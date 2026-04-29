<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Gateway\Regolo;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Files\HasName;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\RerankingResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Responses\TranscriptionResponse;

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
 * read from the {@see Provider} argument on each
 * call via `providerCredentials()['key']` and
 * `additionalConfiguration()['url']`. This matches the upstream
 * MistralGateway / OllamaGateway / OpenRouterGateway pattern and lets
 * the gateway pick up environment / config rotation without
 * re-instantiation.
 */
final class RegoloGateway implements AudioGateway, EmbeddingGateway, ImageGateway, RerankingGateway, TextGateway, TranscriptionGateway
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

    /**
     * Default HTTP timeout (seconds) applied to `generateImage` when
     * the caller passes `null` for `$timeout`. Image rendering is
     * meaningfully slower than text generation on Regolo's catalogue
     * (Qwen-Image takes 8–25s on a typical prompt), so the gateway
     * raises the timeout above the 60s text default. Exposed as a
     * `public const` so the unit suite can assert that
     * `generateImage` actually applies this value (testing the timeout
     * any other way would require mocking a `final` class).
     */
    public const IMAGE_DEFAULT_TIMEOUT_SECONDS = 120;

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

    /**
     * Generate an image via Regolo's `POST /v1/images/generations` endpoint.
     *
     * Regolo exposes an OpenAI-compatible image generation surface; the
     * default catalogue model is `Qwen-Image`. The response shape mirrors
     * OpenAI: `{ data: [{ b64_json: '...' }, ...] }`. Image-edit (i.e.
     * generation conditioned on `attachments`) is not part of the
     * documented Regolo API at the time of writing — passing
     * `$attachments` therefore raises a `RuntimeException` rather than
     * silently dropping them; consumers can detect the limitation and
     * either skip the call or pre-process the attachments themselves.
     *
     * @param  array<int, mixed>  $attachments
     * @param  '3:2'|'2:3'|'1:1'|null  $size
     * @param  'low'|'medium'|'high'|null  $quality
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
    ): ImageResponse {
        if (! empty($attachments)) {
            throw new \RuntimeException(
                'Regolo does not currently expose an image-edit endpoint; '.
                'POST /v1/images/generations is text-prompt-only. '.
                'Skip the attachments argument or open a feature request at '.
                'https://github.com/padosoft/laravel-ai-regolo/issues.'
            );
        }

        $body = [
            'model' => $model,
            'prompt' => $prompt,
            ...$provider->defaultImageOptions($size, $quality),
        ];

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $this->imageEffectiveTimeout($timeout, $provider))->post('images/generations', $body),
        );

        $data = $response->json();

        return new ImageResponse(
            (new Collection($data['data'] ?? []))->map(fn (array $image) => new GeneratedImage(
                $image['b64_json'] ?? '',
                'image/png',
            )),
            new Usage(0, 0),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Generate audio (text-to-speech) via Regolo's `POST /v1/audio/speech`
     * endpoint.
     *
     * The endpoint mirrors OpenAI's `audio/speech` shape so the same body
     * applies (`model`, `input`, `voice`, `response_format`, `speed`,
     * `instructions`). Regolo's TTS model catalogue is **not** part of
     * the public `GET /v1/models` listing yet — the model name has to
     * come from the Seeweb team directly (commercial / early-access
     * channel). The gateway does not pin a default and
     * `RegoloProvider::defaultAudioModel()` returns `''` when the
     * config block is empty so the upstream `model required` 4xx is
     * the clear failure mode rather than a hard-coded guess.
     *
     * The two SDK pseudo-voices (`default-male`, `default-female`) are
     * forwarded verbatim — Regolo accepts custom voice ids and the SDK
     * treats them as opaque strings.
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
    ): AudioResponse {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('audio/speech', array_filter([
                'model' => $model,
                'input' => $text,
                'voice' => $voice,
                'response_format' => 'mp3',
                'speed' => 1.0,
                'instructions' => $instructions,
            ], fn ($v) => $v !== null)),
        );

        return new AudioResponse(
            base64_encode($response->body()),
            new Meta($provider->name(), $model),
            'audio/mpeg',
        );
    }

    /**
     * Transcribe audio via Regolo's `POST /v1/audio/transcriptions`
     * endpoint.
     *
     * The default Regolo STT model is `faster-whisper-large-v3` — a Whisper
     * derivative that returns the canonical OpenAI Whisper JSON shape
     * (`{ text, segments?: [...] }`). The Regolo docs note that audio
     * chunks should stay under ~2 minutes for best latency; this gateway
     * does not enforce that limit (consumers chunk if they need to).
     *
     * Diarization (`$diarize = true`) is forwarded as
     * `response_format=diarized_json`; not every Regolo Whisper variant
     * supports it — the upstream returns a plain `text` field if not, and
     * the response shape stays compatible.
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30,
    ): TranscriptionResponse {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->attach('file', $audio->content(), $this->audioFilename($audio), ['Content-Type' => $audio->mimeType()])
                ->post('audio/transcriptions', array_filter([
                    'model' => $model,
                    'language' => $language,
                    'response_format' => $diarize ? 'diarized_json' : 'json',
                ], fn ($v) => $v !== null)),
        );

        $data = $response->json();

        // Whisper-style usage: `input_tokens` is the audio-in cost,
        // `total_tokens` is the billed total. The SDK's Usage DTO
        // splits prompt vs completion, so map `input_tokens` →
        // `promptTokens` and the *delta* (total − input, floored at 0)
        // → `completionTokens`. Sending `total_tokens` raw into
        // `completionTokens` would double-count when consumers add
        // `promptTokens + completionTokens` to derive a billed-total
        // figure.
        $inputTokens = (int) ($data['usage']['input_tokens'] ?? 0);
        $totalTokens = (int) ($data['usage']['total_tokens'] ?? 0);
        $completionTokens = max($totalTokens - $inputTokens, 0);

        return new TranscriptionResponse(
            $data['text'] ?? '',
            (new Collection($data['segments'] ?? []))->map(fn (array $segment) => new TranscriptionSegment(
                $segment['text'] ?? '',
                $segment['speaker'] ?? '',
                $segment['start'] ?? 0,
                $segment['end'] ?? 0,
            )),
            new Usage($inputTokens, $completionTokens),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Pick a filename for the audio upload based on the audio's MIME
     * type. Regolo's `/v1/audio/transcriptions` endpoint uses the
     * filename's extension as a hint when the `Content-Type` header is
     * generic (e.g. `application/octet-stream`); a wrong extension here
     * surfaces as an upstream 415 / 422 rather than a useful local
     * error.
     */
    protected function audioFilename(TranscribableAudio $audio): string
    {
        if ($audio instanceof HasName) {
            $name = $audio->name();

            // HasName::name() is `?string` — both null (never set) and
            // '' (explicitly cleared via `->as('')`) fall through to
            // the MIME-type-driven fallback so the upstream always
            // gets a non-empty filename it can dispatch on.
            if ($name !== null && $name !== '') {
                return $name;
            }
        }

        $extension = match ($audio->mimeType()) {
            'audio/webm' => 'webm',
            'audio/ogg', 'audio/ogg; codecs=opus' => 'ogg',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/flac', 'audio/x-flac' => 'flac',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mpga' => 'mpga',
            default => 'mp3',
        };

        return "audio.{$extension}";
    }

    /**
     * Resolve the effective timeout for `generateImage` honoring the
     * full precedence chain:
     *
     *  1. per-call `$timeout` argument (caller wins);
     *  2. provider-level `additionalConfiguration()['timeout']` if the
     *     consuming app has set `providers.regolo.timeout` /
     *     `REGOLO_TIMEOUT` to a valid positive integer;
     *  3. `IMAGE_DEFAULT_TIMEOUT_SECONDS` (120 s) — only used when
     *     **nothing** has been configured upstream.
     *
     * The image gateway used to short-circuit straight to step 3 the
     * moment `$timeout` was `null`, which silently bypassed an
     * explicit `REGOLO_TIMEOUT=240` from the Laravel app and caused
     * inconsistency with `generateText` / `generateEmbeddings` that
     * both honour the provider config. This helper restores the
     * canonical precedence; callers who genuinely want the package
     * default just leave the provider config unset.
     */
    private function imageEffectiveTimeout(?int $timeout, ImageProvider $provider): int
    {
        if ($timeout !== null) {
            return $timeout;
        }

        $providerConfigured = $provider->additionalConfiguration()['timeout'] ?? null;
        if (is_numeric($providerConfigured) && (int) $providerConfigured >= 1) {
            return (int) $providerConfigured;
        }

        return self::IMAGE_DEFAULT_TIMEOUT_SECONDS;
    }
}
