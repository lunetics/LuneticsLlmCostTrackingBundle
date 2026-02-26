<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Tests;

use Lunetics\LlmCostTrackingBundle\LuneticsLlmCostTrackingBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LuneticsLlmCostTrackingBundleTest extends TestCase
{
    #[Test]
    public function itReturnsPath(): void
    {
        $bundle = new LuneticsLlmCostTrackingBundle();
        self::assertSame(\dirname(__DIR__), $bundle->getPath());
    }
}
