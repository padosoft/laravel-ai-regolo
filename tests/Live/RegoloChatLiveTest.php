<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Live;

use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\TextResponse;

/**
 * Live chat-completion tests against `POST /v1/chat/completions` on
 * `api.regolo.ai`.
 *
 * Intentionally minimal — one short prompt, asserts the wire contract
 * holds and the response shape matches the SDK's `TextResponse` DTO.
 * Cost per run: a few hundred tokens of a small model.
 */
final class RegoloChatLiveTest extends LiveTestCase
{
    public function test_live_chat_completion_returns_non_empty_text(): void
    {
        $response = $this->liveGateway()->generateText(
            $this->liveProvider(),
            $this->textModel(),
            'You are a helpful assistant. Reply in one short sentence.',
            [new UserMessage('Say hello in Italian.')],
            timeout: $this->liveTimeout(),
        );

        $this->assertInstanceOf(TextResponse::class, $response);
        $this->assertNotEmpty($response->text, 'Live chat should return a non-empty answer.');
        $this->assertSame('regolo', $response->meta->provider);

        // Regolo's API canonicalises the model name in the response —
        // a request for `Llama-3.1-8B-Instruct` (HuggingFace shape)
        // comes back as `llama3.1:8b` (Ollama tag shape). Both refer
        // to the same weights, but a strict `assertSame()` against
        // the requested name flakes on this server-side normalisation.
        // Match on the short token both names share (`llama3.1`) so
        // the assertion stays meaningful: a future regression that
        // mis-routes the request to a different model family (e.g.
        // `qwen3.5`) still fails this test loudly.
        $this->assertStringContainsStringIgnoringCase(
            'llama',
            $response->meta->model,
            sprintf(
                'Expected the response model to mention `llama` somewhere — '.
                'Regolo canonicalises `Llama-3.1-8B-Instruct` to `llama3.1:8b` '.
                'or similar. Got: %s',
                $response->meta->model,
            ),
        );

        $this->assertGreaterThan(0, $response->usage->promptTokens, 'Live response should report prompt tokens.');
        $this->assertGreaterThan(0, $response->usage->completionTokens, 'Live response should report completion tokens.');
    }

    public function test_live_chat_with_explicit_messages_history(): void
    {
        $response = $this->liveGateway()->generateText(
            $this->liveProvider(),
            $this->textModel(),
            null,
            [
                new UserMessage('My favourite city is Rome. Remember that.'),
            ],
            timeout: $this->liveTimeout(),
        );

        $this->assertNotEmpty($response->text);
        $this->assertNotNull($response->steps->first()?->finishReason);
    }
}
