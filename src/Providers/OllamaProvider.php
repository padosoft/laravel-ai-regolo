<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Providers;

use Laravel\Ai\Contracts\Provider;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

/**
 * Ollama provider for the official `laravel/ai` SDK.
 *
 * Two deployment modes share this class:
 *  - Local: `OLLAMA_BASE_URL` defaults to `http://localhost:11434`,
 *    no auth header.
 *  - Cloud: when `OLLAMA_CLOUD_KEY` is set, the base URL switches to
 *    Ollama Cloud and the request carries a bearer-token header.
 *
 * The chat endpoint (`/v1/chat/completions`) is OpenAI-compatible.
 * Embeddings use the Ollama-native `/api/embeddings` shape because
 * the OpenAI-compatible layer does not cover that capability at the
 * time of writing.
 *
 * Implementation lands in W2.A.4.
 */
final class OllamaProvider implements Provider
{
    public function __construct(
        private readonly string $baseUrl = 'http://localhost:11434',
        private readonly ?string $cloudKey = null,
        private readonly int $timeout = 60,
    ) {
    }

    public function prompt(AgentPrompt $prompt): AgentResponse
    {
        throw new \LogicException(
            'OllamaProvider::prompt is not yet implemented. Tracked in W2.A.4.',
        );
    }

    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        throw new \LogicException(
            'OllamaProvider::stream is not yet implemented. Tracked in W2.A.4.',
        );
    }
}
