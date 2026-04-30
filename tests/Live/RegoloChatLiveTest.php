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
        // Match on the leading alphabetic family token derived from
        // the *requested* model (`Llama-3.1-8B-Instruct` → `Llama`,
        // `Qwen3-Coder-30B` → `Qwen`, `Mistral-7B-Instruct` →
        // `Mistral`), so the suite stays correct when an operator
        // points `REGOLO_LIVE_TEXT_MODEL` at any other catalogue
        // entry — Copilot review on PR #11 round-2 flagged the prior
        // hard-coded `'llama'` for exactly that reason. A future
        // regression that mis-routes the request to a different
        // family still fails this assertion loudly.
        $family = preg_match('/^([A-Za-z]+)/', $this->textModel(), $matches) === 1
            ? $matches[1]
            : $this->textModel();

        $this->assertStringContainsStringIgnoringCase(
            $family,
            $response->meta->model,
            sprintf(
                'Expected the response model to mention the family `%s` derived '.
                'from the requested model `%s` — Regolo canonicalises tags '.
                '(e.g. `Llama-3.1-8B-Instruct` → `llama3.1:8b`) but the family '.
                'prefix survives the canonicalisation. Got: %s',
                $family,
                $this->textModel(),
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
