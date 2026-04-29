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
     * @param  'low'|'medium'|'high'|\BackedEnum|\Stringable|int|float|null  $quality
     *                                                                                 Widened from the parent interface's `?string` to `mixed`
     *                                                                                 (LSP-safe contravariant) so backed enums, Stringable value
     *                                                                                 objects, and int/float primitives reach
     *                                                                                 `RegoloProvider::defaultImageOptions()` for normalisation.
     *                                                                                 Booleans / arrays / resources / plain objects fall through
     *                                                                                 to silent-drop inside `normaliseImageQuality()`.
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        $quality = null,
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
                $this->detectImageMime($image['b64_json'] ?? ''),
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

        // Both `audio/...` and `video/...` containers map to a real
        // extension because MP4 and WebM containers commonly carry an
        // audio-only stream — `finfo_file()` returns the container
        // type even when the payload is pure audio, and Whisper-style
        // STT endpoints accept the underlying audio regardless of
        // how the container labels itself. The previous mapping
        // silently fell back to `audio.mp3` for `video/mp4` /
        // `video/webm`, which then triggered upstream 415 / 422
        // because the bytes (mp4) and the filename (.mp3) disagreed.
        //
        // `audio/m4a` and `audio/mp4` deliberately map to DIFFERENT
        // extensions: Whisper-style endpoints accept both `m4a` and
        // `mp4` as distinct file types, and a `.m4a` payload labelled
        // `audio.mp4` (or vice versa) can trip a strict upstream
        // dispatcher.
        $extension = match ($audio->mimeType()) {
            'audio/webm', 'video/webm' => 'webm',
            'audio/ogg', 'audio/ogg; codecs=opus' => 'ogg',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/mp4', 'video/mp4' => 'mp4',
            'audio/flac', 'audio/x-flac' => 'flac',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mpga' => 'mpga',
            default => 'mp3',
        };

        return "audio.{$extension}";
    }

    /**
     * Inspect the first few bytes of a base64-encoded image payload to
     * label the response with the actual format. Regolo's `Qwen-Image`
     * empirically returns JPEG today — using the JFIF APP0 marker
     * (`\xFF\xD8\xFF\xE0`) — even though the SDK's OpenAI-compatible
     * response envelope has no `mime` field; without sniffing the
     * bytes we'd hand `image/png` downstream and the `<img>` tag
     * emitter / file-store helpers would write `.png` files
     * containing JPEG bytes. The sniffer accepts ANY JPEG variant,
     * not just JFIF — the JPEG check below matches the 3-byte
     * `\xFF\xD8\xFF` SOI prefix that's common to every APP marker
     * family (JFIF/E0, EXIF/E1, ICC/E2, ...), so a future Regolo
     * release that swaps the marker family still resolves correctly.
     *
     * Recognised signatures (only the first ~12 raw bytes need
     * decoding — see the prefix-only optimisation in the body):
     *   - PNG:  `\x89PNG\r\n\x1a\n`        (8 bytes, canonical)
     *   - JPEG: `\xFF\xD8\xFF` + any APPn   (3 bytes; matches JFIF /
     *                                       EXIF / Adobe / etc.)
     *   - WebP: `RIFF` + 4 size bytes + `WEBP` (12 bytes total — the
     *                                       `WEBP` marker at offset
     *                                       8 disambiguates the
     *                                       container from WAV/AVI)
     *   - GIF:  `GIF87a` / `GIF89a`        (6 bytes)
     *
     * Falls back to `image/png` for unrecognised payloads — that
     * matches the long-standing OpenAI default and keeps existing
     * unit-test fixtures (which pass deliberate non-image payloads
     * like `'fake-png-1'`) green.
     */
    private function detectImageMime(string $base64): string
    {
        if ($base64 === '') {
            return 'image/png';
        }

        // Decode only a short base64 prefix instead of the entire
        // payload — Qwen-Image renders are routinely several MB and
        // the sniffer never needs more than the first 12 raw bytes
        // (the longest signature segment is WebP's
        // `RIFF....WEBP` ending at offset 11). 16 base64 characters
        // decode to exactly 12 raw bytes under strict mode (4 chars
        // → 3 bytes); take 24 chars to absorb future signature
        // additions without re-tuning the constant. Copilot review
        // on PR #11 round-3 flagged the prior "decode the whole
        // payload" branch as avoidable CPU+RAM overhead.
        //
        // strict: true so a non-base64 payload (e.g. the unit-test
        // fixtures `'fake-png-1'`) decodes to `false` and reliably
        // hits the `image/png` fallback below. With strict: false,
        // PHP would best-effort-decode the garbage into arbitrary
        // bytes that could accidentally match one of the magic
        // prefixes downstream and surface a wrong MIME — Copilot
        // also caught this in round-1.
        $bytes = base64_decode(substr($base64, 0, 24), strict: true);
        if ($bytes === false || strlen($bytes) < 8) {
            return 'image/png';
        }

        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }

        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return 'image/gif';
        }

        return 'image/png';
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
