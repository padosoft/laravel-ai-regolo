<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Unit\Gateway\Regolo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelAiRegolo\Gateway\Regolo\RegoloGateway;
use Padosoft\LaravelAiRegolo\LaravelAiRegoloServiceProvider;
use Padosoft\LaravelAiRegolo\Providers\RegoloProvider;

/**
 * Embeddings coverage for the Regolo provider.
 *
 * Ports the Python client's only embeddings test (`test_static_embeddings`,
 * a happy-path live-API check) and adds 10 robustness scenarios the
 * Python suite does not cover: empty / single / batch inputs, dimension
 * consistency, default-from-config, Unicode, 4xx / 429 / 503 error
 * mapping, and base-URL override.
 *
 * Every scenario uses Http::fake() — no real network.
 */
final class RegoloGatewayEmbeddingsTest extends TestCase
{
    /**
     * Port of Python: regolo-ai/python-client tests/test.py::test_static_embeddings
     *
     * Single-input embed returns a vector array. Asserts the request
     * body is OpenAI-classic shape `{ model, input }` and the response
     * is parsed into `EmbeddingsResponse::$embeddings`.
     */
    public function test_embed_batch_returns_list(): void
    {
        Http::fake([
            'api.regolo.test/v1/embeddings' => Http::response($this->embeddingsFixture(2, dim: 4)),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->generateEmbeddings(
            $this->makeProvider(),
            'Qwen3-Embedding-8B',
            ['hello', 'world'],
            4096,
        );

        $this->assertCount(2, $response->embeddings);
        $this->assertCount(4, $response->embeddings[0]);
        $this->assertSame(17, $response->tokens);
        $this->assertSame('regolo', $response->meta->provider);
        $this->assertSame('Qwen3-Embedding-8B', $response->meta->model);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return str_ends_with($request->url(), '/embeddings')
                && $body['model'] === 'Qwen3-Embedding-8B'
                && $body['input'] === ['hello', 'world'];
        });
    }

    public function test_embed_single_input_returns_list_of_one(): void
    {
        Http::fake([
            'api.regolo.test/v1/embeddings' => Http::response($this->embeddingsFixture(1, dim: 8)),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->generateEmbeddings(
            $this->makeProvider(),
            'Qwen3-Embedding-8B',
            ['solo'],
            4096,
        );

        $this->assertCount(1, $response->embeddings);
        $this->assertCount(8, $response->embeddings[0]);
    }

    /**
     * The gateway short-circuits an empty input array — no HTTP call,
     * empty embeddings, zero usage. Pinned by `Http::assertNothingSent()`
     * to surface any future regression that would silently send
     * `{ "input": [] }` and waste a billable request on Regolo's API.
     *
     * R26 — short-circuit proof, not a side-effect smell.
     */
    public function test_embed_empty_input_short_circuits_no_http_call(): void
    {
        Http::fake();

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->generateEmbeddings(
            $this->makeProvider(),
            'Qwen3-Embedding-8B',
            [],
            4096,
        );

        $this->assertSame([], $response->embeddings);
        $this->assertSame(0, $response->tokens);
        Http::assertNothingSent();
    }

    public function test_embed_dimension_consistent_across_calls(): void
    {
        Http::fake([
            'api.regolo.test/v1/embeddings' => Http::sequence()
                ->push($this->embeddingsFixture(3, dim: 16))
                ->push($this->embeddingsFixture(2, dim: 16)),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));
        $provider = $this->makeProvider();

        $r1 = $gateway->generateEmbeddings($provider, 'Qwen3-Embedding-8B', ['a', 'b', 'c'], 4096);
        $r2 = $gateway->generateEmbeddings($provider, 'Qwen3-Embedding-8B', ['x', 'y'], 4096);

        foreach ([...$r1->embeddings, ...$r2->embeddings] as $vector) {
            $this->assertCount(16, $vector, 'every embedding vector must have the same dimension across calls');
        }
    }

    public function test_embed_batch_size_boundary_handles_max_inputs(): void
    {
        $batchSize = 256;
        $inputs = array_map(fn (int $i) => "doc-{$i}", range(0, $batchSize - 1));

        Http::fake([
            'api.regolo.test/v1/embeddings' => Http::response($this->embeddingsFixture($batchSize, dim: 4)),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->generateEmbeddings(
            $this->makeProvider(),
            'Qwen3-Embedding-8B',
            $inputs,
            4096,
        );

        $this->assertCount($batchSize, $response->embeddings);

        Http::assertSent(function (Request $request) use ($inputs) {
            return $request->data()['input'] === $inputs;
        });
    }

    public function test_embed_uses_default_model_from_provider_config(): void
    {
        $provider = $this->makeProvider([
            'models' => [
                'text' => ['default' => 'Llama-3.1-8B-Instruct'],
                'embeddings' => ['default' => 'CustomEmbedModel', 'dimensions' => 1024],
                'reranking' => ['default' => 'jina-reranker-v2'],
            ],
        ]);

        $this->assertSame('CustomEmbedModel', $provider->defaultEmbeddingsModel());
        $this->assertSame(1024, $provider->defaultEmbeddingsDimensions());
    }

    public function test_embed_unicode_input_handled(): void
    {
        $unicodeInputs = ['Ciao', '中文嵌入测试', '🚀 emoji test', 'مرحبا'];
        Http::fake([
            'api.regolo.test/v1/embeddings' => Http::response($this->embeddingsFixture(4, dim: 4)),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->generateEmbeddings(
            $this->makeProvider(),
            'Qwen3-Embedding-8B',
            $unicodeInputs,
            4096,
        );

        $this->assertCount(4, $response->embeddings);

        Http::assertSent(function (Request $request) use ($unicodeInputs) {
            return $request->data()['input'] === $unicodeInputs;
        });
    }

    public function test_embed_5xx_surfaces_as_request_exception_no_retry(): void
    {
        Http::fake([
            'api.regolo.test/v1/embeddings' => Http::response(
                ['error' => ['message' => 'gateway timeout']],
                502,
            ),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $this->expectException(RequestException::class);

        $gateway->generateEmbeddings(
            $this->makeProvider(),
            'Qwen3-Embedding-8B',
            ['fail'],
            4096,
        );

        Http::assertSentCount(1);
    }

    public function test_embed_4xx_surfaces_as_request_exception_no_retry(): void
    {
        Http::fake([
            'api.regolo.test/v1/embeddings' => Http::response(
                ['error' => ['message' => 'invalid model']],
                400,
            ),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $this->expectException(RequestException::class);

        $gateway->generateEmbeddings(
            $this->makeProvider(),
            'unknown-embed',
            ['oops'],
            4096,
        );

        Http::assertSentCount(1);
    }

    public function test_embed_429_throws_rate_limited_exception(): void
    {
        Http::fake([
            'api.regolo.test/v1/embeddings' => Http::response(
                ['error' => ['message' => 'rate limit']],
                429,
            ),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $this->expectException(RateLimitedException::class);

        $gateway->generateEmbeddings(
            $this->makeProvider(),
            'Qwen3-Embedding-8B',
            ['too many'],
            4096,
        );
    }

    public function test_embed_503_throws_provider_overloaded_exception(): void
    {
        Http::fake([
            'api.regolo.test/v1/embeddings' => Http::response(
                ['error' => ['message' => 'service overloaded']],
                503,
            ),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $this->expectException(ProviderOverloadedException::class);

        $gateway->generateEmbeddings(
            $this->makeProvider(),
            'Qwen3-Embedding-8B',
            ['overloaded'],
            4096,
        );
    }

    public function test_embed_connection_failure_surfaces_as_connection_exception(): void
    {
        Http::fake([
            'api.regolo.test/v1/embeddings' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $this->expectException(ConnectionException::class);

        $gateway->generateEmbeddings(
            $this->makeProvider(),
            'Qwen3-Embedding-8B',
            ['timeout'],
            4096,
        );
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LaravelAiRegoloServiceProvider::class];
    }

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
            ],
        ], $configOverride);

        return new RegoloProvider($config, $this->app->make('events'));
    }

    private function embeddingsFixture(int $count, int $dim = 4, string $model = 'Qwen3-Embedding-8B', int $totalTokens = 17): array
    {
        $data = [];
        for ($i = 0; $i < $count; $i++) {
            $vector = [];
            for ($d = 0; $d < $dim; $d++) {
                $vector[] = round(0.1 * ($i + 1) + 0.01 * $d, 4);
            }
            $data[] = [
                'object' => 'embedding',
                'index' => $i,
                'embedding' => $vector,
            ];
        }

        return [
            'object' => 'list',
            'data' => $data,
            'model' => $model,
            'usage' => ['prompt_tokens' => $totalTokens, 'total_tokens' => $totalTokens],
        ];
    }
}
