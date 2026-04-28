# Contributing

Thanks for your interest in contributing!

## Quick start

```bash
git clone https://github.com/<owner>/<repo>.git
cd <repo>
composer install
vendor/bin/phpunit
```

## Branching

- `main` is protected. Open a pull request from a feature branch.
- Branch naming: `feature/<short-description>` or `fix/<short-description>`.

## Commit conventions

We use conventional commits:
- `feat: new feature`
- `fix: bug fix`
- `docs: documentation only`
- `chore: tooling/maintenance`
- `test: tests only`
- `refactor: code refactor without behavior change`

## Pull requests

1. Fork the repo and create a feature branch from `main`.
2. Make your changes with tests.
3. Run `vendor/bin/phpunit` and `vendor/bin/phpstan analyse` locally.
4. Open a PR using the provided template.
5. Wait for CI to be green and a maintainer review.

## Code style

- Follow PSR-12.
- Use Laravel Pint for auto-formatting.
- Type hints on all parameters, properties, and return types.

## Tests

- Unit tests: pure components, mocked dependencies.
- Feature tests: HTTP/DB/queue integration where applicable.
- New code requires tests; aim for 85%+ coverage on new code.

## Reporting issues

Use GitHub Issues. Include:
- Steps to reproduce
- Expected vs actual behavior
- PHP version + Laravel version (if applicable)
- Any relevant logs
