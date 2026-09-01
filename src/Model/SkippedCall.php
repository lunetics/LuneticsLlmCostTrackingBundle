<?php

declare(strict_types=1);

namespace Lunetics\LlmCostTrackingBundle\Model;

final readonly class SkippedCall
{
    public function __construct(
        public ?string $model,
        public string $exceptionClass,
        public string $exceptionMessage,
    ) {
    }
}
