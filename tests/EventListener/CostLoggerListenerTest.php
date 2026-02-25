<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests\EventListener;

use Lunetics\LlmCostTrackingBundle\EventListener\CostLoggerListener;
use Lunetics\LlmCostTrackingBundle\Model\CallRecord;
use Lunetics\LlmCostTrackingBundle\Model\CostSnapshot;
use Lunetics\LlmCostTrackingBundle\Model\CostSummary;
use Lunetics\LlmCostTrackingBundle\Service\CostTrackerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class CostLoggerListenerTest extends TestCase
{
    #[Test]
    public function itDoesNotLogWhenNoCalls(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');
        $logger->expects(self::never())->method('warning');

        $listener = $this->createListener(
            snapshot: new CostSnapshot(
                calls: [],
                byModel: [],
                totals: new CostSummary(0, 0, 0, 0, 0.0),
                unconfiguredModels: [],
            ),
            logger: $logger,
        );

        $listener($this->createTerminateEvent());
    }

    #[Test]
    public function itLogsEachCallAndSummary(): void
    {
        $call = new CallRecord(
            model: 'claude-sonnet-4-6',
            displayName: 'Claude Sonnet 4.6',
            provider: 'Anthropic',
            inputTokens: 1000,
            outputTokens: 500,
            totalTokens: 1500,
            thinkingTokens: 0,
            cachedTokens: 200,
            cost: 0.00625,
        );

        $totals = new CostSummary(
            calls: 1,
            inputTokens: 1000,
            outputTokens: 500,
            totalTokens: 1500,
            cost: 0.00625,
        );

        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects(self::exactly(2))
            ->method('info')
            ->willReturnCallback(static function (string $message, array $context): void {
                static $callIndex = 0;

                if (0 === $callIndex) {
                    // Per-call log
                    self::assertStringContainsString('Claude Sonnet 4.6', $message);
                    self::assertStringContainsString('Anthropic', $message);
                    self::assertSame('claude-sonnet-4-6', $context['model']);
                    self::assertSame('Anthropic', $context['provider']);
                    self::assertSame(1000, $context['input_tokens']);
                    self::assertSame(500, $context['output_tokens']);
                    self::assertSame(1500, $context['total_tokens']);
                    self::assertSame(0, $context['thinking_tokens']);
                    self::assertSame(200, $context['cached_tokens']);
                    self::assertSame(0.00625, $context['cost']);
                } else {
                    // Summary log
                    self::assertStringContainsString('summary', $message);
                    self::assertSame(1, $context['calls']);
                    self::assertSame(0.00625, $context['total_cost']);
                    self::assertSame(1000, $context['input_tokens']);
                    self::assertSame(500, $context['output_tokens']);
                    self::assertSame(1500, $context['total_tokens']);
                }

                ++$callIndex;
            });

        $logger->expects(self::never())->method('warning');

        $listener = $this->createListener(
            snapshot: new CostSnapshot(
                calls: [$call],
                byModel: [],
                totals: $totals,
                unconfiguredModels: [],
            ),
            logger: $logger,
        );

        $listener($this->createTerminateEvent());
    }

    #[Test]
    public function itLogsWarningForUnconfiguredModels(): void
    {
        $call = new CallRecord(
            model: 'unknown-model',
            displayName: 'unknown-model',
            provider: 'Unknown',
            inputTokens: 100,
            outputTokens: 50,
            totalTokens: 150,
            thinkingTokens: 0,
            cachedTokens: 0,
            cost: 0.0,
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('info');

        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('unknown-model'),
                self::callback(static function (array $context): bool {
                    return ['unknown-model', 'other-model'] === $context['models'];
                }),
            );

        $listener = $this->createListener(
            snapshot: new CostSnapshot(
                calls: [$call],
                byModel: [],
                totals: new CostSummary(1, 100, 50, 150, 0.0),
                unconfiguredModels: ['unknown-model', 'other-model'],
            ),
            logger: $logger,
        );

        $listener($this->createTerminateEvent());
    }

    #[Test]
    public function itDoesNotLogWarningWhenAllModelsConfigured(): void
    {
        $call = new CallRecord(
            model: 'gpt-5',
            displayName: 'GPT-5',
            provider: 'OpenAI',
            inputTokens: 100,
            outputTokens: 50,
            totalTokens: 150,
            thinkingTokens: 0,
            cachedTokens: 0,
            cost: 0.001,
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('info');
        $logger->expects(self::never())->method('warning');

        $listener = $this->createListener(
            snapshot: new CostSnapshot(
                calls: [$call],
                byModel: [],
                totals: new CostSummary(1, 100, 50, 150, 0.001),
                unconfiguredModels: [],
            ),
            logger: $logger,
        );

        $listener($this->createTerminateEvent());
    }

    #[Test]
    public function itDoesNothingWhenLoggerIsNull(): void
    {
        $costTracker = $this->createMock(CostTrackerInterface::class);
        $costTracker->expects(self::never())->method('getSnapshot');

        $listener = new CostLoggerListener($costTracker);

        // Must not throw even with calls pending — logger is absent
        $listener($this->createTerminateEvent());
    }

    #[Test]
    public function itLogsOnConsoleTerminate(): void
    {
        $call = new CallRecord(
            model: 'claude-sonnet-4-6',
            displayName: 'Claude Sonnet 4.6',
            provider: 'Anthropic',
            inputTokens: 100,
            outputTokens: 50,
            totalTokens: 150,
            thinkingTokens: 0,
            cachedTokens: 0,
            cost: 0.001,
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('info');
        $logger->expects(self::never())->method('warning');

        $listener = $this->createListener(
            snapshot: new CostSnapshot(
                calls: [$call],
                byModel: [],
                totals: new CostSummary(1, 100, 50, 150, 0.001),
                unconfiguredModels: [],
            ),
            logger: $logger,
        );

        $listener(new ConsoleTerminateEvent(new Command('test'), new ArrayInput([]), new NullOutput(), 0));
    }

    private function createListener(CostSnapshot $snapshot, ?LoggerInterface $logger = null): CostLoggerListener
    {
        $costTracker = static::createStub(CostTrackerInterface::class);
        $costTracker->method('getSnapshot')->willReturn($snapshot);

        return new CostLoggerListener($costTracker, $logger);
    }

    private function createTerminateEvent(): TerminateEvent
    {
        return new TerminateEvent(
            static::createStub(HttpKernelInterface::class),
            new Request(),
            new Response(),
        );
    }
}
