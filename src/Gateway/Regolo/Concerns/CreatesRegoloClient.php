<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Gateway\Regolo\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Provider;

/**
 * HTTP client factory for the Regolo REST API.
 *
 * Mirrors the upstream `Laravel\Ai\Gateway\Mistral\Concerns\CreatesMistralClient`
 * pattern — credentials and base URL are read from the Provider on each
 * call rather than held as gateway state. This lets the gateway be
 * resolved once per Provider lifetime while still picking up
 * environment / config changes (test overrides, multi-tenant rotation,
 * key rotation) on the very next request.
 *
 * The base URL defaults to Regolo's production endpoint
 * (`https://api.regolo.ai/v1`) and is overridable via the provider's
 * `additionalConfiguration()['url']` — set in `config/ai.php`'s
 * `providers.regolo.url` entry.
 *
 * Timeout precedence (highest to lowest):
 *   1. `$timeout` argument passed by the gateway method (e.g.
 *      `generateText(..., timeout: 30)`)
 *   2. `additionalConfiguration()['timeout']` from `config/ai.php`'s
 *      `providers.regolo.timeout` entry
 *   3. 60-second hard default
 *
 * Step 2 is what makes `RegoloGateway::rerank()` honour the provider's
 * configured timeout — `rerank()` does not accept a per-call timeout
 * parameter in its SDK signature, so it always reaches this method
 * with `$timeout = null` and falls through to the provider config.
 */
trait CreatesRegoloClient
{
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return Http::baseUrl($this->baseUrl($provider))
            ->withToken($provider->providerCredentials()['key'])
            ->timeout($timeout ?? $this->providerTimeout($provider))
            ->throw();
    }

    protected function baseUrl(Provider $provider): string
    {
        return rtrim(
            $provider->additionalConfiguration()['url'] ?? 'https://api.regolo.ai/v1',
            '/',
        );
    }

    /**
     * Read the provider-level default timeout (seconds) from
     * `config/ai.php`'s `providers.regolo.timeout`. Falls back to 60s
     * when the entry is missing.
     */
    protected function providerTimeout(Provider $provider): int
    {
        $configured = $provider->additionalConfiguration()['timeout'] ?? null;

        if ($configured === null || $configured === '') {
            return 60;
        }

        return (int) $configured;
    }
}
