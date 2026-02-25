# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Monolog cost logging** (`logging` config key, enabled by default). On every request that
  makes at least one AI call the bundle now logs per-call detail and a summary on
  `kernel.terminate` via the `ai` Monolog channel. Disable with `logging.enabled: false`;
  change the channel with `logging.channel: 'your-channel'`.

  > **Upgrade note:** logging is enabled by default. If you do not want production log output,
  > add `logging: { enabled: false }` to your `lunetics_llm_cost_tracking` config.

- **Snapshot pricing baseline** — a bundled `resources/pricing_snapshot.json` now serves as
  an always-on fallback. When `dynamic_pricing.enabled: true` (default), live API data takes
  priority and the snapshot fills any gaps. When `dynamic_pricing.enabled: false`, the snapshot
  is the sole pricing source (no live HTTP calls). User-configured `models:` always override both.

### Changed
- **`dynamic_pricing.enabled: false` now uses snapshot baseline** instead of providing no
  fallback at all. Models not in your `models:` config will now resolve from the bundled
  snapshot rather than returning `null`.

### Breaking Changes
- **`RefreshablePricingProviderInterface`** gains a new required method `fetchLive(): array`.
  Any custom implementation of this interface must add the method. It should fetch directly
  from the live source, throw on failure, and never fall back to cached or snapshot data.

## [0.1.3] - 2026-02-25

### Changed
- Updated CHANGELOG with entries for v0.1.1 and v0.1.2

## [0.1.2] - 2026-02-25

### Fixed
- Widened `symfony/ai-bundle` and `symfony/ai-platform` constraints from `^0.4|^1.0` to
  `>=0.4.0 <2.0.0`. The caret operator on pre-1.0 versions locks the minor digit
  (`^0.4` = `>=0.4.0 <0.5.0`), leaving a gap where 0.5.x through 0.9.x would not
  satisfy the constraint and cause installation to fail.

## [0.1.1] - 2026-02-25

### Fixed
- Fixed `cache:clear` crash in Symfony dev environment (`kernel.debug=true`). Symfony's
  `XmlDumper` (used by `ContainerBuilderDebugDumpPass`) cannot serialize plain PHP object
  instances as service constructor arguments. Replaced direct `new ModelDefinition(...)` and
  `new CostThresholds(...)` instantiation with `Symfony\Component\DependencyInjection\Definition`
  objects using named `setArgument()` calls, so the container compiler receives serializable
  metadata rather than live object instances.

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

[Unreleased]: https://github.com/lunetics/llm-cost-tracking-bundle/compare/v0.1.3...HEAD
[0.1.3]: https://github.com/lunetics/llm-cost-tracking-bundle/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/lunetics/llm-cost-tracking-bundle/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/lunetics/llm-cost-tracking-bundle/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/lunetics/llm-cost-tracking-bundle/releases/tag/v0.1.0
