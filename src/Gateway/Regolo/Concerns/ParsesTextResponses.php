<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Gateway\Regolo\Concerns;

use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Parse one Regolo Chat Completions response into a single step.
 *
 * This trait used to run the whole conversation: it counted steps against
 * `maxSteps`, executed tool calls, fed the results back in and recursed.
 * From laravel/ai 0.11 the SDK owns that loop, so all of it is deleted
 * rather than ported — a gateway that also loops is a second implementation
 * of the same policy, and the two would disagree the first time the SDK
 * changed how it decides a conversation is finished.
 *
 * What remains is the part that is genuinely Regolo's: turning its response
 * shape — OpenAI Chat Completions classic — into the SDK's.
 */
trait ParsesTextResponses
{
    use DecodesStructuredOutput;

    /**
     * Reject an error envelope before it is read as a result.
     *
     * A JSON body is not the same thing as a JSON answer: an error comes back
     * with a perfectly valid structure, and parsing it as a completion would
     * produce an empty assistant turn instead of a failure the caller can see.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (! $data || isset($data['error']) || ($data['object'] ?? null) === 'error') {
            throw new AiException(sprintf(
                'Regolo Error: [%s] %s',
                $data['error']['type'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown Regolo error.',
            ));
        }
    }

    /**
     * Parse the response data into a single step response.
     *
     * @param  array<string, mixed>  $data
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        bool $structured,
    ): StepResponse {
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $model = $data['model'] ?? '';

        $text = $this->extractContentText($message['content'] ?? '');

        $toolCalls = array_map(
            fn (array $toolCall): ToolCall => new ToolCall(
                $toolCall['id'] ?? '',
                $toolCall['function']['name'] ?? '',
                json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [],
                $toolCall['id'] ?? null,
            ),
            $message['tool_calls'] ?? [],
        );

        return new StepResponse(
            text: $text,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason($choice),
            usage: $this->extractUsage($data),
            meta: new Meta($provider->name(), $model),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
        );
    }

    /**
     * Extract the text from a message content value.
     *
     * Regolo returns either a plain string or a list of content chunks,
     * depending on the model.
     */
    protected function extractContentText(mixed $content): string
    {
        if (! is_array($content)) {
            return (string) $content;
        }

        return implode('', array_map(
            fn (mixed $chunk): string => is_array($chunk) ? (string) ($chunk['text'] ?? '') : (string) $chunk,
            $content,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractUsage(array $data): Usage
    {
        $usage = $data['usage'] ?? [];

        return new Usage(
            $usage['prompt_tokens'] ?? 0,
            $usage['completion_tokens'] ?? 0,
        );
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    protected function extractFinishReason(array $choice): FinishReason
    {
        return match ($choice['finish_reason'] ?? '') {
            'stop' => FinishReason::Stop,
            'tool_calls' => FinishReason::ToolCalls,
            'length' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }
}
