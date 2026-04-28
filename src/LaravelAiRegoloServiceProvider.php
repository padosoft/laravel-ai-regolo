<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo;

use Illuminate\Support\ServiceProvider;

/**
 * LaravelAiRegoloServiceProvider — skeleton service provider for v0.0.1 scaffold.
 *
 * Implementation will follow during v4.0 development. For now this is
 * an empty no-op so Laravel package auto-discovery does not fail with
 * "Class not found" when a host application requires the package via
 * a path repository.
 *
 * Scope (Padosoft v4.0 W2):
 *  - Adds Seeweb Regolo as a provider for the official laravel/ai SDK
 *    (chat, embeddings, reranking, ReAct-style tool calls)
 *  - Adds the Regolo open-model catalog (30+ models not covered by the
 *    standard providers in laravel/ai)
 *  - Adds Ollama (local + Cloud) as a provider
 *
 * This package is an EXTENSION of the official laravel/ai SDK, not a
 * replacement. Users register laravel/ai for OpenAI/Anthropic/Gemini/
 * OpenRouter/Mistral/Groq/Cohere/Perplexity/Workers AI, then add this
 * package to also use Regolo + Ollama through the same unified API.
 */
final class LaravelAiRegoloServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bindings will be added during v4.0 development.
    }

    public function boot(): void
    {
        // Bootstrapping will be added during v4.0 development.
    }
}
