<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\Command;

use Lunetics\LlmCostTrackingBundle\Command\UpdatePricingCommand;
use Lunetics\LlmCostTrackingBundle\Model\ModelDefinition;
use Lunetics\LlmCostTrackingBundle\Pricing\RefreshablePricingProviderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class UpdatePricingCommandTest extends TestCase
{
    #[Test]
    public function itReportsModelCountOnSuccess(): void
    {
        $models = [
            'gpt-5' => new ModelDefinition('gpt-5', 'GPT-5', 'OpenAI', 1.25, 10.0),
            'claude-sonnet-4-6' => new ModelDefinition('claude-sonnet-4-6', 'Claude Sonnet 4.6', 'Anthropic', 3.0, 15.0),
        ];

        $tester = new CommandTester(new UpdatePricingCommand($this->createProvider($models)));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Loaded pricing for 2 models', $tester->getDisplay());
    }

    #[Test]
    public function itShowsTableInVerboseMode(): void
    {
        $models = [
            'gpt-5' => new ModelDefinition('gpt-5', 'GPT-5', 'OpenAI', 1.25, 10.0),
        ];

        $tester = new CommandTester(new UpdatePricingCommand($this->createProvider($models)));
        $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        self::assertStringContainsString('Provider', $tester->getDisplay());
        self::assertStringContainsString('Input /1M', $tester->getDisplay());
    }

    #[Test]
    public function itCallsFetchLiveNotGetModels(): void
    {
        $models = ['gpt-5' => new ModelDefinition('gpt-5', 'GPT-5', 'OpenAI', 1.25, 10.0)];

        $provider = $this->createMock(RefreshablePricingProviderInterface::class);
        $provider->expects($this->once())->method('fetchLive')->willReturn($models);
        $provider->expects($this->never())->method('getModels');
        $provider->expects($this->never())->method('invalidate');

        $tester = new CommandTester(new UpdatePricingCommand($provider));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    #[Test]
    public function itReturnsFailureWhenNoModelsLoaded(): void
    {
        $tester = new CommandTester(new UpdatePricingCommand($this->createProvider([])));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No models loaded', $tester->getDisplay());
    }

    #[Test]
    public function itReturnsFailureOnException(): void
    {
        $provider = $this->createMock(RefreshablePricingProviderInterface::class);
        $provider->method('fetchLive')->willThrowException(new \RuntimeException('Connection refused'));

        $tester = new CommandTester(new UpdatePricingCommand($provider));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Failed', $tester->getDisplay());
    }

    /** @param array<string, ModelDefinition> $models */
    private function createProvider(array $models): RefreshablePricingProviderInterface
    {
        $provider = $this->createMock(RefreshablePricingProviderInterface::class);
        $provider->method('fetchLive')->willReturn($models);

        return $provider;
    }
}
