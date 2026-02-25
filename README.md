# LuneticsLlmCostTrackingBundle

[![CI](https://github.com/lunetics/llm-cost-tracking-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/lunetics/llm-cost-tracking-bundle/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/lunetics/llm-cost-tracking-bundle/graph/badge.svg)](https://codecov.io/gh/lunetics/llm-cost-tracking-bundle)
[![Latest Stable Version](https://poser.pugx.org/lunetics/llm-cost-tracking-bundle/v/stable)](https://packagist.org/packages/lunetics/llm-cost-tracking-bundle)
[![Total Downloads](https://poser.pugx.org/lunetics/llm-cost-tracking-bundle/downloads)](https://packagist.org/packages/lunetics/llm-cost-tracking-bundle)
[![License](https://poser.pugx.org/lunetics/llm-cost-tracking-bundle/license)](https://packagist.org/packages/lunetics/llm-cost-tracking-bundle)

A Symfony bundle that tracks LLM API costs and displays them in the Web Debug Toolbar and Profiler.

Hooks into [symfony/ai-bundle](https://github.com/symfony/ai-bundle)'s `TraceablePlatform` to calculate per-request costs based on token usage, with support for input, output, cached, and thinking tokens.

## Requirements

- PHP >= 8.2
- Symfony >= 7.0
- symfony/ai-bundle >= 0.4

## Installation

```bash
composer require lunetics/llm-cost-tracking-bundle
```

If you are not using [Symfony Flex](https://github.com/symfony/flex), register the bundle manually:

```php
// config/bundles.php
return [
    // ...
    Lunetics\LlmCostTrackingBundle\LuneticsLlmCostTrackingBundle::class => ['all' => true],
];
```

## Features

- **Web Debug Toolbar** — shows total cost and call count for the current request
- **Profiler Panel** — per-call breakdown with model, tokens, and cost
- **Per-model aggregation** — costs grouped by model with totals
- **Budget warnings** — toolbar alerts when costs exceed a configurable threshold
- **Color-coded costs** — green/yellow/red based on configurable thresholds
- **Dynamic pricing** — automatically fetches live model pricing from [models.dev](https://models.dev), covering hundreds of models without any manual configuration
- **Unconfigured model detection** — warns when a model has no pricing data
- **Injectable cost tracking** — use `CostTrackerInterface` in your own services to access cost data outside the profiler
- **Extensible** — implement `CostCalculatorInterface` to provide custom pricing logic

## Model Pricing

The bundle resolves pricing for a model using the following priority order:

1. **Your YAML config** — `models:` entries take precedence over everything else
2. **Dynamic pricing from models.dev** — the bundle fetches live pricing from [models.dev](https://models.dev) (thousands of models) and caches it for 24 hours. When models.dev is unreachable, a bundled snapshot of ~3000 models is used as a fallback so known models are still priced correctly during outages
3. **Not found** — cost is shown as zero with a warning in the profiler

This means most models work out of the box with no configuration. Your own entries always win.

> **All prices are in USD.** The models.dev feed and any prices you configure are all treated as USD. There is no currency conversion; the `$` prefix shown in the profiler is a literal dollar sign.

## Configuration

```yaml
# config/packages/lunetics_llm_cost_tracking.yaml
lunetics_llm_cost_tracking:
    budget_warning: 0.50          # toolbar turns red when exceeded
    cost_thresholds:
        low: 0.01                 # below = green
        medium: 0.10              # between low/medium = yellow, above = red
    logging:
        enabled: false            # default: false — opt in to log per-request cost data via Monolog
        channel: 'ai'             # Monolog channel (default: 'ai'); route it via your handlers
    dynamic_pricing:
        enabled: true             # default: true — fetch live pricing from models.dev
        ttl: 86400                # cache duration in seconds (default: 24h, max: 7 days)
    models:
        my-custom-model:
            display_name: 'My Custom Model'
            provider: 'MyProvider'
            input_price_per_million: 1.00
            output_price_per_million: 5.00
            cached_input_price_per_million: 0.10   # optional
            thinking_price_per_million: 5.00       # optional
```

### Logging

Logging is **disabled by default**. Enable it explicitly to have per-request LLM cost data written via Monolog:

```yaml
lunetics_llm_cost_tracking:
    logging:
        enabled: true
```

Each request that makes at least one AI call produces:

- one `info` log per call (model, provider, token breakdown, cost)
- one `info` summary log (total calls, cost, tokens)
- one `warning` if any models lack pricing configuration

Logs are emitted on `kernel.terminate` — after the response is sent — so there is no latency impact.

To route AI cost logs to a dedicated file, configure a Monolog handler for the `ai` channel:

```yaml
# config/packages/monolog.yaml
monolog:
    channels: [ai]
    handlers:
        ai_costs:
            type: stream
            path: '%kernel.logs_dir%/ai_costs.log'
            channels: [ai]
```

If the `ai` channel is not explicitly configured, logs fall through to your default handler.

To use a different channel name:

```yaml
lunetics_llm_cost_tracking:
    logging:
        channel: 'llm'
```

### Disabling Dynamic Pricing

If you want fully offline/air-gapped operation, or prefer explicit control over every model's price:

```yaml
lunetics_llm_cost_tracking:
    dynamic_pricing:
        enabled: false
```

When disabled, only your `models:` config is used — no bundled snapshot and no live fetching. The `lunetics:llm:update-pricing` command is also removed from the container. This is the right choice for fully air-gapped environments where you manage all model pricing explicitly.

> **Upgrade note:** before v0.3, the bundle shipped a small static list of bundled defaults (gpt-5, claude-sonnet-4-6, etc.) that were available even when dynamic pricing was disabled. Those defaults have been removed. If you had `dynamic_pricing.enabled: false` and relied on models resolving without explicit `models:` config, add those models to your YAML configuration.

### Adjusting the Cache TTL

The dynamic pricing response is cached to avoid unnecessary HTTP requests on every page load. The default TTL is 24 hours. To refresh more or less frequently:

```yaml
lunetics_llm_cost_tracking:
    dynamic_pricing:
        ttl: 3600    # 1 hour
```

Minimum: 1 second. Maximum: 604800 (7 days).

## Console Command

To manually refresh the cached pricing from models.dev:

```bash
php bin/console lunetics:llm:update-pricing
```

This clears the cache and immediately fetches fresh pricing. Add `--verbose` to see the full model table:

```bash
php bin/console lunetics:llm:update-pricing --verbose
```

The command exits with a non-zero status if the API is unreachable or returns no models, making it safe to use in deployment pipelines.

## Model Coverage

The bundle ships a versioned snapshot of the [models.dev](https://models.dev) catalogue (~3000 models across ~100 providers) as `resources/pricing_snapshot.json`. This snapshot is used as a fallback when the live API is unreachable, so pricing works correctly even in offline or air-gapped environments.

The snapshot is regenerated before each release. To regenerate it locally:

```bash
make update-snapshot
# or directly:
php bin/generate_snapshot.php
```

The model string passed to `$platform->invoke()` (e.g. `'gpt-5'`) is the same string the bundle uses to look up pricing.

### Example: OpenAI GPT

```yaml
# config/packages/symfony_ai.yaml
symfony_ai:
    platform:
        openai:
            api_key: '%env(OPENAI_API_KEY)%'
```

```php
// The model string 'gpt-5' is matched against the pricing registry
$result = $platform->invoke('gpt-5', 'Explain Symfony in one sentence.');
```

No `lunetics_llm_cost_tracking` config is needed — `gpt-5` is covered by dynamic pricing (or the bundled snapshot when offline). Costs appear automatically in the profiler toolbar.

### Overriding or Adding Model Pricing

If you use a model that isn't on models.dev (e.g. a fine-tuned or self-hosted model), add it to your config:

```yaml
lunetics_llm_cost_tracking:
    models:
        ft:gpt-5:my-finetuned-2025:
            display_name: 'My Fine-tuned GPT-5'
            provider: 'OpenAI'
            input_price_per_million: 3.00
            output_price_per_million: 15.00
```

Your `models:` entries always take precedence over bundle defaults and dynamic pricing.

## Using Cost Data in Your Services

The `CostTrackerInterface` service is available for dependency injection. Use it to access cost data outside the profiler — for example, in middleware, event listeners, or API responses:

```php
use Lunetics\LlmCostTrackingBundle\Service\CostTrackerInterface;

class MyService
{
    public function __construct(
        private readonly CostTrackerInterface $costTracker,
    ) {}

    public function logCosts(): void
    {
        $totals = $this->costTracker->getTotals();
        // $totals = ['calls' => 3, 'input_tokens' => 5000, ..., 'cost' => 0.042]

        // Or get everything in one call:
        $snapshot = $this->costTracker->getSnapshot();
        // $snapshot = ['calls' => [...], 'by_model' => [...], 'totals' => [...], 'unconfigured_models' => [...]]
    }
}
```

Available methods: `getCalls()`, `getTotals()`, `getByModel()`, `getUnconfiguredModels()`, `getSnapshot()`.

The `ModelRegistryInterface` and `CostCalculatorInterface` are also available for injection if you need lower-level access to model definitions or cost calculation.

## How It Works

The bundle collects data from all services tagged with `ai.traceable_platform` (provided by symfony/ai-bundle). The `CostTracker` service iterates over all recorded LLM calls, extracts token usage metadata, and calculates costs using the configured model pricing. Results are memoized for the lifetime of the request, so repeated calls to any getter return the same data without recomputation.

The `LlmCostCollector` (Symfony Profiler data collector) delegates to `CostTracker` via `getSnapshot()`, which returns all cost data in a single atomic call. This separation keeps business logic in a standalone service that can be injected anywhere, while the data collector focuses on profiler integration.

Cost formula per call:

```
cost = (regular_input_tokens / 1M × input_price)
     + (output_tokens / 1M × output_price)
     + (cached_tokens / 1M × cached_price)
     + (thinking_tokens / 1M × thinking_price)
```

Where `regular_input_tokens = max(0, input_tokens - cached_tokens)`.

## Development

A Docker-based Makefile is provided for local development:

```bash
make install          # Install dependencies
make test             # Run PHPUnit tests
make phpstan          # Run PHPStan (level 8)
make cs-check         # Check coding standards
make cs-fix           # Fix coding standards
make ci               # Run all checks
make update-snapshot  # Regenerate resources/pricing_snapshot.json from models.dev
```

Override the PHP version with `PHP_VERSION=8.2 make test`.

## License

MIT License. See [LICENSE](LICENSE) for details.
