<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\Concerns\GeneratesEmbeddings;
use Laravel\Ai\Providers\Concerns\GeneratesText;
use Laravel\Ai\Providers\Concerns\HasEmbeddingGateway;
use Laravel\Ai\Providers\Concerns\HasRerankingGateway;
use Laravel\Ai\Providers\Concerns\HasTextGateway;
use Laravel\Ai\Providers\Concerns\Reranks;
use Laravel\Ai\Providers\Concerns\StreamsText;
use Laravel\Ai\Providers\Provider;
use Padosoft\LaravelAiRegolo\Gateway\Regolo\RegoloGateway;

/**
 * Regolo (Seeweb) provider for the official `laravel/ai` SDK.
 *
 * Regolo's REST surface is OpenAI-compatible at
 * `https://api.regolo.ai/v1` for chat completions, embeddings, and
 * reranking, served from the Italian sovereign cloud (GDPR + AI-Act
 * friendly hosting). The provider exposes:
 *
 *  - Text generation (chat + streaming) via `TextProvider`
 *  - Embeddings via `EmbeddingProvider`
 *  - Reranking via `RerankingProvider`
 *
 * Heavy lifting is delegated to {@see RegoloGateway} (HTTP transport)
 * and the SDK's standard concern traits (request/response shaping,
 * provider event emission, retry semantics).
 *
 * Default models match the upstream Regolo Python SDK so PHP users
 * get identical out-of-the-box behaviour:
 *
 *  - default text:       Llama-3.1-8B-Instruct
 *  - default embeddings: Qwen3-Embedding-8B
 *  - default reranking:  jina-reranker-v2
 */
final class RegoloProvider extends Provider implements EmbeddingProvider, RerankingProvider, TextProvider
{
    use GeneratesEmbeddings;
    use GeneratesText;
    use HasEmbeddingGateway;
    use HasRerankingGateway;
    use HasTextGateway;
    use Reranks;
    use StreamsText;

    protected ?RegoloGateway $regoloGateway = null;

    public function __construct(protected array $config, protected Dispatcher $events)
    {
        // The abstract Provider constructor expects a Gateway instance,
        // but the SDK pattern (used by MistralProvider, OllamaProvider,
        // OpenRouterProvider, ...) is to resolve the gateway lazily via
        // a typed accessor below. The SDK never calls into the parent
        // constructor's $gateway property directly — every capability
        // concern routes through the textGateway() / embeddingGateway()
        // / rerankingGateway() accessors below.
    }

    public function providerCredentials(): array
    {
        return [
            'key' => $this->config['key'] ?? '',
        ];
    }

    protected function regoloGateway(): RegoloGateway
    {
        // Mirrors the upstream MistralProvider / OllamaProvider pattern:
        // the gateway is stateless w.r.t. credentials and base URL — it
        // reads both from the Provider passed to each gateway method via
        // $provider->providerCredentials() and
        // $provider->additionalConfiguration()['url']. Only the events
        // dispatcher is gateway state.
        return $this->regoloGateway ??= new RegoloGateway($this->events);
    }

    public function textGateway(): TextGateway
    {
        return $this->textGateway ??= $this->regoloGateway();
    }

    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= $this->regoloGateway();
    }

    public function rerankingGateway(): RerankingGateway
    {
        return $this->rerankingGateway ??= $this->regoloGateway();
    }

    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'Llama-3.1-8B-Instruct';
    }

    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'Llama-3.1-8B-Instruct';
    }

    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'Llama-3.3-70B-Instruct';
    }

    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'Qwen3-Embedding-8B';
    }

    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 4096;
    }

    public function defaultRerankingModel(): string
    {
        return $this->config['models']['reranking']['default'] ?? 'jina-reranker-v2';
    }
}
