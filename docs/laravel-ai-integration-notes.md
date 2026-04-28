# Integration with the official `laravel/ai` SDK

This package extends `laravel/ai` (v0.6.4 at time of writing) with two providers — Regolo and Ollama — that are not covered by the SDK out of the box.

> Source: [packagist.org/packages/laravel/ai](https://packagist.org/packages/laravel/ai), [github.com/laravel/ai](https://github.com/laravel/ai), [laravel.com/docs/ai-sdk](https://laravel.com/docs/ai-sdk).

---

## SDK architecture (relevant portions)

### Provider architecture (corrected after source audit)

The early documentation read of `laravel/ai` suggested a single `Provider` interface with `prompt()` + `stream()`. The actual architecture (verified against `github.com/laravel/ai` `src/`) is layered:

1. **Abstract base** — `Laravel\Ai\Providers\Provider` constructor `(Gateway $gateway, array $config, Dispatcher $events)`. Provides `name()`, `driver()`, `providerCredentials()`, `additionalConfiguration()`, and a `formatProviderAndModelList()` static helper.
2. **Capability interfaces** — `src/Contracts/Providers/{TextProvider,EmbeddingProvider,RerankingProvider,AudioProvider,ImageProvider,TranscriptionProvider,FileProvider,StoreProvider}.php`. Each declares its own method (e.g. `prompt(AgentPrompt)`, `embeddings(array, ?dim, ?model, timeout)`, `rerank(array, query, ?limit, ?model)`) PLUS gateway accessor/mutator methods PLUS default-model accessors.
3. **Concern traits** — `src/Providers/Concerns/{GeneratesText,StreamsText,GeneratesEmbeddings,Reranks,GeneratesAudio,GeneratesImages,GeneratesTranscriptions,ManagesFiles,ManagesStores,Has*Gateway}.php`. The traits implement the actual behaviour by calling the gateway.
4. **Gateway classes** — `src/Gateway/{OpenAi,Anthropic,Gemini,Mistral,Ollama,...}/`. One per provider. Implement multiple `Gateway` interfaces (TextGateway, EmbeddingGateway, RerankingGateway, ...) and own all HTTP transport details.

A custom provider therefore looks like (Ollama core SDK example, paraphrased):

```php
class RegoloProvider extends Provider implements TextProvider, EmbeddingProvider, RerankingProvider
{
    use GeneratesText, StreamsText;          // implements prompt() + stream()
    use GeneratesEmbeddings;                 // implements embeddings()
    use Reranks;                             // implements rerank()
    use HasTextGateway, HasEmbeddingGateway, HasRerankingGateway;

    protected ?RegoloGateway $regoloGateway = null;

    public function __construct(protected array $config, protected Dispatcher $events) {}

    public function textGateway(): TextGateway { return $this->textGateway ??= $this->regoloGateway(); }
    public function embeddingGateway(): EmbeddingGateway { return $this->embeddingGateway ??= $this->regoloGateway(); }
    public function rerankingGateway(): RerankingGateway { return $this->rerankingGateway ??= $this->regoloGateway(); }

    public function defaultTextModel(): string { return 'Llama-3.1-8B-Instruct'; }
    // ...defaults for embeddings + reranking
}
```

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
2. **Single `RegoloProvider` class implementing all three capability interfaces** (`TextProvider` + `EmbeddingProvider` + `RerankingProvider`). One gateway (`RegoloGateway`) that implements `TextGateway` + `EmbeddingGateway` + `RerankingGateway` and owns all HTTP transport. This matches the upstream Ollama / OpenAI / Cohere shape — single binding key `ai.provider.regolo`.
3. **Ollama is dropped from this package** — `laravel/ai` ships `Laravel\Ai\Providers\OllamaProvider` and `Laravel\Ai\Gateway\Ollama\OllamaGateway` first-class. Shadowing them would create maintenance debt and break compatibility when upstream Ollama support evolves. Users who want Ollama configure it directly against the upstream driver.
4. **Transport** — `illuminate/http` only. No third-party SDK.
5. **Streaming** — yield `StreamEvent` instances from a `Generator` inside `StreamableAgentResponse` via the SDK's `StreamsText` concern. Verify that `usingVercelDataProtocol()` works out of the box for AskMyDocs's W3 frontend migration to `@ai-sdk/react`.
6. **ReAct fallback for tool calls** when the underlying Regolo open model lacks native function calling. The wrapper detects support based on the `/v1/models` metadata at startup; per-request override available via `providerOptions['toolCallStrategy' => 'react'|'native'|'auto']`.
7. **Auth** — `Bearer ${REGOLO_API_KEY}`.
8. **Defaults** match the upstream Python SDK (`Llama-3.1-8B-Instruct`, `Qwen3-Embedding-8B`, `jina-reranker-v2`) so PHP users get parity with Python users out of the box.

---

## Open questions (status)

1. ~~Capability-specific binding keys~~ — RESOLVED. The single binding `ai.provider.<name>` is enough; the SDK dispatches to whatever capability interfaces the provider class implements (TextProvider, EmbeddingProvider, RerankingProvider, etc.). Verified against `OllamaProvider` source, which implements both TextProvider + EmbeddingProvider with one binding.
2. Vercel AI SDK UI streaming protocol — TODO during W2.A.2 implementation.
3. Conversation persistence — TODO during W2.A.2 implementation.

---

## Gateway implementation reference (W2.A.2 + W2.A.3 path)

### Template selection — Mistral, NOT OpenAi (corrected after vendor source audit)

A vendor source audit on 2026-04-29 invalidated the prior assumption that `OpenAiGateway` would be the closest template for Regolo. The upstream `Laravel\Ai\Gateway\OpenAi\OpenAiGateway` posts to the **Responses API endpoint** (`POST /v1/responses`, OpenAI's newer agentic surface), not to `/v1/chat/completions`. Regolo runs the **classic** OpenAI surface (`/v1/chat/completions`), so `OpenAiGateway` is structurally misaligned.

The actual template — verified in `vendor/laravel/ai/src/Gateway/Mistral/` — is **`MistralGateway`**:

  - posts text to `chat/completions` with `body['stream'] = true` for streams
  - posts embeddings to `embeddings` (classic OpenAI shape — `{ model, input }`)
  - composes the same 7 concerns Regolo will need (`BuildsTextRequests`, `CreatesMistralClient`, `HandlesTextStreaming`, `MapsAttachments`, `MapsMessages`, `MapsTools`, `ParsesTextResponses`)
  - extends the abstract `Provider` and overrides the constructor to skip the parent gateway argument — the same pattern `RegoloProvider` already uses
  - reads provider config (api key, base URL) inside the gateway via `$provider->providerCredentials()` and `$provider->additionalConfiguration()['url']` — the gateway constructor takes only `Dispatcher $events`

Three other classic-API templates are also valid as cross-references when Mistral diverges (e.g. provider-specific response validation): `DeepSeekGateway`, `GroqGateway`, `OpenRouterGateway`. All four post to `chat/completions`, all four ship a parallel concerns directory.

For reranking, the equivalent template is `Laravel\Ai\Gateway\JinaGateway` — single file, no concerns split, posts to `/rerank` with `{ model, query, documents, top_n }` and parses Cohere-style `data.results[].relevance_score` + `data.results[].index`.

### Concerns to copy from `Mistral/Concerns/` and adapt to Regolo

| Concern | Lines | Adaptation needed |
|---|---|---|
| `BuildsTextRequests` | ~90 | Namespace + 1× `class_basename($tool)` (in MapsTools) for tool-not-supported error message — Regolo supports tools, no change. Otherwise copy verbatim. |
| `CreatesMistralClient` → `CreatesRegoloClient` | ~30 | Rename, change default base URL to `https://api.regolo.ai/v1`, keep `withToken()` Bearer auth. |
| `HandlesTextStreaming` | ~325 | Namespace only. SSE parser is OpenAI-classic-compatible. |
| `MapsAttachments` | ~78 | Namespace + provider-name in `InvalidArgumentException` ("Regolo" vs "Mistral"). |
| `MapsMessages` | ~129 | Namespace only. Chat Completions shape is identical. |
| `MapsTools` | ~59 | Namespace + provider-name in error message ("Regolo" vs "Mistral"). |
| `ParsesTextResponses` | ~325 | Namespace + provider-name in `validateTextResponse` exception ("Regolo" vs "Mistral"). |

Plus three vendor concerns we **use as-is** (no copy — the package depends on them through the public SDK contract):

  - `Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors` (HTTP error handling + structured `AiException`)
  - `Laravel\Ai\Gateway\Concerns\InvokesTools` (tool dispatch into the SDK's tool registry)
  - `Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents` (raw SSE chunk parser)

### Constructor refactor required on existing scaffold

The current `src/Gateway/Regolo/RegoloGateway.php` declares `__construct(string $apiKey, string $baseUrl, int $timeout, Dispatcher $events)`. **This must be reduced to `__construct(Dispatcher $events)`** to match the upstream pattern. All credentials and base URL are read from the `Provider` argument passed to each gateway method, via `$provider->providerCredentials()['key']` and `$provider->additionalConfiguration()['url'] ?? 'https://api.regolo.ai/v1'`.

Correspondingly, `src/Providers/RegoloProvider.php::regoloGateway()` must drop `apiKey:`, `baseUrl:`, `timeout:` from the `new RegoloGateway(...)` call and pass only `$this->events`. Timeout is the SDK's per-call argument (`$timeout` on the gateway method signature), not gateway state.

### Endpoint map (Regolo → SDK gateway method)

| SDK gateway method | HTTP endpoint | Body shape | Response DTO |
|---|---|---|---|
| `TextGateway::generateText` | `POST /v1/chat/completions` | OpenAI-compatible classic | `TextResponse` |
| `TextGateway::streamText` | `POST /v1/chat/completions` with `stream: true` + `stream_options: { include_usage: true }` | OpenAI SSE | `Generator<StreamEvent>` |
| `EmbeddingGateway::generateEmbeddings` | `POST /v1/embeddings` | OpenAI-compatible (`{ model, input }`) | `EmbeddingsResponse` |
| `RerankingGateway::rerank` | `POST /v1/rerank` | Cohere/Jina-style (`{ model, query, documents, top_n }`) | `RerankingResponse` (uses `Laravel\Ai\Responses\Data\RankedDocument`) |

### Implementation order for next session

1. Refactor `RegoloGateway` constructor: drop `apiKey/baseUrl/timeout`, keep only `Dispatcher $events`.
2. Refactor `RegoloProvider::regoloGateway()` to call `new RegoloGateway($this->events)`.
3. Create `src/Gateway/Regolo/Concerns/CreatesRegoloClient.php` (Mistral pattern, base URL `https://api.regolo.ai/v1`).
4. Copy `BuildsTextRequests`, `HandlesTextStreaming`, `MapsAttachments`, `MapsMessages`, `MapsTools`, `ParsesTextResponses` from `vendor/laravel/ai/src/Gateway/Mistral/Concerns/` into `src/Gateway/Regolo/Concerns/`. Adapt namespace + provider-name strings only.
5. Implement `RegoloGateway::generateText` — `POST chat/completions`, parse, return `TextResponse`.
6. Implement `RegoloGateway::streamText` — `POST chat/completions` with `stream: true`, yield from `processTextStream`.
7. Implement `RegoloGateway::generateEmbeddings` — `POST embeddings`, return `EmbeddingsResponse`.
8. Implement `RegoloGateway::rerank` — `POST rerank` (Jina shape), parse `data.results[]` into `RankedDocument[]`, return `RerankingResponse`.
9. Write 40 tests (4 ported from upstream Python SDK + 36 robustness; see `docs/test-coverage-vs-python-sdk.md`).
10. Open W2.A.2 PR (chat + stream subset of the 40 tests). W2.A.3 (embed + rerank) ships in a follow-up PR.
