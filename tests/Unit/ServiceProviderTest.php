<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Unit;

use Illuminate\Foundation\Application;
use Laravel\Ai\Ai;
use Laravel\Ai\AiServiceProvider;
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
 * `Ai::instance('regolo')` resolution returns a configured
 * `RegoloProvider` (registered via `AiManager::extend()` — resolved
 * directly from the container in the package service provider; the
 * `Ai` facade does not document `extend()` statically, see PR #6
 * docblock note), and the gateway exposes all three SDK capability
 * interfaces (text + embeddings + reranking) without trait conflicts.
 *
 * R23 — pluggable pipeline-style: the driver name `regolo` is part of
 * our contract with the upstream `laravel/ai` SDK, so a regression
 * that silently renames it would break every consumer's resolve path.
 * This test pins both the driver name and the resolved class.
 */
final class ServiceProviderTest extends TestCase
{
    public function test_service_provider_boots_without_errors(): void
    {
        $providers = $this->app->getLoadedProviders();

        $this->assertArrayHasKey(LaravelAiRegoloServiceProvider::class, $providers);
        $this->assertTrue($providers[LaravelAiRegoloServiceProvider::class]);
    }

    public function test_ai_instance_regolo_resolves_to_regolo_provider(): void
    {
        $resolved = Ai::instance('regolo');

        $this->assertInstanceOf(RegoloProvider::class, $resolved);
    }

    public function test_regolo_provider_implements_three_capability_interfaces(): void
    {
        $provider = Ai::instance('regolo');

        $this->assertInstanceOf(TextProvider::class, $provider);
        $this->assertInstanceOf(EmbeddingProvider::class, $provider);
        $this->assertInstanceOf(RerankingProvider::class, $provider);
    }

    public function test_regolo_provider_exposes_unified_gateway_for_three_capabilities(): void
    {
        /** @var RegoloProvider $provider */
        $provider = Ai::instance('regolo');

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
        $provider = Ai::instance('regolo');

        $this->assertSame('test-api-key', $provider->providerCredentials()['key']);
    }

    public function test_regolo_provider_additional_configuration_includes_base_url(): void
    {
        /** @var RegoloProvider $provider */
        $provider = Ai::instance('regolo');

        $this->assertSame(
            'https://api.regolo.test/v1',
            $provider->additionalConfiguration()['url'],
        );
    }

    public function test_regolo_provider_resolves_with_default_models_when_unspecified(): void
    {
        /** @var RegoloProvider $provider */
        $provider = Ai::instance('regolo');

        // The package defaults — verified end-to-end through the SDK
        // resolution path so any future config-shape change is caught.
        $this->assertSame('Llama-3.1-8B-Instruct', $provider->defaultTextModel());
        $this->assertSame('Qwen3-Embedding-8B', $provider->defaultEmbeddingsModel());
        $this->assertSame('Qwen3-Reranker-4B', $provider->defaultRerankingModel());
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        // Upstream `laravel/ai` SDK must be registered too — Testbench
        // does not auto-discover packages, so listing only this package's
        // service provider would leave the `Ai` facade pointing at an
        // unbound `AiManager` (whose constructor takes `$app` and cannot
        // be resolved by the container without the SDK provider).
        return [
            AiServiceProvider::class,
            LaravelAiRegoloServiceProvider::class,
        ];
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
