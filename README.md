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

## Configuration

The bundle ships with default pricing for common models (OpenAI, Anthropic, Google). You can override or extend the model list:

```yaml
# config/packages/lunetics_llm_cost_tracking.yaml
lunetics_llm_cost_tracking:
    currency: 'USD'
    budget_warning: 0.50          # toolbar turns red when exceeded
    cost_thresholds:
        low: 0.01                 # below = green
        medium: 0.10              # between low and medium = yellow, above = red
    models:
        my-custom-model:
            display_name: 'My Custom Model'
            provider: 'MyProvider'
            input_price_per_million: 1.00
            output_price_per_million: 5.00
            cached_input_price_per_million: 0.10   # optional
            thinking_price_per_million: 5.00       # optional
```

## Features

- **Web Debug Toolbar** — shows total cost and call count for the current request
- **Profiler Panel** — per-call breakdown with model, tokens, and cost
- **Per-model aggregation** — costs grouped by model with totals
- **Budget warnings** — toolbar alerts when costs exceed a configurable threshold
- **Color-coded costs** — green/yellow/red based on configurable thresholds
- **Unconfigured model detection** — warns when a model has no pricing data
- **Extensible** — implement `CostCalculatorInterface` to provide custom pricing logic

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
