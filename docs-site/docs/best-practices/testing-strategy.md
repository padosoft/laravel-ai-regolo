---
title: Testing Strategy
description: Test the provider with offline units and opt-in live checks.
---

# Testing Strategy

The repository uses offline unit tests by default and opt-in live tests for real Regolo compatibility.

## Offline first

Run the normal suite without a Regolo key:

```bash
composer install
vendor/bin/phpunit
```

The unit suite fakes HTTP and covers chat, streaming, embeddings, reranking, image, audio, transcription, timeout fallback, and provider binding behavior.

## Live only when intentional

```bash
$env:REGOLO_API_KEY = "rg_live_..."
vendor/bin/phpunit --testsuite Live
```

:::warning
Live tests call the real API. Keep them out of normal pull-request CI unless the workflow is manual and credentials are scoped for that purpose.
:::
