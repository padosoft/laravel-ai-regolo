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
     * Read an env var via getenv() OR $_ENV — Testbench / phpunit
     * environments do not always populate one when the developer set
     * the other.
     */
    protected function envValue(string $name): ?string
    {
        $value = getenv($name);

        if ($value !== false && $value !== '') {
            return $value;
        }

        return $_ENV[$name] ?? null;
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
     * value through explicitly. `RegoloGateway::rerank()` does not
     * accept a per-call timeout in its SDK signature; it picks the
     * value up from the provider's HTTP-client config, which is
     * already set via `liveProvider()`'s `'timeout' => ...` entry.
     * The env var therefore controls every live request, just via
     * two paths.
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
