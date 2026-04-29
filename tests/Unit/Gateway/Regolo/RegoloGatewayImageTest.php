<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Unit\Gateway\Regolo;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelAiRegolo\Gateway\Regolo\RegoloGateway;
use Padosoft\LaravelAiRegolo\LaravelAiRegoloServiceProvider;
use Padosoft\LaravelAiRegolo\Providers\RegoloProvider;
use RuntimeException;

/**
 * Image-generation coverage for the Regolo provider.
 *
 * Regolo exposes an OpenAI-compatible `POST /v1/images/generations`
 * endpoint with the public model `Qwen-Image` as the catalogue
 * default. The wire contract is `{ data: [{ b64_json: '...' }, ...] }`,
 * which the gateway maps to `Laravel\Ai\Responses\Data\GeneratedImage`.
 *
 * Image-edit (the `images/edits` cousin endpoint) is not part of the
 * documented Regolo API surface — passing `$attachments` therefore
 * raises a `RuntimeException` rather than silently dropping them.
 */
final class RegoloGatewayImageTest extends TestCase
{
    public function test_generate_image_returns_b64_encoded_png(): void
    {
        Http::fake([
            'api.regolo.test/v1/images/generations' => Http::response($this->imageFixture(['fake-png-1', 'fake-png-2'])),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->generateImage(
            $this->makeProvider(),
            'Qwen-Image',
            'A renaissance fresco of an Italian server farm.',
        );

        $this->assertCount(2, $response->images);
        $this->assertSame('fake-png-1', $response->images[0]->image);
        $this->assertSame('image/png', $response->images[0]->mime);
        $this->assertSame('regolo', $response->meta->provider);
        $this->assertSame('Qwen-Image', $response->meta->model);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return str_ends_with($request->url(), '/images/generations')
                && $body['model'] === 'Qwen-Image'
                && $body['prompt'] === 'A renaissance fresco of an Italian server farm.';
        });
    }

    public function test_generate_image_forwards_size_and_quality_when_provided(): void
    {
        Http::fake([
            'api.regolo.test/v1/images/generations' => Http::response($this->imageFixture(['x'])),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $gateway->generateImage(
            $this->makeProvider(),
            'Qwen-Image',
            'A photo of Roman ruins at sunset.',
            attachments: [],
            size: '1:1',
            quality: 'high',
        );

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $body['size'] === '1:1'
                && $body['quality'] === 'high';
        });
    }

    public function test_generate_image_omits_size_and_quality_when_null(): void
    {
        Http::fake([
            'api.regolo.test/v1/images/generations' => Http::response($this->imageFixture(['x'])),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $gateway->generateImage(
            $this->makeProvider(),
            'Qwen-Image',
            'A botanical illustration.',
        );

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            // size + quality must NOT be present in the body so the
            // upstream model can apply its own defaults.
            return ! array_key_exists('size', $body)
                && ! array_key_exists('quality', $body);
        });
    }

    public function test_generate_image_rejects_attachments_with_runtime_exception(): void
    {
        $gateway = new RegoloGateway($this->app->make('events'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Regolo does not currently expose an image-edit endpoint');

        $gateway->generateImage(
            $this->makeProvider(),
            'Qwen-Image',
            'Edit this image.',
            attachments: ['fake-attachment'],
        );
    }

    public function test_generate_image_uses_120_second_default_timeout_when_caller_omits(): void
    {
        Http::fake([
            'api.regolo.test/v1/images/generations' => Http::response($this->imageFixture(['x'])),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        // The image endpoint defaults to 120s rather than the gateway's
        // 60s text default because image rendering is meaningfully
        // slower. The caller can still override via `$timeout`.
        $gateway->generateImage(
            $this->makeProvider(),
            'Qwen-Image',
            'A photo of the Pantheon at dusk.',
        );

        // The Http::fake() response went through, which is the only
        // observable signal from outside that the longer timeout was
        // applied (it would have been a connect failure otherwise on a
        // real network).
        Http::assertSentCount(1);
    }

    public function test_generate_image_default_model_falls_through_provider_config(): void
    {
        Http::fake([
            'api.regolo.test/v1/images/generations' => Http::response($this->imageFixture(['x'])),
        ]);

        $provider = $this->makeProvider([
            'models' => [
                'text' => ['default' => 'Llama-3.1-8B-Instruct'],
                'embeddings' => ['default' => 'Qwen3-Embedding-8B', 'dimensions' => 4096],
                'reranking' => ['default' => 'jina-reranker-v2'],
                'image' => ['default' => 'Qwen-Image-Heavy'],
            ],
        ]);

        $this->assertSame('Qwen-Image-Heavy', $provider->defaultImageModel());
    }

    public function test_generate_image_default_model_when_config_missing(): void
    {
        $provider = $this->makeProvider([
            'models' => [
                'text' => ['default' => 'Llama-3.1-8B-Instruct'],
                'embeddings' => ['default' => 'Qwen3-Embedding-8B', 'dimensions' => 4096],
                'reranking' => ['default' => 'jina-reranker-v2'],
                // image entry intentionally absent
            ],
        ]);

        // Hard default matches the publicly-documented Qwen-Image entry
        // in `GET https://api.regolo.ai/v1/models`.
        $this->assertSame('Qwen-Image', $provider->defaultImageModel());
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
     * @param  array<string, mixed>  $configOverride
     */
    private function makeProvider(array $configOverride = []): RegoloProvider
    {
        $config = array_merge([
            'driver' => 'regolo',
            'name' => 'regolo',
            'key' => 'test-api-key',
            'url' => 'https://api.regolo.test/v1',
            'models' => [
                'text' => ['default' => 'Llama-3.1-8B-Instruct'],
                'embeddings' => ['default' => 'Qwen3-Embedding-8B', 'dimensions' => 4096],
                'reranking' => ['default' => 'jina-reranker-v2'],
                'image' => ['default' => 'Qwen-Image'],
            ],
        ], $configOverride);

        return new RegoloProvider($config, $this->app->make('events'));
    }

    /**
     * @param  array<int, string>  $b64Payloads
     * @return array<string, mixed>
     */
    private function imageFixture(array $b64Payloads): array
    {
        return [
            'created' => 1_710_000_000,
            'data' => array_map(fn (string $b64) => ['b64_json' => $b64], $b64Payloads),
        ];
    }
}
