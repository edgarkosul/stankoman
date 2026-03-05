<?php

namespace App\Exceptions;

use RuntimeException;

class ImportErrorThresholdExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $metric,
        public readonly int|float $threshold,
        public readonly int|float $actual,
    ) {
        $message = match ($this->metric) {
            'percent' => sprintf(
                'Порог ошибок превышен: %.2f%% (факт %.2f%%).',
                (float) $this->threshold,
                (float) $this->actual,
            ),
            default => sprintf(
                'Порог ошибок превышен: %d (факт %d).',
                (int) $this->threshold,
                (int) $this->actual,
            ),
        };

        parent::__construct($message);
    }

    /**
     * @return array{metric:string,threshold:int|float,actual:int|float}
     */
    public function toSnapshot(): array
    {
        return [
            'metric' => $this->metric,
            'threshold' => $this->threshold,
            'actual' => $this->actual,
        ];
    }
}
