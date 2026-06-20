---
name: docmd-docs
description: Maintain the public docmd documentation site in docs-site.
---

# docmd Docs

Use this skill when changing the public documentation site for this repository.

## Rules

1. Work inside `docs-site/`.
2. Keep content Markdown-only. Do not add MDX, JSX, or raw HTML to `docs-site/docs`.
3. Use docmd containers such as `:::tip`, `:::note`, `:::warning`, and `:::details`; do not use `::: button`.
4. Keep navigation in `docs-site/docmd.config.json` as the only sidebar source, and include every page.
5. Preserve semantic search configuration in `docs-site/.docmd-search/config.json`.
6. Run `npm run check` and `npm run build` from `docs-site/` before committing documentation changes.

## Expected outputs

- `_site/index.html`
- `_site/sitemap.xml`
- `_site/llms.txt`
- `_site/.docmd-search/manifest.json`
- `_site/.docmd-search/batches/`
