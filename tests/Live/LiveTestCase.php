<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Live;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelAiRegolo\Gateway\Regolo\RegoloGateway;
use Padosoft\LaravelAiRegolo\LaravelAiRegoloServiceProvider;
use Padosoft\LaravelAiRegolo\Providers\RegoloProvider;

/**
 * Base class for tests that hit the **real** Regolo API at
 * `https://api.regolo.ai/v1`.
 *
 * These tests are intentionally separate from the default offline
 * `Unit` suite (which uses `Http::fake()` for everything). They are
 * **never** invoked in CI — running them costs real tokens against
 * a real API key, and the matrix has no API key to spend.
 *
 * The suite self-skips when `REGOLO_API_KEY` is not set, so a fresh
 * `git clone` + `vendor/bin/phpunit` invocation never accidentally
 * burns money or fails on missing credentials.
 *
 * ## How to run
 *
 *   export REGOLO_API_KEY=rg_live_...
 *   vendor/bin/phpunit --testsuite Live
 *
 * ## Optional overrides
 *
 *   REGOLO_BASE_URL                       default: https://api.regolo.ai/v1
 *   REGOLO_LIVE_TEXT_MODEL                default: Llama-3.1-8B-Instruct
 *   REGOLO_LIVE_EMBEDDINGS_MODEL          default: Qwen3-Embedding-8B
 *   REGOLO_LIVE_RERANKING_MODEL           default: Qwen3-Reranker-4B
 *   REGOLO_LIVE_IMAGE_MODEL               default: Qwen-Image
 *   REGOLO_LIVE_TRANSCRIPTION_MODEL       default: faster-whisper-large-v3
 *   REGOLO_LIVE_TIMEOUT                   default: 60
 *
 * Multimodal — the corresponding live test self-skips when these
 * are unset:
 *
 *   REGOLO_LIVE_AUDIO_MODEL               TTS model id from Seeweb
 *                                         (catalogue not on /v1/models)
 *   REGOLO_LIVE_TRANSCRIPTION_AUDIO_PATH  path to a real speech file
 *
 * Multimodal — optional overrides (defaults apply when unset):
 *
 *   REGOLO_LIVE_AUDIO_VOICE               default: alloy
 *   REGOLO_LIVE_TRANSCRIPTION_LANGUAGE    optional ISO 639-1 hint
 *                                         (omit to let Whisper auto-detect)
 *
 * Embedding vector dimension is **model-defined** — the live tests do
 * not attempt to override it. `RegoloGateway::generateEmbeddings()`
 * posts `{ model, input }` to `/v1/embeddings`; the SDK's `int
 * $dimensions` argument is part of the upstream contract but is not
 * threaded through to the wire request, and no env-var override is
 * exposed for it.
 */
abstract class LiveTestCase extends TestCase
{
    protected function setUp(): void
    {
        $apiKey = $this->envValue('REGOLO_API_KEY');

        if ($apiKey === null || $apiKey === '') {
            $this->markTestSkipped(
                'Live Regolo tests require the REGOLO_API_KEY environment variable. '.
                'See README "Running the live test suite".'
            );
        }

        parent::setUp();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            LaravelAiRegoloServiceProvider::class,
        ];
    }

    /**
     * Read an env var via every channel PHPUnit/Testbench may have
     * populated: `getenv()`, `$_ENV[]`, `$_SERVER[]`. PHPUnit's
     * `<server>` and `<env>` blocks in phpunit.xml inject into
     * different superglobals depending on the host PHP config,
     * and CI runners (GitHub Actions, GitLab CI, etc.) sometimes
     * surface variables in only one of the three. Empty strings are
     * treated as unset so a stray `export FOO=""` in a CI step does
     * not fool the suite into thinking the key is configured.
     */
    protected function envValue(string $name): ?string
    {
        foreach ([getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $candidate) {
            if ($candidate === false || $candidate === null || $candidate === '') {
                continue;
            }

            return (string) $candidate;
        }

        return null;
    }

    /**
     * Build a real RegoloProvider that points at the live API.
     */
    protected function liveProvider(): RegoloProvider
    {
        return new RegoloProvider(
            config: [
                'driver' => 'regolo',
                'name' => 'regolo',
                'key' => $this->envValue('REGOLO_API_KEY'),
                'url' => $this->envValue('REGOLO_BASE_URL') ?? 'https://api.regolo.ai/v1',
                'timeout' => $this->liveTimeout(),
            ],
            events: $this->app->make(Dispatcher::class),
        );
    }

    protected function liveGateway(): RegoloGateway
    {
        return new RegoloGateway($this->app->make(Dispatcher::class));
    }

    /**
     * Timeout (seconds) honoured by the live tests, drawn from
     * `REGOLO_LIVE_TIMEOUT` with a 60s default.
     *
     * Three of the four SDK gateway methods accept a per-call timeout
     * argument — `generateText`, `streamText`, `generateEmbeddings` —
     * and the chat / streaming / embeddings live tests pass this
     * value through explicitly.
     *
     * `RegoloGateway::rerank()` does not accept a per-call timeout
     * in its SDK signature, so it reaches the HTTP client builder
     * with `$timeout = null`. The builder (`CreatesRegoloClient::client`)
     * therefore falls through to the provider's
     * `additionalConfiguration()['timeout']` entry — which
     * `liveProvider()` sets to `$this->liveTimeout()` — before
     * landing on the 60-second hard default. The env var controls
     * every live request, just via two paths (per-call argument for
     * three methods; provider config + builder fallback for rerank).
     */
    protected function liveTimeout(): int
    {
        $configured = $this->envValue('REGOLO_LIVE_TIMEOUT');

        if ($configured === null || ! is_numeric($configured)) {
            return 60;
        }

        $timeout = (int) $configured;

        // Reject 0 / negative values: the underlying Guzzle client
        // treats Http::timeout(0) as "no timeout at all", which is a
        // footgun for live tests that should always fail fast.
        return $timeout >= 1 ? $timeout : 60;
    }

    protected function textModel(): string
    {
        return $this->envValue('REGOLO_LIVE_TEXT_MODEL') ?? 'Llama-3.1-8B-Instruct';
    }

    protected function embeddingsModel(): string
    {
        return $this->envValue('REGOLO_LIVE_EMBEDDINGS_MODEL') ?? 'Qwen3-Embedding-8B';
    }

    /**
     * Placeholder dimension passed to `generateEmbeddings()` to
     * satisfy the SDK signature (`int $dimensions`).
     *
     * `RegoloGateway::generateEmbeddings()` does NOT thread this value
     * through to the wire request — the body posted to `/v1/embeddings`
     * is `{ model, input }` only. The vector dimension that comes back
     * is therefore entirely model-defined, and the live tests assert
     * that every vector in a single response has the same length
     * rather than that the length matches a configured override.
     *
     * The hard-coded 4096 matches Qwen3-Embedding-8B (the default
     * `REGOLO_LIVE_EMBEDDINGS_MODEL`) but carries no semantic weight.
     */
    protected function embeddingsDimensionsPlaceholder(): int
    {
        return 4096;
    }

    protected function rerankingModel(): string
    {
        return $this->envValue('REGOLO_LIVE_RERANKING_MODEL') ?? 'Qwen3-Reranker-4B';
    }

    protected function imageModel(): string
    {
        return $this->envValue('REGOLO_LIVE_IMAGE_MODEL') ?? 'Qwen-Image';
    }

    protected function transcriptionModel(): string
    {
        return $this->envValue('REGOLO_LIVE_TRANSCRIPTION_MODEL') ?? 'faster-whisper-large-v3';
    }

    /**
     * TTS model for the live audio test.
     *
     * Regolo's TTS catalogue is not on `GET /v1/models` yet — Seeweb
     * provides the model id through their commercial / early-access
     * channel rather than the public listing. The live audio test
     * therefore self-skips when this env var is unset, matching the
     * package-level pattern (`RegoloProvider::defaultAudioModel()`
     * returns `''`). Once Seeweb publishes the catalogue, the env
     * var becomes optional and a sensible default can be wired here.
     */
    protected function audioModelOrSkip(): string
    {
        $configured = $this->envValue('REGOLO_LIVE_AUDIO_MODEL');

        if ($configured === null || $configured === '') {
            $this->markTestSkipped(
                'Live TTS test requires REGOLO_LIVE_AUDIO_MODEL — Regolo\'s TTS '.
                'catalogue is not on /v1/models yet, the model id has to come '.
                'from Seeweb directly. Set the env var to the model name they '.
                'gave you to enable this scenario.'
            );
        }

        return $configured;
    }

    /**
     * Voice id for the TTS test, defaulting to OpenAI's `alloy` because
     * Regolo's Audio gateway forwards the voice string verbatim and
     * accepts the OpenAI-canonical names. The env var override exists
     * for users whose Seeweb-provided model ships a different voice
     * catalogue.
     */
    protected function audioVoice(): string
    {
        return $this->envValue('REGOLO_LIVE_AUDIO_VOICE') ?? 'alloy';
    }
}
