---
title: Live Verification
description: Run real Regolo API smoke tests safely.
---

# Live Verification

Use the `Live` PHPUnit suite when validating a release, checking Seeweb compatibility, or debugging a production-only wire issue.

## Configure

PowerShell:

```powershell
$env:REGOLO_API_KEY = "rg_live_..."
$env:REGOLO_BASE_URL = "https://api.regolo.ai/v1"
```

Optional model overrides:

```powershell
$env:REGOLO_LIVE_TEXT_MODEL = "Llama-3.1-8B-Instruct"
$env:REGOLO_LIVE_EMBEDDINGS_MODEL = "Qwen3-Embedding-8B"
$env:REGOLO_LIVE_RERANKING_MODEL = "Qwen3-Reranker-4B"
```

## Run

```bash
vendor/bin/phpunit --testsuite Live --testdox
```

Expected default state with no audio model or transcription file is partial skip: text, streaming, embeddings, reranking, and image run; TTS and transcription self-skip when required environment is missing.

## Interpret

| Result | Meaning |
| --- | --- |
| All skipped | `REGOLO_API_KEY` is missing. |
| TTS skipped | `REGOLO_LIVE_AUDIO_MODEL` is not set. |
| Transcription skipped | `REGOLO_LIVE_TRANSCRIPTION_AUDIO_PATH` is not set. |
| 401 or 403 | Key, account, or model access issue. |
| 404 model | Model id mismatch with Regolo catalog. |
