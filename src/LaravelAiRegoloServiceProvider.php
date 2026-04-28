<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo;

use Illuminate\Support\ServiceProvider;
use Padosoft\LaravelAiRegolo\Providers\RegoloProvider;

/**
 * Service provider that registers the Regolo provider with the
 * official `laravel/ai` SDK.
 *
 * Ollama is intentionally NOT registered here — `laravel/ai` ships
 * a first-class `Laravel\Ai\Providers\OllamaProvider` out of the box,
 * so adding our own would shadow the upstream and break compatibility
 * when the upstream is updated. Users who want Ollama configure it
 * via `config/ai.php` directly against the SDK's built-in driver.
 *
 * The binding key `ai.provider.regolo` is what the SDK resolves when
 * the application calls `Agent::prompt(...)`, `Embeddings::for(...)`,
 * or `Reranking::of(...)` with a `Lab::Custom('regolo')` argument or
 * a `regolo` default declared in `config/ai.php`.
 */
final class LaravelAiRegoloServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('ai.provider.regolo', function ($app) {
            return new RegoloProvider(
                config: (array) config('ai.providers.regolo', []),
                events: $app->make('events'),
            );
        });
    }

    public function boot(): void
    {
        // Configuration publishing is intentionally NOT exposed here —
        // `config/ai.php` is owned by `laravel/ai` and our provider
        // simply slots into the existing `providers` array. Users are
        // expected to declare the `regolo` entry directly in their
        // application's published copy of `config/ai.php`. See README
        // for the canonical config snippet.
    }
}
