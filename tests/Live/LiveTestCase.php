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
 *   REGOLO_BASE_URL                  default: https://api.regolo.ai/v1
 *   REGOLO_LIVE_TEXT_MODEL           default: Llama-3.1-8B-Instruct
 *   REGOLO_LIVE_EMBEDDINGS_MODEL     default: Qwen3-Embedding-8B
 *   REGOLO_LIVE_EMBEDDINGS_DIM       default: 4096
 *   REGOLO_LIVE_RERANKING_MODEL      default: jina-reranker-v2
 *   REGOLO_LIVE_TIMEOUT              default: 60
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
     * treated as unset so a stray `EXPORT FOO=""` in a CI step does
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
        return (int) ($this->envValue('REGOLO_LIVE_TIMEOUT') ?? 60);
    }

    protected function textModel(): string
    {
        return $this->envValue('REGOLO_LIVE_TEXT_MODEL') ?? 'Llama-3.1-8B-Instruct';
    }

    protected function embeddingsModel(): string
    {
        return $this->envValue('REGOLO_LIVE_EMBEDDINGS_MODEL') ?? 'Qwen3-Embedding-8B';
    }

    protected function embeddingsDimensions(): int
    {
        return (int) ($this->envValue('REGOLO_LIVE_EMBEDDINGS_DIM') ?? 4096);
    }

    protected function rerankingModel(): string
    {
        return $this->envValue('REGOLO_LIVE_RERANKING_MODEL') ?? 'jina-reranker-v2';
    }
}
