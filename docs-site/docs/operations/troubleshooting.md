---
title: Troubleshooting
description: Diagnose common installation, configuration, and runtime issues.
---

# Troubleshooting

## Provider cannot be resolved

Check Composer autoload and package discovery:

```bash
composer dump-autoload
php artisan package:discover
```

Verify `config/ai.php` contains a provider with `driver => 'regolo'`.

## Unauthorized

Confirm the key is present in the running process:

```bash
php -r "var_dump((bool) getenv('REGOLO_API_KEY'));"
```

If the app uses config cache, rebuild it:

```bash
php artisan config:clear
php artisan config:cache
```

## Empty or irrelevant RAG answers

- Check embedding model and vector dimension.
- Rebuild the vector index after model changes.
- Log reranked document ids before final chat.
- Keep final context under the selected model's practical context budget.

:::warning
If reranking returns good sources but chat answers poorly, debug the final prompt. If reranking returns poor sources, debug retrieval and candidate filters first.
:::
