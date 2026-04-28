<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Unit;

use Illuminate\Foundation\Application;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelAiRegolo\Gateway\Regolo\RegoloGateway;
use Padosoft\LaravelAiRegolo\LaravelAiRegoloServiceProvider;
use Padosoft\LaravelAiRegolo\Providers\RegoloProvider;

/**
 * Verifies the package wiring: service provider boots, the
 * `ai.provider.regolo` container binding resolves a configured
 * `RegoloProvider`, and the gateway exposes all three SDK capability
 * interfaces (text + embeddings + reranking) without trait conflicts.
 *
 * R23 — pluggable pipeline-style: the binding name is part of our
 * contract with the upstream `laravel/ai` SDK, so a regression that
 * silently renames it would break every consumer's resolve path. This
 * test pins both the binding key and the resolved class.
 */
final class ServiceProviderTest extends TestCase
{
    public function test_service_provider_boots_without_errors(): void
    {
        $providers = $this->app->getLoadedProviders();

        $this->assertArrayHasKey(LaravelAiRegoloServiceProvider::class, $providers);
        $this->assertTrue($providers[LaravelAiRegoloServiceProvider::class]);
    }

    public function test_ai_provider_regolo_binding_resolves_to_regolo_provider(): void
    {
        $resolved = $this->app->make('ai.provider.regolo');

        $this->assertInstanceOf(RegoloProvider::class, $resolved);
    }

    public function test_regolo_provider_implements_three_capability_interfaces(): void
    {
        $provider = $this->app->make('ai.provider.regolo');

        $this->assertInstanceOf(TextProvider::class, $provider);
        $this->assertInstanceOf(EmbeddingProvider::class, $provider);
        $this->assertInstanceOf(RerankingProvider::class, $provider);
    }

    public function test_regolo_provider_exposes_unified_gateway_for_three_capabilities(): void
    {
        /** @var RegoloProvider $provider */
        $provider = $this->app->make('ai.provider.regolo');

        $textGateway = $provider->textGateway();
        $embeddingGateway = $provider->embeddingGateway();
        $rerankingGateway = $provider->rerankingGateway();

        $this->assertInstanceOf(TextGateway::class, $textGateway);
        $this->assertInstanceOf(EmbeddingGateway::class, $embeddingGateway);
        $this->assertInstanceOf(RerankingGateway::class, $rerankingGateway);
        $this->assertInstanceOf(RegoloGateway::class, $textGateway);

        // R23 mutex check — a single gateway instance backs all three
        // capabilities; rebinding one capability does not silently
        // shadow the others.
        $this->assertSame($textGateway, $embeddingGateway);
        $this->assertSame($textGateway, $rerankingGateway);
    }

    public function test_regolo_provider_credentials_flow_through_to_provider_credentials(): void
    {
        /** @var RegoloProvider $provider */
        $provider = $this->app->make('ai.provider.regolo');

        $this->assertSame('test-api-key', $provider->providerCredentials()['key']);
    }

    public function test_regolo_provider_additional_configuration_includes_base_url(): void
    {
        /** @var RegoloProvider $provider */
        $provider = $this->app->make('ai.provider.regolo');

        $this->assertSame(
            'https://api.regolo.test/v1',
            $provider->additionalConfiguration()['url'],
        );
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LaravelAiRegoloServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai.providers.regolo', [
            'driver' => 'regolo',
            'name' => 'regolo',
            'key' => 'test-api-key',
            'url' => 'https://api.regolo.test/v1',
            'timeout' => 60,
        ]);
    }
}
