<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\EventListener;

use Lunetics\LlmCostTrackingBundle\Service\CostTrackerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

final class CostLoggerListener
{
    public function __construct(
        private readonly CostTrackerInterface $costTracker,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $snapshot = $this->costTracker->getSnapshot();

        if (0 === $snapshot->totals->calls) {
            return;
        }

        foreach ($snapshot->calls as $call) {
            $this->logger->info(
                sprintf(
                    'LLM call: %s (%s) — %d in, %d out, %d total tokens — $%f',
                    $call->displayName,
                    $call->provider,
                    $call->inputTokens,
                    $call->outputTokens,
                    $call->totalTokens,
                    $call->cost,
                ),
                [
                    'model' => $call->model,
                    'provider' => $call->provider,
                    'input_tokens' => $call->inputTokens,
                    'output_tokens' => $call->outputTokens,
                    'total_tokens' => $call->totalTokens,
                    'thinking_tokens' => $call->thinkingTokens,
                    'cached_tokens' => $call->cachedTokens,
                    'cost' => $call->cost,
                ],
            );
        }

        $this->logger->info(
            sprintf(
                'LLM cost summary: %d calls, $%f total — %d in, %d out, %d total tokens',
                $snapshot->totals->calls,
                $snapshot->totals->cost,
                $snapshot->totals->inputTokens,
                $snapshot->totals->outputTokens,
                $snapshot->totals->totalTokens,
            ),
            [
                'calls' => $snapshot->totals->calls,
                'total_cost' => $snapshot->totals->cost,
                'input_tokens' => $snapshot->totals->inputTokens,
                'output_tokens' => $snapshot->totals->outputTokens,
                'total_tokens' => $snapshot->totals->totalTokens,
            ],
        );

        if ([] !== $snapshot->unconfiguredModels) {
            $this->logger->warning(
                sprintf('LLM models without pricing: %s', implode(', ', $snapshot->unconfiguredModels)),
                ['models' => $snapshot->unconfiguredModels],
            );
        }
    }
}
