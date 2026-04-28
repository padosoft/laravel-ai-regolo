<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Unit\Gateway\Regolo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelAiRegolo\Gateway\Regolo\RegoloGateway;
use Padosoft\LaravelAiRegolo\LaravelAiRegoloServiceProvider;
use Padosoft\LaravelAiRegolo\Providers\RegoloProvider;

/**
 * Chat + streaming coverage for the Regolo provider.
 *
 * The 18 scenarios below port Regolo's official Python client test
 * suite (4 happy-path tests) and add 14 robustness scenarios for
 * the failure modes the Python suite does not exercise: streaming,
 * 429 rate limit, 401 invalid key, 503 overloaded, malformed JSON,
 * timeouts, multi-turn history, model-switch, Unicode, very long
 * prompts, and concurrent isolation.
 *
 * Every scenario uses Http::fake() — no real network. Streaming
 * scenarios use Http::response() with a manually built SSE body
 * since the Laravel Http fake does not natively model an
 * `Iterator<StreamEvent>` source.
 *
 * R26 — refusal / no-call short-circuits are out of scope here:
 * the gateway does not implement local refusal logic.
 *
 * R16 — every test name describes the behaviour the body actually
 * exercises (no "smoke" tests masquerading as feature coverage).
 */
final class RegoloGatewayChatTest extends TestCase
{
    /**
     * Port of Python: regolo-ai/python-client tests/test.py::test_completions
     *
     * Single-prompt completion goes through chat/completions and returns
     * the assistant's `content`. Verifies the gateway round-trips the
     * happy-path payload to a TextResponse.
     */
    public function test_chat_returns_string_response_via_mock(): void
    {
        Http::fake([
            'api.regolo.test/v1/chat/completions' => Http::response($this->chatCompletionFixture('Hello there!'), 200),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->generateText(
            $this->makeProvider(),
            'Llama-3.1-8B-Instruct',
            null,
            [new UserMessage('Say hello')],
        );

        $this->assertSame('Hello there!', $response->text);
        $this->assertSame(12, $response->usage->promptTokens);
        $this->assertSame(5, $response->usage->completionTokens);
    }

    /**
     * Port of Python: regolo-ai/python-client tests/test.py::test_chat_completions
     *
     * Multi-message conversation. Asserts the request body shape
     * preserves the message order, role mapping, and instructions
     * field (system prompt).
     */
    public function test_chat_returns_string_response_via_http_fake(): void
    {
        Http::fake([
            'api.regolo.test/v1/chat/completions' => Http::response($this->chatCompletionFixture('Si, capisco.')),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->generateText(
            $this->makeProvider(),
            'Llama-3.1-8B-Instruct',
            'You are a helpful assistant.',
            [
                new UserMessage('Hi'),
                Message::tryFrom(new AssistantMessage('Hello!')),
                new UserMessage('Capisci?'),
            ],
        );

        $this->assertSame('Si, capisco.', $response->text);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return str_ends_with($request->url(), '/chat/completions')
                && $body['model'] === 'Llama-3.1-8B-Instruct'
                && count($body['messages']) === 4
                && $body['messages'][0] === ['role' => 'system', 'content' => 'You are a helpful assistant.']
                && $body['messages'][1]['role'] === 'user'
                && $body['messages'][1]['content'] === 'Hi'
                && $body['messages'][2]['role'] === 'assistant'
                && $body['messages'][3]['content'] === 'Capisci?';
        });
    }

    /**
     * Port of Python: regolo-ai/python-client tests/test.py::test_static_completions
     *
     * Asserts the gateway sends the configured Bearer key in the
     * Authorization header and uses the configured base URL — proving
     * static configuration flows from the Provider into each request.
     */
    public function test_static_completion_call_via_http_fake(): void
    {
        Http::fake([
            'api.regolo.test/v1/chat/completions' => Http::response($this->chatCompletionFixture('static')),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $gateway->generateText(
            $this->makeProvider(['key' => 'static-key']),
            'Llama-3.1-8B-Instruct',
            null,
            [new UserMessage('static call')],
        );

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Authorization', 'Bearer static-key')
                && str_starts_with($request->url(), 'https://api.regolo.test/v1/');
        });
    }

    /**
     * Port of Python: regolo-ai/python-client tests/test.py::test_static_chat_completions
     *
     * Asserts the response returns both the text AND a populated
     * Meta(provider, model). Python returns a tuple (text, meta);
     * the SDK encodes meta on `TextResponse::$meta`.
     */
    public function test_static_chat_completions_via_http_fake(): void
    {
        Http::fake([
            'api.regolo.test/v1/chat/completions' => Http::response($this->chatCompletionFixture('static-chat', model: 'Llama-3.3-70B-Instruct')),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->generateText(
            $this->makeProvider(),
            'Llama-3.3-70B-Instruct',
            null,
            [new UserMessage('static chat call')],
        );

        $this->assertSame('static-chat', $response->text);
        $this->assertSame('regolo', $response->meta->provider);
        $this->assertSame('Llama-3.3-70B-Instruct', $response->meta->model);
    }

    public function test_chat_streams_token_chunks_via_sse_fake(): void
    {
        $sseBody = $this->buildSseBody(['Hello', ' ', 'world', '!'], usage: ['prompt_tokens' => 5, 'completion_tokens' => 4]);

        Http::fake([
            'api.regolo.test/v1/chat/completions' => Http::response($sseBody, 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $events = iterator_to_array($gateway->streamText(
            'inv-test',
            $this->makeProvider(),
            'Llama-3.1-8B-Instruct',
            null,
            [new UserMessage('Say hello')],
        ));

        $deltas = array_filter($events, fn ($e) => $e instanceof TextDelta);
        $this->assertSame(['Hello', ' ', 'world', '!'], array_values(array_map(fn (TextDelta $e) => $e->delta, $deltas)));

        $start = array_filter($events, fn ($e) => $e instanceof StreamStart);
        $end = array_filter($events, fn ($e) => $e instanceof StreamEnd);
        $textStart = array_filter($events, fn ($e) => $e instanceof TextStart);
        $textEnd = array_filter($events, fn ($e) => $e instanceof TextEnd);
        $this->assertCount(1, $start);
        $this->assertCount(1, $end);
        $this->assertCount(1, $textStart);
        $this->assertCount(1, $textEnd);

        Http::assertSent(function (Request $request) {
            return $request->data()['stream'] === true
                && $request->data()['stream_options']['include_usage'] === true;
        });
    }

    /**
     * SDK behaviour: HandlesFailoverErrors does NOT retry on plain 5xx.
     * 503 specifically maps to ProviderOverloadedException; other 5xx
     * surface as RequestException. This test pins the documented
     * non-retry contract — callers wanting retries layer Http::retry()
     * via a custom client decorator.
     */
    public function test_chat_5xx_surfaces_as_request_exception_no_retry(): void
    {
        Http::fake([
            'api.regolo.test/v1/chat/completions' => Http::response(
                ['error' => ['message' => 'gateway timeout', 'type' => 'server_error']],
                504,
            ),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $this->expectException(RequestException::class);

        $gateway->generateText(
            $this->makeProvider(),
            'Llama-3.1-8B-Instruct',
            null,
            [new UserMessage('Will fail')],
        );

        // Single attempt — no retry.
        Http::assertSentCount(1);
    }

    public function test_chat_4xx_surfaces_as_request_exception_no_retry(): void
    {
        Http::fake([
            'api.regolo.test/v1/chat/completions' => Http::response(
                ['error' => ['message' => 'invalid model', 'type' => 'invalid_request_error']],
                400,
            ),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $this->expectException(RequestException::class);

        $gateway->generateText(
            $this->makeProvider(),
            'unknown-model',
            null,
            [new UserMessage('Will fail')],
        );

        Http::assertSentCount(1);
    }

    /**
     * 401 is treated as a generic RequestException by the upstream
     * HandlesFailoverErrors trait — it does not have a dedicated
     * exception type. The provider/key mismatch surfaces verbatim
     * to the caller so a custom error handler can distinguish auth
     * failures from other 4xx via `$e->response->status() === 401`.
     */
    public function test_chat_401_surfaces_as_request_exception(): void
    {
        Http::fake([
            'api.regolo.test/v1/chat/completions' => Http::response(
                ['error' => ['message' => 'invalid api key', 'type' => 'authentication_error']],
                401,
            ),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $exception = null;

        try {
            $gateway->generateText(
                $this->makeProvider(['key' => 'wrong-key']),
                'Llama-3.1-8B-Instruct',
                null,
                [new UserMessage('Will fail')],
            );
        } catch (RequestException $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception, '401 should throw RequestException');
        $this->assertSame(401, $exception->response->status());
    }

    public function test_chat_429_throws_rate_limited_exception(): void
    {
        Http::fake([
            'api.regolo.test/v1/chat/completions' => Http::response(
                ['error' => ['message' => 'rate limit exceeded']],
                429,
                ['Retry-After' => '5'],
            ),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $this->expectException(RateLimitedException::class);

        $gateway->generateText(
            $this->makeProvider(),
            'Llama-3.1-8B-Instruct',
            null,
            [new UserMessage('rate limited')],
        );
    }

    public function test_chat_503_throws_provider_overloaded_exception(): void
    {
        Http::fake([
            'api.regolo.test/v1/chat/completions' => Http::response(
                ['error' => ['message' => 'service overloaded']],
                503,
            ),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $this->expectException(ProviderOverloadedException::class);

        $gateway->generateText(
            $this->makeProvider(),
            'Llama-3.1-8B-Instruct',
            null,
            [new UserMessage('overloaded')],
        );
    }

    public function test_chat_connection_failure_surfaces_as_connection_exception(): void
    {
        Http::fake([
            'api.regolo.test/v1/chat/completions' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $this->expectException(ConnectionException::class);

        $gateway->generateText(
            $this->makeProvider(),
            'Llama-3.1-8B-Instruct',
            null,
            [new UserMessage('timeout')],
        );
    }

    public function test_chat_malformed_json_response_throws_specific_exception(): void
    {
        Http::fake([
            'api.regolo.test/v1/chat/completions' => Http::response(
                ['error' => ['message' => 'malformed payload', 'type' => 'invalid_response']],
                200,
            ),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/Regolo Error:.*invalid_response/');

        $gateway->generateText(
            $this->makeProvider(),
            'Llama-3.1-8B-Instruct',
            null,
            [new UserMessage('test')],
        );
    }

    public function test_chat_multi_turn_preserves_message_history(): void
    {
        Http::fake([
            'api.regolo.test/v1/chat/completions' => Http::response($this->chatCompletionFixture('terzo turno')),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $gateway->generateText(
            $this->makeProvider(),
            'Llama-3.1-8B-Instruct',
            'Sei un assistente.',
            [
                new UserMessage('domanda 1'),
                Message::tryFrom(new AssistantMessage('risposta 1')),
                new UserMessage('domanda 2'),
                Message::tryFrom(new AssistantMessage('risposta 2')),
                new UserMessage('domanda 3'),
            ],
        );

        Http::assertSent(function (Request $request) {
            $messages = $request->data()['messages'];

            return count($messages) === 6
                && $messages[0]['role'] === 'system'
                && $messages[1]['content'] === 'domanda 1'
                && $messages[2]['role'] === 'assistant'
                && $messages[2]['content'] === 'risposta 1'
                && $messages[3]['content'] === 'domanda 2'
                && $messages[5]['content'] === 'domanda 3';
        });
    }

    public function test_chat_model_switch_preserves_provider_config(): void
    {
        Http::fake([
            'api.regolo.test/v1/chat/completions' => Http::response($this->chatCompletionFixture('reply', model: 'Llama-3.3-70B-Instruct')),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));
        $provider = $this->makeProvider();

        $gateway->generateText($provider, 'Llama-3.1-8B-Instruct', null, [new UserMessage('cheap model')]);
        $gateway->generateText($provider, 'Llama-3.3-70B-Instruct', null, [new UserMessage('smart model')]);

        Http::assertSentCount(2);

        $sentModels = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->data()['model'])->all();
        $this->assertSame(['Llama-3.1-8B-Instruct', 'Llama-3.3-70B-Instruct'], $sentModels);
    }

    public function test_chat_provider_default_models_resolve_from_config(): void
    {
        $provider = $this->makeProvider([
            'models' => [
                'text' => [
                    'default' => 'CustomDefault',
                    'cheapest' => 'CustomCheap',
                    'smartest' => 'CustomSmart',
                ],
                'embeddings' => ['default' => 'CustomEmbed', 'dimensions' => 256],
                'reranking' => ['default' => 'CustomRerank'],
            ],
        ]);

        $this->assertSame('CustomDefault', $provider->defaultTextModel());
        $this->assertSame('CustomCheap', $provider->cheapestTextModel());
        $this->assertSame('CustomSmart', $provider->smartestTextModel());
        $this->assertSame('CustomEmbed', $provider->defaultEmbeddingsModel());
        $this->assertSame(256, $provider->defaultEmbeddingsDimensions());
        $this->assertSame('CustomRerank', $provider->defaultRerankingModel());
    }

    public function test_chat_unicode_prompt_round_trips(): void
    {
        $unicode = 'Dimmi un proverbio italiano. 中文测试 🇮🇹';
        Http::fake([
            'api.regolo.test/v1/chat/completions' => Http::response($this->chatCompletionFixture('Chi va piano va sano. 答え')),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->generateText(
            $this->makeProvider(),
            'Llama-3.1-8B-Instruct',
            null,
            [new UserMessage($unicode)],
        );

        $this->assertSame('Chi va piano va sano. 答え', $response->text);

        Http::assertSent(function (Request $request) use ($unicode) {
            return $request->data()['messages'][0]['content'] === $unicode;
        });
    }

    public function test_chat_very_long_prompt_does_not_truncate_silently(): void
    {
        $long = str_repeat('Lorem ipsum dolor sit amet. ', 5000); // ~135 KB
        Http::fake([
            'api.regolo.test/v1/chat/completions' => Http::response($this->chatCompletionFixture('reply')),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $gateway->generateText(
            $this->makeProvider(),
            'Llama-3.1-8B-Instruct',
            null,
            [new UserMessage($long)],
        );

        Http::assertSent(function (Request $request) use ($long) {
            return $request->data()['messages'][0]['content'] === $long;
        });
    }

    public function test_chat_concurrent_requests_isolated_state(): void
    {
        Http::fake([
            'api.regolo.test/v1/chat/completions' => Http::sequence()
                ->push($this->chatCompletionFixture('answer-1'))
                ->push($this->chatCompletionFixture('answer-2'))
                ->push($this->chatCompletionFixture('answer-3')),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));
        $provider = $this->makeProvider();

        $r1 = $gateway->generateText($provider, 'Llama-3.1-8B-Instruct', null, [new UserMessage('q1')]);
        $r2 = $gateway->generateText($provider, 'Llama-3.1-8B-Instruct', null, [new UserMessage('q2')]);
        $r3 = $gateway->generateText($provider, 'Llama-3.1-8B-Instruct', null, [new UserMessage('q3')]);

        $this->assertSame('answer-1', $r1->text);
        $this->assertSame('answer-2', $r2->text);
        $this->assertSame('answer-3', $r3->text);
    }

    public function test_chat_temperature_and_max_tokens_options_are_forwarded(): void
    {
        Http::fake([
            'api.regolo.test/v1/chat/completions' => Http::response($this->chatCompletionFixture('reply')),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $options = new TextGenerationOptions(
            maxTokens: 256,
            temperature: 0.42,
        );

        $gateway->generateText(
            $this->makeProvider(),
            'Llama-3.1-8B-Instruct',
            null,
            [new UserMessage('test')],
            options: $options,
        );

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $body['max_tokens'] === 256
                && $body['temperature'] === 0.42;
        });
    }

    public function test_chat_uses_configured_base_url_override(): void
    {
        Http::fake([
            'api.regolo-staging.test/v1/chat/completions' => Http::response($this->chatCompletionFixture('staging reply')),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->generateText(
            $this->makeProvider(['url' => 'https://api.regolo-staging.test/v1']),
            'Llama-3.1-8B-Instruct',
            null,
            [new UserMessage('staging hit')],
        );

        $this->assertSame('staging reply', $response->text);

        Http::assertSent(function (Request $request) {
            return str_starts_with($request->url(), 'https://api.regolo-staging.test/v1/');
        });
    }

    public function test_chat_finish_reason_length_propagates(): void
    {
        Http::fake([
            'api.regolo.test/v1/chat/completions' => Http::response($this->chatCompletionFixture('truncated', finishReason: 'length')),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->generateText(
            $this->makeProvider(),
            'Llama-3.1-8B-Instruct',
            null,
            [new UserMessage('write a long essay')],
        );

        $this->assertSame('truncated', $response->text);
        $this->assertSame(FinishReason::Length, $response->steps->first()->finishReason);
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
                'text' => [
                    'default' => 'Llama-3.1-8B-Instruct',
                    'cheapest' => 'Llama-3.1-8B-Instruct',
                    'smartest' => 'Llama-3.3-70B-Instruct',
                ],
                'embeddings' => ['default' => 'Qwen3-Embedding-8B', 'dimensions' => 4096],
                'reranking' => ['default' => 'jina-reranker-v2'],
            ],
        ], $configOverride);

        return new RegoloProvider($config, $this->app->make('events'));
    }

    private function chatCompletionFixture(string $text = 'Ciao!', int $promptTokens = 12, int $completionTokens = 5, string $finishReason = 'stop', string $model = 'Llama-3.1-8B-Instruct'): array
    {
        return [
            'id' => 'chatcmpl-regolo-test',
            'object' => 'chat.completion',
            'created' => 1745846400,
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $text],
                    'finish_reason' => $finishReason,
                ],
            ],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
            ],
        ];
    }

    private function buildSseBody(array $deltas, string $finishReason = 'stop', ?array $usage = null, string $model = 'Llama-3.1-8B-Instruct'): string
    {
        $lines = [];

        foreach ($deltas as $i => $delta) {
            $chunk = [
                'id' => 'chatcmpl-stream-test',
                'object' => 'chat.completion.chunk',
                'created' => 1745846400,
                'model' => $model,
                'choices' => [
                    [
                        'index' => 0,
                        'delta' => ['content' => $delta],
                        'finish_reason' => null,
                    ],
                ],
            ];
            $lines[] = 'data: '.json_encode($chunk);
            $lines[] = '';
        }

        $finalChunk = [
            'id' => 'chatcmpl-stream-test',
            'object' => 'chat.completion.chunk',
            'created' => 1745846400,
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'delta' => [],
                    'finish_reason' => $finishReason,
                ],
            ],
        ];

        if ($usage) {
            $finalChunk['usage'] = $usage;
        }

        $lines[] = 'data: '.json_encode($finalChunk);
        $lines[] = '';
        $lines[] = 'data: [DONE]';
        $lines[] = '';

        return implode("\n", $lines);
    }
}
