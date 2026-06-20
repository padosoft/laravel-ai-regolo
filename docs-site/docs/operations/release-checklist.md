---
title: Release Checklist
description: Prepare a package release with tests, docs, and metadata.
---

# Release Checklist

Use this checklist before tagging a release.

## Code

- Run `vendor/bin/phpunit`.
- Run static analysis and formatting checks configured in CI.
- Confirm package discovery still registers `LaravelAiRegoloServiceProvider`.

## Docs

- Update README examples when public APIs change.
- Update this docmd site when configuration, defaults, or model support changes.
- Rebuild the docs site and verify `llms.txt`, `sitemap.xml`, and semantic search output.

## Release

- Review `composer.json` constraints.
- Update changelog or release notes.
- Push the branch and tag only after CI is green.
