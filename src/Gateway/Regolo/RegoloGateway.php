<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Gateway\Regolo;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\RerankingResponse;
use Laravel\Ai\Responses\TextResponse;

/**
 * HTTP gateway for Regolo's REST API.
 *
 * Regolo (Seeweb) hosts an OpenAI-compatible **classic** Chat
 * Completions surface (`POST /v1/chat/completions`), an OpenAI-classic
 * embeddings endpoint (`POST /v1/embeddings`), and a Cohere/Jina-style
 * reranking endpoint (`POST /v1/rerank`). One gateway implements all
 * three SDK gateway contracts so a single provider binding wires every
 * capability.
 *
 * The gateway is stateless w.r.t. credentials and base URL — both are
 * read from the {@see \Laravel\Ai\Providers\Provider} argument on each
 * call via `providerCredentials()['key']` and
 * `additionalConfiguration()['url']`. This matches the upstream
 * MistralGateway / OllamaGateway / OpenRouterGateway pattern and lets
 * the gateway pick up environment / config rotation without
 * re-instantiation.
 */
final class RegoloGateway implements EmbeddingGateway, RerankingGateway, TextGateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\CreatesRegoloClient;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsAttachments;
    use Concerns\MapsMessages;
    use Concerns\MapsTools;
    use Concerns\ParsesTextResponses;
    use HandlesFailoverErrors;
    use InvokesTools;
    use ParsesServerSentEvents;

    public function __construct(protected Dispatcher $events)
    {
        $this->initializeToolCallbacks();
    }

    /**
     * {@inheritdoc}
     */
    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        $body = $this->buildTextRequestBody(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
        );

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('chat/completions', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->parseTextResponse(
            $data,
            $provider,
            filled($schema),
            $tools,
            $schema,
            $options,
            $instructions,
            $messages,
            $timeout,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): Generator {
        $body = $this->buildTextRequestBody(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
        );

        $body['stream'] = true;
        $body['stream_options'] = ['include_usage' => true];

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post('chat/completions', $body),
        );

        yield from $this->processTextStream(
            $invocationId,
            $provider,
            $model,
            $tools,
            $schema,
            $options,
            $response->getBody(),
            $instructions,
            $messages,
            timeout: $timeout,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
    ): EmbeddingsResponse {
        if (empty($inputs)) {
            return new EmbeddingsResponse(
                [],
                0,
                new Meta($provider->name(), $model),
            );
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('embeddings', [
                'model' => $model,
                'input' => $inputs,
            ]),
        );

        $data = $response->json();

        return new EmbeddingsResponse(
            collect($data['data'] ?? [])->pluck('embedding')->all(),
            $data['usage']['total_tokens'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * {@inheritdoc}
     *
     * Regolo's reranking endpoint follows the Cohere/Jina shape:
     *  - request body: `{ model, query, documents: string[], top_n? }`
     *  - response: `{ results: [{ index, relevance_score }, ...] }`
     *    where `index` is the position in the original `documents` array
     *
     * The `documents` parameter is preserved by index so that
     * `RankedDocument::$document` carries the original string back to
     * the caller without a second lookup.
     */
    public function rerank(
        RerankingProvider $provider,
        string $model,
        array $documents,
        string $query,
        ?int $limit = null
    ): RerankingResponse {
        if (empty($documents)) {
            return new RerankingResponse(
                [],
                new Meta($provider->name(), $model),
            );
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->post('rerank', array_filter([
                'model' => $model,
                'query' => $query,
                'documents' => $documents,
                'top_n' => $limit,
            ], fn ($v) => $v !== null)),
        );

        $data = $response->json();

        $results = (new Collection($data['results'] ?? []))->map(fn (array $result) => new RankedDocument(
            index: $result['index'],
            document: $documents[$result['index']] ?? '',
            score: (float) ($result['relevance_score'] ?? 0),
        ))->all();

        return new RerankingResponse(
            $results,
            new Meta($provider->name(), $model),
        );
    }
}
