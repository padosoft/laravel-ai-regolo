---
title: Security And Privacy
description: Keep credentials, prompts, and generated artifacts safe.
---

# Security And Privacy

The package helps route traffic to Regolo, but your Laravel application still owns secrets, authorization, prompt hygiene, and retention.

## Secrets

- Store `REGOLO_API_KEY` in environment or secret management.
- Never commit real keys in `.env`.
- Rotate keys when CI, preview apps, or contractor machines no longer need access.

## Prompt data

Filter tenant permissions before retrieval and reranking. Redact data that the model does not need. Keep audit logs focused on metadata unless the organization has approved prompt retention.

:::warning
Sovereign hosting reduces data-transfer risk. It does not replace authorization, minimization, encryption, or incident response.
:::

## Generated media

Image, speech, and transcription outputs can contain personal data. Store them with the same access policy as the source workflow.
