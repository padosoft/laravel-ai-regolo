<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Providers;

use Laravel\Ai\Contracts\Provider;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

/**
 * Regolo (Seeweb) provider for the official `laravel/ai` SDK.
 *
 * Targets `https://api.regolo.ai/v1` (OpenAI-compatible). Authentication
 * is bearer-token via the `REGOLO_API_KEY` env. The catalog of
 * Regolo open models is fetched dynamically from `/v1/models` on first
 * use and cached for the lifetime of the application instance.
 *
 * Implementation lands in W2.A.2. This class currently throws on
 * every call so that early consumers get a clear "not yet
 * implemented" signal rather than a silent no-op.
 */
final class RegoloProvider implements Provider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.regolo.ai/v1',
        private readonly int $timeout = 60,
    ) {
    }

    public function prompt(AgentPrompt $prompt): AgentResponse
    {
        throw new \LogicException(
            'RegoloProvider::prompt is not yet implemented. Tracked in W2.A.2.',
        );
    }

    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        throw new \LogicException(
            'RegoloProvider::stream is not yet implemented. Tracked in W2.A.2.',
        );
    }
}
