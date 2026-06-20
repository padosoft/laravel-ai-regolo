---
title: Models
description: Default Regolo model ids documented by the package.
---

# Models

Model availability is controlled by Regolo's catalog and your account access. These are the package defaults documented in the README.

| Capability | Default model |
| --- | --- |
| Text default | `Llama-3.1-8B-Instruct` |
| Text cheapest | `Llama-3.1-8B-Instruct` |
| Text smartest | `Llama-3.3-70B-Instruct` |
| Embeddings | `Qwen3-Embedding-8B` |
| Reranking | `Qwen3-Reranker-4B` |
| Image | `Qwen-Image` |
| Transcription | `faster-whisper-large-v3` |
| Audio | Explicit `REGOLO_AUDIO_MODEL` |

:::note
Treat model ids as operational configuration. Keep them easy to override per environment.
:::
