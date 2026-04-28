# agent-llm

Laravel-native agentic LLM SDK with **6 providers** unified under a single interface.

## Features

- 6 first-class providers: **OpenAI, Anthropic, Gemini, OpenRouter, Regolo (Italian sovereign cloud), Ollama**
- Unified tool calling / function calling across providers
- Streaming SSE support
- Structured output (JSON mode) normalized
- Prompt caching where supported
- Cost tracking + per-run budget cap
- Type-safe DTOs (PHP 8.3+)

## Installation

```bash
composer require padosoft/agent-llm
```

## Quick start

```php
use Lopadova\AgentLlm\Facades\AgentLlm;

$response = AgentLlm::driver('anthropic')
    ->chat([
        ['role' => 'user', 'content' => 'Hello, world!'],
    ])
    ->withModel('claude-sonnet-4-6')
    ->execute();

echo $response->content;
```

## Documentation

See [docs/](./docs/) for full API reference.

## License

Apache-2.0 — see [LICENSE](./LICENSE).

## Status

🚧 Pre-release. v0.1.0 expected May 2026.
