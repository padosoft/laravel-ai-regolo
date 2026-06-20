---
title: Environment
description: Environment variables used by the provider and live tests.
---

# Environment

## Application variables

| Variable | Required | Purpose |
| --- | --- | --- |
| `REGOLO_API_KEY` | yes | Bearer token for Regolo. |
| `REGOLO_BASE_URL` | no | Override API base URL. |
| `AI_DEFAULT_TEXT` | no | Default Laravel AI text provider. |
| `AI_DEFAULT_EMBEDDINGS` | no | Default embedding provider. |
| `AI_DEFAULT_RERANKING` | no | Default reranking provider. |
| `REGOLO_IMAGE_MODEL` | no | Image model override. |
| `REGOLO_TRANSCRIPTION_MODEL` | no | Transcription model override. |
| `REGOLO_AUDIO_MODEL` | no | Text-to-speech model id. |

## Live test variables

| Variable | Purpose |
| --- | --- |
| `REGOLO_LIVE_TEXT_MODEL` | Override live chat model. |
| `REGOLO_LIVE_EMBEDDINGS_MODEL` | Override live embedding model. |
| `REGOLO_LIVE_RERANKING_MODEL` | Override live reranking model. |
| `REGOLO_LIVE_IMAGE_MODEL` | Override live image model. |
| `REGOLO_LIVE_AUDIO_MODEL` | Enable live TTS test. |
| `REGOLO_LIVE_TRANSCRIPTION_AUDIO_PATH` | Enable live transcription test. |
| `REGOLO_LIVE_TIMEOUT` | Override live request timeout. |
