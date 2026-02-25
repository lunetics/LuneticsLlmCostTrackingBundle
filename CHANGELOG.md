# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-02-25

### Added
- Web Debug Toolbar and Profiler Panel integration
- Per-call breakdown with model, tokens, and cost
- Per-model aggregation with totals
- Budget warnings with configurable thresholds
- Color-coded costs (green/yellow/red)
- Dynamic pricing from [models.dev](https://models.dev) with configurable cache TTL
- Bundled defaults for OpenAI, Anthropic, and Google models
- `CostTrackerInterface` for userland service injection
- `CostCalculatorInterface` for custom pricing logic
- `ModelRegistryInterface` for model definition access
- `lunetics:llm:update-pricing` console command
- Readonly DTOs: `CostSnapshot`, `CostSummary`, `ModelAggregation`, `CallRecord`

[Unreleased]: https://github.com/lunetics/llm-cost-tracking-bundle/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/lunetics/llm-cost-tracking-bundle/releases/tag/v0.1.0
