<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Command;

use Lunetics\LlmCostTrackingBundle\Pricing\RefreshablePricingProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'lunetics:llm:update-pricing',
    description: 'Refresh LLM model pricing from models.dev.',
)]
final class UpdatePricingCommand extends Command
{
    public function __construct(
        private readonly RefreshablePricingProviderInterface $pricingProvider,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Updating LLM Pricing from models.dev');

        try {
            $this->pricingProvider->invalidate();
            $models = $this->pricingProvider->getModels();

            if (0 === \count($models)) {
                $io->error('No models loaded from models.dev. The API may be unavailable or returned empty data.');

                return Command::FAILURE;
            }

            $io->success(\sprintf('Loaded pricing for %d models from models.dev.', \count($models)));

            if ($output->isVerbose()) {
                $rows = [];
                foreach ($models as $modelId => $model) {
                    $rows[] = [
                        $modelId,
                        $model->provider,
                        \sprintf('$%.4f', $model->inputPricePerMillion),
                        \sprintf('$%.4f', $model->outputPricePerMillion),
                        null !== $model->cachedInputPricePerMillion ? \sprintf('$%.4f', $model->cachedInputPricePerMillion) : '-',
                        null !== $model->thinkingPricePerMillion ? \sprintf('$%.4f', $model->thinkingPricePerMillion) : '-',
                    ];
                }
                $io->table(
                    ['Model ID', 'Provider', 'Input /1M', 'Output /1M', 'Cached /1M', 'Thinking /1M'],
                    $rows,
                );
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Failed to fetch pricing from models.dev: '.$e->getMessage());

            if ($output->isVeryVerbose()) {
                $io->writeln((string) $e);
            }

            return Command::FAILURE;
        }
    }
}
