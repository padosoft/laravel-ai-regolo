# Integration with the official `laravel/ai` SDK

This package extends `laravel/ai` (v0.6.4 at time of writing) with two providers — Regolo and Ollama — that are not covered by the SDK out of the box.

> Source: [packagist.org/packages/laravel/ai](https://packagist.org/packages/laravel/ai), [github.com/laravel/ai](https://github.com/laravel/ai), [laravel.com/docs/ai-sdk](https://laravel.com/docs/ai-sdk).

---

## SDK architecture (relevant portions)

### Provider contract

```php
namespace Laravel\Ai\Contracts;

interface Provider
{
    public function prompt(AgentPrompt $prompt): AgentResponse;
    public function stream(AgentPrompt $prompt): StreamableAgentResponse;
}
```

A provider is a class that produces an `AgentResponse` from an `AgentPrompt`. Streaming is a separate method returning an iterator-style `StreamableAgentResponse`.

### Request DTO — `AgentPrompt`

```php
namespace Laravel\Ai\Prompts;

class AgentPrompt
{
    public string  $prompt;
    public iterable $messages;        // prior conversation
    public string  $instructions;     // system prompt
    public iterable $tools;           // tool catalog
    public ?array  $schema;           // structured-output JSON schema
    public array   $attachments;
    public ?int    $maxTokens;
    public ?float  $temperature;
    public ?int    $maxSteps;
    public ?int    $timeout;
    public string  $model;
    public array   $providerOptions;  // provider-specific knobs
}
```

### Response DTO — `AgentResponse`

```php
namespace Laravel\Ai\Responses;

class AgentResponse
{
    public string  $text;
    public array   $usage;            // token counts
    public string  $stopReason;
    public array   $toolCalls;
    public ?string $conversationId;
}
```

`StreamableAgentResponse` is an `Iterator` of `StreamEvent` items, each with a `text` delta, optional `toolCall`, and optional final `usage`. It also exposes `then(callable)` for completion callbacks and `usingVercelDataProtocol()` for direct compatibility with the Vercel AI SDK UI streaming protocol.

### Capability dispatchers

The SDK ships separate facades for non-text capabilities, each with its own provider-resolution path:

| Capability | Entry point | How it dispatches |
|---|---|---|
| Chat / agent | `Laravel\Ai\Agent` (uses `Provider::prompt`) | `Lab` enum or config default |
| Embeddings | `Laravel\Ai\Embeddings::for($inputs)->generate($provider, $model)` | provider parameter or `defaults.embeddings` |
| Reranking | `Laravel\Ai\Reranking::of($docs)->rerank($query, $provider, $model)` | provider parameter or `defaults.reranking` |
| Image gen | `Laravel\Ai\Image::of($prompt)->generate($provider, $model)` | provider parameter or `defaults.image` |
| Audio TTS | `Laravel\Ai\Audio::of($text)->generate($provider, $model)` | provider parameter or `defaults.audio` |
| Transcription | `Laravel\Ai\Transcription::fromPath($p)->generate($provider, $model)` | provider parameter or `defaults.transcription` |
| Vector stores | `Laravel\Ai\Stores::create(...)` | provider managed |

The SDK does **not** expose a single mega-interface that providers implement for every capability. Instead, providers register against capabilities they support. A custom provider that handles only text + embeddings + reranking declares those bindings; the SDK falls back to `defaults.<capability>` when the resolution lands on a provider missing that capability.

### Provider registration pattern

```php
// config/ai.php
return [
    'providers' => [
        'regolo' => [
            'driver' => 'regolo',
            'key'    => env('REGOLO_API_KEY'),
            'url'    => env('REGOLO_BASE_URL', 'https://api.regolo.ai/v1'),
        ],
        'ollama' => [
            'driver' => 'ollama',
            'url'    => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            'cloud_key' => env('OLLAMA_CLOUD_KEY'),
        ],
    ],
    'defaults' => [
        'text'           => 'regolo',
        'embeddings'     => 'regolo',
        'reranking'      => 'regolo',
    ],
];
```

```php
// LaravelAiRegoloServiceProvider
public function register(): void
{
    $this->app->singleton(RegoloProvider::class, fn ($app) => new RegoloProvider(
        apiKey:  config('ai.providers.regolo.key'),
        baseUrl: config('ai.providers.regolo.url'),
    ));

    $this->app->bind('ai.provider.regolo', RegoloProvider::class);

    // Same for embeddings + reranking + ollama
}
```

The binding key `ai.provider.<name>` is what the SDK resolves when `Lab::Custom('regolo')` (or the string `'regolo'`) is passed to `generate()` / `prompt()`.

### Files / attachments

`Laravel\Ai\Files\Document` and `Image` accept multiple sources (`fromPath`, `fromUrl`, `fromString`, `fromUpload`, `fromStorage`, `fromId`). A custom provider receives them via `AgentPrompt::$attachments`. For Regolo + Ollama (text-only at v0.1), we pass through OpenAI-style multimodal payload when present and reject with a clear exception otherwise.

### Tools

```php
namespace Laravel\Ai\Contracts;

interface Tool
{
    public function description(): Stringable|string;
    public function handle(Request $request): Stringable|string;
    public function schema(JsonSchema $schema): array;
}
```

Tool calls flow:
1. The Agent includes `tools` in the `AgentPrompt`.
2. The provider formats tools to its native schema (OpenAI / Anthropic / Gemini-style).
3. The provider parses `tool_calls` out of the response into the SDK's normalized format.
4. The SDK invokes `Tool::handle($request)` with parameter binding.
5. The tool result is appended to the message stack and sent back to the provider until either a final answer is produced or `maxSteps` is reached.

For Regolo's open-model catalog, native function calling support varies. The implementation falls back to a ReAct-style prompt-formatted tool loop when the underlying model doesn't ship a tool-call schema; the prompt parser detects `Action: foo` / `Args: {...}` / `Observation: ...` blocks and rewrites them into the SDK's `toolCalls` array.

---

## Mapping — Regolo Python SDK ↔ `laravel/ai` ↔ this package

| Python SDK (`regolo` lib)              | `laravel/ai` SDK                          | This package implements |
|----------------------------------------|--------------------------------------------|--------------------------|
| `RegoloClient.completions(prompt)`     | `Agent::prompt(text:)` via Provider        | `RegoloProvider::prompt` |
| `RegoloClient.run_chat(messages)`      | `Agent::prompt(messages:)` via Provider    | `RegoloProvider::prompt` |
| `RegoloClient.chat_completions_stream` | `Provider::stream` → `StreamableAgentResponse` | `RegoloProvider::stream` |
| `RegoloClient.embeddings(input)`       | `Embeddings::for($input)->generate('regolo', $model)` | `RegoloEmbeddingsProvider::generate` |
| `RegoloClient.rerank(query, docs)`     | `Reranking::of($docs)->rerank($query, 'regolo', $model)` | `RegoloRerankingProvider::rerank` |
| `RegoloClient.create_image(prompt)`    | `Image::of($prompt)->generate('regolo', $model)` | DEFERRED to v0.2 |
| `RegoloClient.audio_transcription`     | `Transcription::from*->generate('regolo', $model)` | DEFERRED to v0.2 |
| `RegoloClient.add_prompt_to_chat`      | Conversation history maintained by Agent + `RemembersConversations` trait | n/a — handled by SDK |
| `RegoloClient.clear_conversations()`   | n/a — Agent owns history | n/a |
| `RegoloClient.change_model(m)`         | `AgentPrompt::$model` (per-request override) | n/a |

Regolo model-management endpoints (`load_model_for_inference`, `get_loaded_models`, `register_model`, GPU listing, billing) are out of scope for this package — they belong to a separate `padosoft/regolo-admin-cli` package if demand emerges.

---

## Decisions

1. **Stay in lockstep with `laravel/ai`'s public contracts.** Do not extend its DTOs; if a Regolo-specific feature does not map cleanly, expose it via `AgentPrompt::$providerOptions` and document the keys in the README.
2. **One provider class per capability** — `RegoloProvider` (text + stream), `RegoloEmbeddingsProvider`, `RegoloRerankingProvider`, plus `OllamaProvider` and `OllamaEmbeddingsProvider`. Avoids God-classes and matches the SDK's binding key shape `ai.provider.<name>` naturally.
3. **Transport** — `illuminate/http` only. No third-party SDK.
4. **Streaming** — yield `StreamEvent` instances from a `Generator` inside `StreamableAgentResponse`. Implement `usingVercelDataProtocol()` so AskMyDocs's W3 frontend migration to `@ai-sdk/react` works out of the box.
5. **ReAct fallback for tool calls** when the underlying Regolo open model lacks native function calling. The wrapper detects support based on the `/v1/models` metadata at startup; per-request override available via `providerOptions['toolCallStrategy' => 'react'|'native'|'auto']`.
6. **Auth** — `Bearer ${REGOLO_API_KEY}` for Regolo; `Bearer ${OLLAMA_CLOUD_KEY}` for Ollama Cloud; no auth for local Ollama.
7. **Defaults** match the upstream Python SDK (`Llama-3.1-8B-Instruct`, `Qwen3-Embedding-8B`, `jina-reranker-v2`) so PHP users get parity with Python users out of the box.

---

## Open questions

1. Does `laravel/ai` ship a public way to register a provider with embeddings/reranking but NOT chat? (i.e. capability-specific binding keys.) If yes, document the exact key. If no, the package registers `regolo` as a "text" provider and provides static helpers for embeddings/reranking that route directly through our provider classes.
2. Vercel AI SDK UI streaming protocol — is `usingVercelDataProtocol()` already wired to emit Server-Sent Events in the Vercel format, or does the provider have to format the chunk-frames itself? Empirical test required during W2.A.2.
3. Conversation persistence (`RemembersConversations` trait + `agent_conversations` table) — does it work cross-provider, or does each provider write/read its own row format? Empirical test required during integration.

These three items get resolved during W2.A.2 (Regolo chat driver implementation) by writing actual code against the SDK and observing the resulting behaviour.
