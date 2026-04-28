# Test coverage map: `padosoft/laravel-ai-regolo` vs `regolo-ai/python-client`

> **Goal**: PHP package test suite must (a) cover EVERY scenario the official Python SDK covers, AND (b) add a more-robust layer on top — race conditions, retries, malformed responses, streaming, auth failures, rate limits.

## Source: Regolo Python SDK test surface (audit 2026-04-28)

Repo: [regolo-ai/python-client](https://github.com/regolo-ai/python-client) — single test file `tests/test.py` (3,832 bytes).

### Active Python tests (7)

| # | Python test | Type | Mocks? | Asserts |
|---|---|---|---|---|
| 1 | `test_completions` | text completion | ✅ MagicMock | response equals mocked string |
| 2 | `test_chat_completions` | chat completion | ✅ MagicMock | response equals mocked string |
| 3 | `test_static_completions` | text completion | ❌ live API | response is `str` |
| 4 | `test_static_chat_completions` | chat completion | ❌ live API | response is `tuple` |
| 5 | `test_static_image_create` | image generation | ❌ live API | response decodes as PIL Image |
| 6 | `test_static_embeddings` | embeddings | ❌ live API | response is `list` |
| 7 | `test_rerank` | reranking | ✅ MagicMock | response equals mocked list |

### Commented-out Python tests (1)

| # | Python test | Status |
|---|---|---|
| 8 | `test_audio_transcriptions` | commented out in source — not active |

### Default models declared in Python tests

- chat: `Llama-3.1-8B-Instruct`
- image: `Qwen-Image`
- embeddings: `Qwen3-Embedding-8B`
- reranker: `jina-reranker-v2`

### Coverage gaps in the Python SDK

The Python suite is happy-path only. It does NOT cover:

- Streaming (SSE)
- Auth failures (`401`, expired key)
- Server errors with retry (`5xx` → eventual success)
- Permanent failures (`5xx` no retry)
- Timeout
- Rate limit (`429`)
- Malformed JSON response
- Model-not-available errors
- Multi-turn chat history maintenance
- Embedding batch with multiple inputs
- Empty / Unicode / very-long input edge cases
- Reranking with empty / single / many documents, `top_n` boundary
- Embedding dimension consistency across calls
- Concurrent requests (thread / fiber safety)

---

## PHP Port plan

### v0.1 IN-SCOPE features → tests

These are the 4 inference areas implemented in `padosoft/laravel-ai-regolo` v0.1:

#### 1. Chat / completions

**Python parity tests** (port the 4 chat-related Python tests):

- `RegoloDriverTest::test_chat_returns_string_response_via_mock` (= Python `test_completions` happy-path)
- `RegoloDriverTest::test_chat_returns_string_response_via_http_fake` (= Python `test_chat_completions` with `Http::fake()` instead of MagicMock)
- `RegoloDriverTest::test_static_completion_call_via_http_fake` (= Python `test_static_completions`)
- `RegoloDriverTest::test_static_chat_completions_via_http_fake` (= Python `test_static_chat_completions`)

**Robustness tests** (added on top):

- `test_chat_streams_token_chunks_via_sse_fake` — SSE streaming, asserts chunk sequence
- `test_chat_5xx_retries_with_backoff_then_succeeds`
- `test_chat_4xx_throws_no_retry`
- `test_chat_401_throws_invalid_key_exception`
- `test_chat_429_respects_retry_after_header`
- `test_chat_timeout_throws_after_configured_seconds`
- `test_chat_malformed_json_response_throws_specific_exception`
- `test_chat_multi_turn_preserves_message_history`
- `test_chat_model_switch_via_change_model_keeps_conversation`
- `test_chat_clear_conversation_resets_history`
- `test_chat_default_model_via_config`
- `test_chat_unicode_prompt_round_trips`
- `test_chat_very_long_prompt_does_not_truncate_silently`
- `test_chat_concurrent_requests_isolated_state`

#### 2. Embeddings

**Python parity test**:

- `RegoloDriverTest::test_embed_batch_returns_list` (= Python `test_static_embeddings`)

**Robustness tests**:

- `test_embed_single_input_returns_list_of_one`
- `test_embed_empty_input_throws_validation`
- `test_embed_dimension_matches_model_metadata`
- `test_embed_dimension_consistent_across_calls`
- `test_embed_batch_size_boundary_handles_max_inputs`
- `test_embed_uses_default_model_from_config`
- `test_embed_unicode_input_handled`
- `test_embed_5xx_retries_then_succeeds`
- `test_embed_4xx_throws_no_retry`
- `test_embed_timeout`

#### 3. Reranking

**Python parity test**:

- `RegoloDriverTest::test_rerank_returns_scored_list` (= Python `test_rerank` happy-path)

**Robustness tests**:

- `test_rerank_empty_documents_returns_empty_list`
- `test_rerank_single_document_returns_list_of_one`
- `test_rerank_top_n_boundary_returns_at_most_top_n`
- `test_rerank_top_n_zero_returns_empty`
- `test_rerank_top_n_greater_than_doc_count_returns_all`
- `test_rerank_default_model_from_config`
- `test_rerank_relevance_scores_descending`
- `test_rerank_index_field_matches_input_position`
- `test_rerank_5xx_retries_then_succeeds`
- `test_rerank_4xx_throws_no_retry`

### v0.1 OUT-OF-SCOPE — tracked for v0.2

Image generation and audio transcription will land in v0.2. When that happens, port:

- Python `test_static_image_create` → `RegoloImageDriverTest::test_create_image_returns_binary`
- Python `test_audio_transcriptions` (uncomment) → `RegoloAudioDriverTest::test_audio_transcribe_returns_dict`

Plus equivalent robustness scenarios (5xx retry, malformed binary, etc.).

### Model management API (separate concern)

The Python SDK exposes model management endpoints (`register_model`, `load_model_for_inference`, `get_loaded_models`, GPU resources, billing, SSH key management). These are **out of scope** for `padosoft/laravel-ai-regolo` — they're CLI / admin tooling for Regolo customers, not for application developers consuming the inference API.

If demand arises, a separate package `padosoft/regolo-admin-cli` would be the right home.

---

## Test framework + style

- **Framework**: PHPUnit 11+/12+ (CI matrix already runs PHP 8.3 + 8.4 + 8.5)
- **HTTP fake**: `Http::fake()` from `illuminate/http` — never real network
- **Test data**: `tests/Fixtures/` for canned responses (chat completions, embeddings, rerank, SSE chunks)
- **Naming**: every test name describes the BEHAVIOUR, not the method (R16: test-actually-tests-what-it-claims)
- **Cross-reference**: every test that is a port of a Python test carries a comment `// Port of Python: regolo-ai/python-client tests/test.py::test_<name>`

## CI gate

When the suite is implemented, add to `.github/workflows/ci.yml`:

```yaml
- name: Coverage parity check
  run: |
    php_count=$(grep -rh '^\s*public function test_' tests/ | wc -l)
    py_count=7  # active tests in tests/test.py at upstream commit <sha>
    if [ "$php_count" -lt "$py_count" ]; then
      echo "PHP suite has fewer test methods ($php_count) than Python upstream ($py_count). Port more."
      exit 1
    fi
```

Refresh the `py_count` value when Regolo updates their suite (vendor an upstream-commit pointer at the top of this file).

## Upstream commit tracked

- Repo: [regolo-ai/python-client](https://github.com/regolo-ai/python-client)
- Audit date: 2026-04-28
- Test file analysed: `tests/test.py` (size 3,832 B, sha `a868520d4e0ea9bb1de8138fe28a82f8416cae5c`)
- README method surface: chat (7 methods), image (2), audio (2), embeddings (2), rerank (2), model management (~9)

When the upstream Python SDK ships new tests, refresh this doc and port them.
