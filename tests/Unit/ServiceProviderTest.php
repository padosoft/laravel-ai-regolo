<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Unit;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelAiRegolo\LaravelAiRegoloServiceProvider;

/**
 * Smoke test — verifies the service provider boots inside a Testbench
 * Laravel application. Real driver tests land in W2.A.2-4 (Regolo +
 * Ollama drivers via Http::fake()).
 *
 * Until those tests arrive, this file keeps CI green AND documents the
 * Testbench bootstrap pattern that future tests should follow.
 */
final class ServiceProviderTest extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LaravelAiRegoloServiceProvider::class];
    }

    public function test_service_provider_boots_without_errors(): void
    {
        $providers = $this->app->getLoadedProviders();

        $this->assertArrayHasKey(LaravelAiRegoloServiceProvider::class, $providers);
        $this->assertTrue($providers[LaravelAiRegoloServiceProvider::class]);
    }
}
