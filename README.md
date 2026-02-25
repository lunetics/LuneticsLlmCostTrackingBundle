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
- **Injectable cost tracking** — use `CostTrackerInterface` in your own services to access cost data outside the profiler
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

## Integration Examples

This bundle works with any platform supported by [symfony/ai-bundle](https://github.com/symfony/ai-bundle). Below are complete configuration examples for the three major providers. Each example shows the symfony/ai-bundle platform config, the cost tracking config, and a controller that calls the LLM.

> **The key link:** the model string passed to `$platform->invoke()` (e.g. `'gpt-5'`) is the same string the bundle uses to look up pricing. Most models are already covered by the bundle defaults or dynamic pricing from models.dev — you only need a `models:` entry for custom or self-hosted models.

### OpenAI

```bash
composer require symfony/ai-openai-platform
```

```yaml
# config/packages/symfony_ai.yaml
symfony_ai:
    platform:
        openai:
            api_key: '%env(OPENAI_API_KEY)%'

# config/packages/lunetics_llm_cost_tracking.yaml
lunetics_llm_cost_tracking:
    budget_warning: 1.00
```

```php
// src/Controller/ChatController.php
namespace App\Controller;

use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ChatController extends AbstractController
{
    #[Route('/chat')]
    public function chat(PlatformInterface $platform): Response
    {
        // The model string 'gpt-5' is matched against the pricing registry
        $result = $platform->invoke('gpt-5', 'Explain Symfony in one sentence.');

        return $this->render('chat.html.twig', [
            'answer' => $result->getResult()->getContent(),
        ]);
    }
}
```

The bundle ships with default pricing for `gpt-5`, `gpt-5-mini`, `gpt-4o-mini`, and `gpt-4.1-mini`. Any other OpenAI model is resolved automatically via models.dev.

### Anthropic

```bash
composer require symfony/ai-anthropic-platform
```

```yaml
# config/packages/symfony_ai.yaml
symfony_ai:
    platform:
        anthropic:
            api_key: '%env(ANTHROPIC_API_KEY)%'

# config/packages/lunetics_llm_cost_tracking.yaml
lunetics_llm_cost_tracking:
    budget_warning: 0.50
```

```php
$result = $platform->invoke('claude-sonnet-4-6', 'Explain dependency injection.');
```

Default pricing is included for `claude-sonnet-4-6` and `claude-opus-4-6`, with support for cached input and thinking tokens. Other Anthropic models are resolved via models.dev.

### Google Gemini

```bash
composer require symfony/ai-gemini-platform
```

```yaml
# config/packages/symfony_ai.yaml
symfony_ai:
    platform:
        gemini:
            api_key: '%env(GOOGLE_GEMINI_API_KEY)%'

# config/packages/lunetics_llm_cost_tracking.yaml
lunetics_llm_cost_tracking:
    budget_warning: 0.25
```

```php
$result = $platform->invoke('gemini-2.5-flash', 'Summarize this document.');
```

Default pricing is included for `gemini-2.5-flash`, `gemini-2.5-pro`, `gemini-3-flash-preview`, and `gemini-3-pro-preview`.

### Multiple Providers

You can configure multiple providers simultaneously. Each platform registers its own `TraceablePlatform` in debug mode, and the cost tracker aggregates calls from all of them:

```yaml
# config/packages/symfony_ai.yaml
symfony_ai:
    platform:
        openai:
            api_key: '%env(OPENAI_API_KEY)%'
        anthropic:
            api_key: '%env(ANTHROPIC_API_KEY)%'
        gemini:
            api_key: '%env(GOOGLE_GEMINI_API_KEY)%'
```

When multiple platforms are configured, inject them by name instead of the generic `PlatformInterface`:

```php
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class AiService
{
    public function __construct(
        #[Autowire(service: 'ai.platform.openai')]
        private readonly PlatformInterface $openai,
        #[Autowire(service: 'ai.platform.anthropic')]
        private readonly PlatformInterface $anthropic,
    ) {}

    public function summarize(string $text): string
    {
        // Both calls are tracked and priced automatically
        $result = $this->openai->invoke('gpt-5', $text);

        return $result->getResult()->getContent();
    }
}
```

The profiler panel shows costs per model regardless of which platform the call went through.

### Overriding Pricing for a Specific Model

If you use a model that isn't in the defaults or on models.dev (e.g. a fine-tuned or self-hosted model), add it to your config:

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
