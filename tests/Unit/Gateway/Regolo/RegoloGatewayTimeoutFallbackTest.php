<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Unit\Gateway\Regolo;

use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelAiRegolo\Gateway\Regolo\RegoloGateway;
use Padosoft\LaravelAiRegolo\LaravelAiRegoloServiceProvider;
use Padosoft\LaravelAiRegolo\Providers\RegoloProvider;
use ReflectionMethod;

/**
 * Pins the 3-tier timeout precedence baked into
 * `CreatesRegoloClient::client()`:
 *
 *   1. per-call `$timeout` argument (used by chat / stream / embed)
 *   2. provider's `additionalConfiguration()['timeout']` (used by
 *      `rerank()` since its SDK signature has no per-call timeout)
 *   3. 60-second hard default
 *
 * `RegoloGateway` is `final`, so the protected `providerTimeout()`
 * helper is reached through `ReflectionMethod` rather than a test
 * subclass. The full HTTP-level path is already covered by the
 * happy-path / 4xx / 5xx tests in the chat / embeddings / rerank
 * test files; this file's job is to lock down the *value chosen*
 * by the fallback chain so a regression in `providerTimeout()`
 * cannot silently let `Http::timeout(0)` (= no timeout) reach a
 * production rerank request.
 */
final class RegoloGatewayTimeoutFallbackTest extends TestCase
{
    public function test_provider_timeout_returns_configured_value_when_numeric_and_positive(): void
    {
        $this->assertSame(42, $this->resolveTimeout(['timeout' => 42]));
    }

    public function test_provider_timeout_accepts_numeric_string(): void
    {
        $this->assertSame(45, $this->resolveTimeout(['timeout' => '45']));
    }

    public function test_provider_timeout_falls_back_to_60s_when_entry_is_missing(): void
    {
        $this->assertSame(60, $this->resolveTimeout([]));
    }

    public function test_provider_timeout_falls_back_to_60s_when_entry_is_null(): void
    {
        $this->assertSame(60, $this->resolveTimeout(['timeout' => null]));
    }

    public function test_provider_timeout_falls_back_to_60s_when_entry_is_empty_string(): void
    {
        $this->assertSame(60, $this->resolveTimeout(['timeout' => '']));
    }

    public function test_provider_timeout_falls_back_to_60s_when_entry_is_non_numeric(): void
    {
        $this->assertSame(60, $this->resolveTimeout(['timeout' => 'forever']));
    }

    public function test_provider_timeout_falls_back_to_60s_when_entry_is_zero(): void
    {
        // Http::timeout(0) means "no timeout at all" on the underlying
        // Guzzle client — the silent footgun this guard exists to block.
        $this->assertSame(60, $this->resolveTimeout(['timeout' => 0]));
    }

    public function test_provider_timeout_falls_back_to_60s_when_entry_is_negative(): void
    {
        $this->assertSame(60, $this->resolveTimeout(['timeout' => -30]));
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
     * Build a `RegoloProvider` with the given config overlay and
     * resolve the gateway's `providerTimeout()` against it.
     *
     * @param  array{timeout?: int|string|null}  $configOverride
     */
    private function resolveTimeout(array $configOverride): int
    {
        $config = array_merge([
            'driver' => 'regolo',
            'name' => 'regolo',
            'key' => 'test-key',
            'url' => 'https://api.regolo.test/v1',
        ], $configOverride);

        $provider = new RegoloProvider($config, $this->app->make('events'));
        $gateway = new RegoloGateway($this->app->make('events'));

        $method = new ReflectionMethod($gateway, 'providerTimeout');
        $method->setAccessible(true);

        return $method->invoke($gateway, $provider);
    }
}
