# LuneticsLlmCostTrackingBundle

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
- **Extensible** — implement `CostCalculatorInterface` to provide custom pricing logic

## Model Pricing

The bundle resolves pricing for a model using the following priority order:

1. **Your YAML config** — `models:` entries take precedence over everything else
2. **Bundle defaults** — a curated list of common OpenAI, Anthropic, and Google models ships with the bundle
3. **Dynamic pricing from models.dev** — for any model not found above, the bundle fetches live pricing from [models.dev](https://models.dev) and caches it for 24 hours
4. **Not found** — cost is shown as zero with a warning in the profiler

This means most models work out of the box with no configuration. Your own entries always win.

> **All prices are in USD.** The bundle defaults, the models.dev feed, and any prices you configure are all treated as USD. There is no currency conversion; the `$` prefix shown in the profiler is a literal dollar sign.

## Configuration

```yaml
# config/packages/lunetics_llm_cost_tracking.yaml
lunetics_llm_cost_tracking:
    budget_warning: 0.50          # toolbar turns red when exceeded
    cost_thresholds:
        low: 0.01                 # below = green
        medium: 0.10              # between low/medium = yellow, above = red
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

### Disabling Dynamic Pricing

If you want fully offline/air-gapped operation, or prefer explicit control over every model's price:

```yaml
lunetics_llm_cost_tracking:
    dynamic_pricing:
        enabled: false
```

When disabled, only bundle defaults and your `models:` config are used. The `lunetics:llm:update-pricing` command is also removed from the container.

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

## How It Works

The bundle collects data from all services tagged with `ai.traceable_platform` (provided by symfony/ai-bundle). After each request, `LlmCostCollector` iterates over all recorded LLM calls, extracts token usage metadata, and calculates costs using the configured model pricing.

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
make install    # Install dependencies
make test       # Run PHPUnit tests
make phpstan    # Run PHPStan (level 8)
make cs-check   # Check coding standards
make cs-fix     # Fix coding standards
make ci         # Run all checks
```

Override the PHP version with `PHP_VERSION=8.2 make test`.

## License

MIT License. See [LICENSE](LICENSE) for details.
