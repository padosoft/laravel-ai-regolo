# Docmd Docs Sync

When package behavior, configuration defaults, supported Regolo capabilities, test strategy, or README usage examples change, update `docs-site/` in the same branch.

Required checks for docs changes:

- `cd docs-site && npm run check`
- `cd docs-site && npm run build`

Keep `docs-site/docmd.config.json` navigation complete. Do not introduce raw HTML, MDX, JSX, or `::: button` containers.
