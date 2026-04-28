<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo;

use Illuminate\Support\ServiceProvider;
use Padosoft\LaravelAiRegolo\Providers\OllamaProvider;
use Padosoft\LaravelAiRegolo\Providers\RegoloProvider;

/**
 * Service provider that registers Regolo + Ollama providers with the
 * official `laravel/ai` SDK.
 *
 * Both bindings expose the SDK's expected key shape
 * `ai.provider.<name>` so the SDK's resolver picks them up when the
 * application calls `Agent::prompt(...)` (or any of the capability
 * facades) with a `Lab::Custom('regolo')` / `'regolo'` argument or a
 * default declared in `config/ai.php`.
 *
 * The provider classes themselves are skeletons during W2.A.1; the
 * implementation of `prompt` / `stream` lands in W2.A.2 (Regolo) and
 * W2.A.4 (Ollama). Embeddings + reranking providers will register
 * additional bindings during W2.A.3.
 */
final class LaravelAiRegoloServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RegoloProvider::class, fn () => new RegoloProvider(
            apiKey:  (string) config('ai.providers.regolo.key', ''),
            baseUrl: (string) config('ai.providers.regolo.url', 'https://api.regolo.ai/v1'),
            timeout: (int) config('ai.providers.regolo.timeout', 60),
        ));

        $this->app->singleton(OllamaProvider::class, fn () => new OllamaProvider(
            baseUrl:  (string) config('ai.providers.ollama.url', 'http://localhost:11434'),
            cloudKey: config('ai.providers.ollama.cloud_key'),
            timeout:  (int) config('ai.providers.ollama.timeout', 60),
        ));

        $this->app->bind('ai.provider.regolo', RegoloProvider::class);
        $this->app->bind('ai.provider.ollama', OllamaProvider::class);
    }

    public function boot(): void
    {
        // Bootstrapping (config publishing, route registration) lands
        // in W2.A.5 alongside the embeddings / reranking provider
        // registrations.
    }
}
