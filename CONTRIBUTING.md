# Contributing

Thank you for considering contributing to LuneticsLlmCostTrackingBundle!

## Reporting Issues

Before opening an issue, please check that it hasn't already been reported. When filing a bug report, include:

- PHP version
- Symfony version
- Bundle version
- Steps to reproduce
- Expected vs. actual behavior

## Pull Requests

1. Fork the repository and create a branch from `main`
2. Run the test suite to confirm everything passes: `make ci`
3. Add tests for any new functionality
4. Follow the existing coding standards (enforced by PHP-CS-Fixer)
5. Keep PRs focused — one logical change per PR

### Development Setup

A Docker-based Makefile is provided:

```bash
make install    # Install dependencies
make test       # Run PHPUnit tests
make phpstan    # Run PHPStan (level 8)
make cs-check   # Check coding standards
make cs-fix     # Fix coding standards
make ci         # Run all checks
```

### Coding Standards

- `declare(strict_types=1)` in all PHP files
- PHPStan level 8 must pass
- PHP-CS-Fixer with the Symfony ruleset must pass
- PHPUnit 11 with `#[Test]` attributes

### Commit Messages

Use conventional commit format:

```
type: short subject

Body explaining why the change was needed.
```

Types: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`, `ci`
