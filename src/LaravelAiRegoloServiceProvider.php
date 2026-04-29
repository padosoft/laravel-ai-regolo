<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiManager;
use Padosoft\LaravelAiRegolo\Providers\RegoloProvider;

/**
 * Service provider that registers the Regolo provider with the
 * official `laravel/ai` SDK.
 *
 * Registration is done via `Ai::extend('regolo', $callback)` —
 * `laravel/ai`'s `AiManager` extends Laravel's `MultipleInstanceManager`
 * and resolves drivers in two ways:
 *
 *   1. By calling a `create<DriverName>Driver(array $config)` method
 *      on the manager (the path used by every built-in provider:
 *      `createOpenaiDriver`, `createMistralDriver`, ...).
 *   2. By looking up the driver in the `customCreators` map populated
 *      via `extend()`. Calls to a `customCreator` pass `($app, $config)`,
 *      and the closure has `$this` rebound to the manager — so
 *      `$this->app->make(...)` works inside the callback.
 *
 * A simple `$this->app->bind('ai.provider.regolo', ...)` is **not**
 * sufficient: `MultipleInstanceManager` does not look at container
 * bindings to resolve drivers. Without `Ai::extend()` the SDK throws
 * `InvalidArgumentException: Instance driver [regolo] is not supported.`
 * on every call.
 *
 * Ollama is intentionally NOT registered here — `laravel/ai` ships a
 * first-class `Laravel\Ai\Providers\OllamaProvider` out of the box, so
 * adding our own would shadow the upstream and break compatibility
 * when the upstream is updated. Users who want Ollama configure it via
 * `config/ai.php` directly against the SDK's built-in driver.
 */
final class LaravelAiRegoloServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No-op at register-time. The `Ai::extend()` call belongs in
        // boot() because it depends on the AiManager facade being
        // resolvable, which requires the upstream `laravel/ai` service
        // provider to have already registered (boot order).
    }

    public function boot(): void
    {
        $this->app->make(AiManager::class)->extend('regolo', function ($app, array $config) {
            return new RegoloProvider(
                config: $config,
                events: $app->make(Dispatcher::class),
            );
        });
    }
}
