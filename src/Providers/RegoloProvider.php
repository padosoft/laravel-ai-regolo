<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Providers;

use Illuminate\Contracts\Events\Dispatcher;
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
use Laravel\Ai\Providers\Concerns\GeneratesAudio;
use Laravel\Ai\Providers\Concerns\GeneratesEmbeddings;
use Laravel\Ai\Providers\Concerns\GeneratesImages;
use Laravel\Ai\Providers\Concerns\GeneratesText;
use Laravel\Ai\Providers\Concerns\GeneratesTranscriptions;
use Laravel\Ai\Providers\Concerns\HasAudioGateway;
use Laravel\Ai\Providers\Concerns\HasEmbeddingGateway;
use Laravel\Ai\Providers\Concerns\HasImageGateway;
use Laravel\Ai\Providers\Concerns\HasRerankingGateway;
use Laravel\Ai\Providers\Concerns\HasTextGateway;
use Laravel\Ai\Providers\Concerns\HasTranscriptionGateway;
use Laravel\Ai\Providers\Concerns\Reranks;
use Laravel\Ai\Providers\Concerns\StreamsText;
use Laravel\Ai\Providers\Provider;
use Padosoft\LaravelAiRegolo\Gateway\Regolo\RegoloGateway;

/**
 * Regolo (Seeweb) provider for the official `laravel/ai` SDK.
 *
 * Regolo's REST surface is OpenAI-compatible at
 * `https://api.regolo.ai/v1` for chat completions, embeddings,
 * reranking, image generation, audio (TTS), and audio transcription
 * (STT). Italian sovereign cloud (GDPR + AI-Act friendly hosting).
 *
 * The provider exposes:
 *
 *  - Text generation (chat + streaming)        — `TextProvider`
 *  - Embeddings                                — `EmbeddingProvider`
 *  - Reranking                                 — `RerankingProvider`
 *  - Image generation                          — `ImageProvider`
 *  - Audio (text-to-speech)                    — `AudioProvider`
 *  - Audio transcription (speech-to-text)      — `TranscriptionProvider`
 *
 * Heavy lifting is delegated to {@see RegoloGateway} (HTTP transport)
 * and the SDK's standard concern traits (request/response shaping,
 * provider event emission, retry semantics).
 *
 * Default models match the Regolo public catalogue
 * (`GET https://api.regolo.ai/v1/models`):
 *
 *  - default text:           Llama-3.1-8B-Instruct
 *  - default embeddings:     Qwen3-Embedding-8B
 *  - default reranking:      jina-reranker-v2
 *  - default image:          Qwen-Image
 *  - default transcription:  faster-whisper-large-v3
 *  - default audio (TTS):    NOT pinned — Regolo's TTS catalogue is not
 *                            fully public yet. Pass the model name
 *                            explicitly via `Audio::for(...)->generate('regolo', $model)`
 *                            until Seeweb publishes the catalogue.
 */
final class RegoloProvider extends Provider implements AudioProvider, EmbeddingProvider, ImageProvider, RerankingProvider, TextProvider, TranscriptionProvider
{
    use GeneratesAudio;
    use GeneratesEmbeddings;
    use GeneratesImages;
    use GeneratesText;
    use GeneratesTranscriptions;
    use HasAudioGateway;
    use HasEmbeddingGateway;
    use HasImageGateway;
    use HasRerankingGateway;
    use HasTextGateway;
    use HasTranscriptionGateway;
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
        // concern routes through the per-capability gateway accessors.
    }

    public function providerCredentials(): array
    {
        return [
            'key' => $this->config['key'] ?? '',
        ];
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

    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ??= $this->regoloGateway();
    }

    public function audioGateway(): AudioGateway
    {
        return $this->audioGateway ??= $this->regoloGateway();
    }

    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway ??= $this->regoloGateway();
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

    public function defaultImageModel(): string
    {
        return $this->config['models']['image']['default'] ?? 'Qwen-Image';
    }

    /**
     * @param  '3:2'|'2:3'|'1:1'|null  $size
     * @param  'low'|'medium'|'high'|\BackedEnum|\Stringable|int|float|null  $quality
     *                                                                                 Loosely typed in the SDK interface as `mixed` so it can
     *                                                                                 accept enums / scalars / objects in future SDK versions.
     *                                                                                 The runtime guard below normalises every reasonable value
     *                                                                                 to its canonical string form; only `array` and `resource`
     *                                                                                 fall through to the silent-drop branch because they have
     *                                                                                 no meaningful string projection for this wire endpoint.
     * @return array<string, string>
     */
    public function defaultImageOptions(?string $size = null, $quality = null): array
    {
        // Regolo's image endpoint mirrors OpenAI's request body. We pass
        // through `size` / `quality` only when the caller supplied them
        // — leaving them off lets the upstream model use its own
        // defaults (Qwen-Image accepts the OpenAI canonical sizes).
        $body = [];

        if ($size !== null) {
            $body['size'] = $size;
        }

        // `$quality` is `mixed` in the SDK contract (LSP forces us to
        // keep the wider parent type). Normalise every plausible value
        // to its string projection; arrays, resources, and booleans
        // (both `true` and `false`) have no meaningful representation
        // for this wire field and are silently dropped rather than
        // crashing the request — `'true'`/`'false'` are upstream-422
        // values, see `normaliseImageQuality()` below.
        $normalisedQuality = $this->normaliseImageQuality($quality);
        if ($normalisedQuality !== null) {
            $body['quality'] = $normalisedQuality;
        }

        return $body;
    }

    public function defaultAudioModel(): string
    {
        return $this->config['models']['audio']['default'] ?? '';
    }

    public function defaultTranscriptionModel(): string
    {
        return $this->config['models']['transcription']['default'] ?? 'faster-whisper-large-v3';
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

    /**
     * Coerce a `$quality` value into its canonical wire-string form.
     *
     * The set of accepted shapes mirrors what production Laravel apps
     * routinely pass in: native `'low'|'medium'|'high'` strings, PHP
     * 8.1+ backed enums (e.g. `ImageQuality::High`), `Stringable`
     * value objects, and `int` / `float` primitives a config file
     * might surface (a model that ever exposes a numeric quality knob
     * gets the value verbatim). Anything else — `array`, `resource`,
     * plain `object` without `__toString`, **and `bool`** (both
     * `true` and `false`, because `'true'` / `'false'` are wire-
     * invalid for `/v1/images/generations` and there is no useful
     * boolean→quality mapping) — returns `null` so the wire body
     * just omits the field.
     */
    private function normaliseImageQuality(mixed $quality): ?string
    {
        if ($quality === null) {
            return null;
        }

        if (is_string($quality)) {
            return $quality !== '' ? $quality : null;
        }

        if ($quality instanceof \BackedEnum) {
            return (string) $quality->value;
        }

        if ($quality instanceof \UnitEnum) {
            return $quality->name;
        }

        if (is_object($quality) && method_exists($quality, '__toString')) {
            return (string) $quality;
        }

        if (is_int($quality) || is_float($quality)) {
            return (string) $quality;
        }

        // Booleans deliberately fall through to silent-drop. Sending
        // `'true'` or `'false'` to `/v1/images/generations` is an
        // invalid wire value (the upstream returns 422), and there is
        // no useful boolean→quality mapping a Laravel app could mean.
        // Better to omit the field and let the model use its default.

        return null;
    }
}
