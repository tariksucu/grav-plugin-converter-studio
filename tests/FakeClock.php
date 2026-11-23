<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio\Tests;

/**
 * Time that moves on only when the code waits, so the tests of slow conversions run at once.
 * It starts at the real time, so the client's own checks against the real clock agree with it.
 */
final class FakeClock
{
    private float $offset = 0.0;

    public float $slept = 0.0;

    public function now(): float
    {
        return microtime(true) + $this->offset;
    }

    public function sleep(float $seconds): void
    {
        $this->offset += $seconds;
        $this->slept += $seconds;
    }

    /** @return \Closure(): float */
    public function clock(): \Closure
    {
        return fn (): float => $this->now();
    }

    /** @return \Closure(float): void */
    public function sleeper(): \Closure
    {
        return function (float $seconds): void {
            $this->sleep($seconds);
        };
    }
}
