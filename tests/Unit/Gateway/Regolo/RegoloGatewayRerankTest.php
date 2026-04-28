<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Unit\Gateway\Regolo;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\RankedDocument;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelAiRegolo\Gateway\Regolo\RegoloGateway;
use Padosoft\LaravelAiRegolo\LaravelAiRegoloServiceProvider;
use Padosoft\LaravelAiRegolo\Providers\RegoloProvider;

/**
 * Reranking coverage for the Regolo provider.
 *
 * Ports the Python client's `test_rerank` happy path and adds 10
 * robustness scenarios: empty / single / many documents, top_n
 * boundaries, descending score ordering, index integrity, default
 * model from config, and 4xx / 429 / 503 error mapping.
 *
 * Wire format follows the Cohere/Jina shape:
 *  - request: `{ model, query, documents: [...], top_n? }`
 *  - response: `{ results: [{ index, relevance_score }, ...] }`
 *
 * Every scenario uses Http::fake() — no real network.
 */
final class RegoloGatewayRerankTest extends TestCase
{
    /**
     * Port of Python: regolo-ai/python-client tests/test.py::test_rerank
     *
     * Reranks 3 documents against a query, returns scored list. Asserts
     * the request payload shape and the response is parsed into
     * `RankedDocument[]` with original document strings preserved.
     */
    public function test_rerank_returns_scored_list(): void
    {
        $documents = ['Roma è la capitale.', 'Milano è in Lombardia.', 'Pasta al pomodoro.'];

        Http::fake([
            'api.regolo.test/v1/rerank' => Http::response($this->rerankFixture([
                ['index' => 0, 'score' => 0.91],
                ['index' => 1, 'score' => 0.42],
                ['index' => 2, 'score' => 0.05],
            ])),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->rerank(
            $this->makeProvider(),
            'jina-reranker-v2',
            $documents,
            'Quale città è la capitale italiana?',
        );

        $this->assertCount(3, $response->results);
        $this->assertSame('Roma è la capitale.', $response->results[0]->document);
        $this->assertSame(0.91, $response->results[0]->score);
        $this->assertSame(0, $response->results[0]->index);
        $this->assertSame('regolo', $response->meta->provider);

        Http::assertSent(function (Request $request) use ($documents) {
            $body = $request->data();

            return str_ends_with($request->url(), '/rerank')
                && $body['model'] === 'jina-reranker-v2'
                && $body['query'] === 'Quale città è la capitale italiana?'
                && $body['documents'] === $documents
                && ! array_key_exists('top_n', $body);
        });
    }

    /**
     * The gateway short-circuits an empty document list — no HTTP call,
     * empty results. Pinned by `Http::assertNothingSent()`.
     */
    public function test_rerank_empty_documents_short_circuits_no_http_call(): void
    {
        Http::fake();

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->rerank(
            $this->makeProvider(),
            'jina-reranker-v2',
            [],
            'any query',
        );

        $this->assertSame([], $response->results);
        Http::assertNothingSent();
    }

    public function test_rerank_single_document_returns_list_of_one(): void
    {
        Http::fake([
            'api.regolo.test/v1/rerank' => Http::response($this->rerankFixture([
                ['index' => 0, 'score' => 0.5],
            ])),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->rerank(
            $this->makeProvider(),
            'jina-reranker-v2',
            ['only one'],
            'something',
        );

        $this->assertCount(1, $response->results);
        $this->assertSame('only one', $response->results[0]->document);
    }

    public function test_rerank_top_n_boundary_returns_at_most_top_n(): void
    {
        $documents = array_map(fn (int $i) => "doc-{$i}", range(0, 9));

        Http::fake([
            'api.regolo.test/v1/rerank' => Http::response($this->rerankFixture([
                ['index' => 3, 'score' => 0.95],
                ['index' => 7, 'score' => 0.81],
                ['index' => 1, 'score' => 0.55],
            ])),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->rerank(
            $this->makeProvider(),
            'jina-reranker-v2',
            $documents,
            'q',
            limit: 3,
        );

        $this->assertCount(3, $response->results);

        Http::assertSent(function (Request $request) {
            return $request->data()['top_n'] === 3;
        });
    }

    public function test_rerank_top_n_zero_omitted_from_request(): void
    {
        // top_n=0 is treated by `array_filter(... !== null)` as still a numeric
        // value to send. The gateway forwards `0` verbatim because the SDK
        // does not redefine "limit=0 means everything". Asserts the wire
        // contract verbatim — caller is responsible for choosing 0 wisely.
        Http::fake([
            'api.regolo.test/v1/rerank' => Http::response($this->rerankFixture([])),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $gateway->rerank(
            $this->makeProvider(),
            'jina-reranker-v2',
            ['a', 'b'],
            'q',
            limit: 0,
        );

        Http::assertSent(function (Request $request) {
            return $request->data()['top_n'] === 0;
        });
    }

    public function test_rerank_top_n_greater_than_doc_count_returns_all(): void
    {
        $documents = ['one', 'two'];

        Http::fake([
            'api.regolo.test/v1/rerank' => Http::response($this->rerankFixture([
                ['index' => 1, 'score' => 0.7],
                ['index' => 0, 'score' => 0.3],
            ])),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->rerank(
            $this->makeProvider(),
            'jina-reranker-v2',
            $documents,
            'q',
            limit: 99,
        );

        $this->assertCount(2, $response->results);

        Http::assertSent(function (Request $request) {
            return $request->data()['top_n'] === 99;
        });
    }

    public function test_rerank_default_model_resolves_from_config(): void
    {
        $provider = $this->makeProvider([
            'models' => [
                'text' => ['default' => 'Llama-3.1-8B-Instruct'],
                'embeddings' => ['default' => 'Qwen3-Embedding-8B', 'dimensions' => 4096],
                'reranking' => ['default' => 'CustomReranker'],
            ],
        ]);

        $this->assertSame('CustomReranker', $provider->defaultRerankingModel());
    }

    public function test_rerank_relevance_scores_descending_order(): void
    {
        $documents = ['a', 'b', 'c', 'd'];

        Http::fake([
            'api.regolo.test/v1/rerank' => Http::response($this->rerankFixture([
                ['index' => 2, 'score' => 0.92],
                ['index' => 0, 'score' => 0.65],
                ['index' => 3, 'score' => 0.31],
                ['index' => 1, 'score' => 0.09],
            ])),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->rerank(
            $this->makeProvider(),
            'jina-reranker-v2',
            $documents,
            'q',
        );

        // R16 — strictly monotonic-decreasing fixture so the assertion
        // would fail if scores were ever sorted ascending.
        $scores = array_map(fn (RankedDocument $r) => $r->score, $response->results);
        for ($i = 0; $i < count($scores) - 1; $i++) {
            $this->assertGreaterThan($scores[$i + 1], $scores[$i], 'rerank scores must arrive in descending order');
        }
    }

    public function test_rerank_index_field_matches_input_position(): void
    {
        $documents = ['zero', 'one', 'two', 'three'];

        Http::fake([
            'api.regolo.test/v1/rerank' => Http::response($this->rerankFixture([
                ['index' => 2, 'score' => 0.9],
                ['index' => 0, 'score' => 0.5],
            ])),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->rerank(
            $this->makeProvider(),
            'jina-reranker-v2',
            $documents,
            'q',
        );

        $this->assertSame(2, $response->results[0]->index);
        $this->assertSame('two', $response->results[0]->document);
        $this->assertSame(0, $response->results[1]->index);
        $this->assertSame('zero', $response->results[1]->document);
    }

    public function test_rerank_5xx_surfaces_as_request_exception_no_retry(): void
    {
        Http::fake([
            'api.regolo.test/v1/rerank' => Http::response(
                ['error' => ['message' => 'gateway timeout']],
                504,
            ),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $this->expectException(RequestException::class);

        $gateway->rerank(
            $this->makeProvider(),
            'jina-reranker-v2',
            ['fail'],
            'q',
        );

        Http::assertSentCount(1);
    }

    public function test_rerank_4xx_surfaces_as_request_exception_no_retry(): void
    {
        Http::fake([
            'api.regolo.test/v1/rerank' => Http::response(
                ['error' => ['message' => 'invalid model']],
                400,
            ),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $this->expectException(RequestException::class);

        $gateway->rerank(
            $this->makeProvider(),
            'unknown-reranker',
            ['oops'],
            'q',
        );

        Http::assertSentCount(1);
    }

    public function test_rerank_429_throws_rate_limited_exception(): void
    {
        Http::fake([
            'api.regolo.test/v1/rerank' => Http::response(
                ['error' => ['message' => 'rate limit']],
                429,
            ),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $this->expectException(RateLimitedException::class);

        $gateway->rerank(
            $this->makeProvider(),
            'jina-reranker-v2',
            ['rate'],
            'q',
        );
    }

    public function test_rerank_503_throws_provider_overloaded_exception(): void
    {
        Http::fake([
            'api.regolo.test/v1/rerank' => Http::response(
                ['error' => ['message' => 'service overloaded']],
                503,
            ),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $this->expectException(ProviderOverloadedException::class);

        $gateway->rerank(
            $this->makeProvider(),
            'jina-reranker-v2',
            ['busy'],
            'q',
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

    /**
     * Build a Cohere/Jina-style rerank response.
     *
     * @param  array<array{index:int, score:float}>  $rankings  results in the wire order the Regolo API would return
     * @param  string  $model  echoed model identifier
     */
    private function rerankFixture(array $rankings, string $model = 'jina-reranker-v2'): array
    {
        return [
            'id' => 'rerank-test',
            'model' => $model,
            'results' => array_map(fn (array $r) => [
                'index' => $r['index'],
                'relevance_score' => $r['score'],
            ], $rankings),
            'meta' => ['billed_units' => ['search_units' => count($rankings)]],
        ];
    }
}
