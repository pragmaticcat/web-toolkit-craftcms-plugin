<?php

namespace pragmatic\webtoolkit\tests\unit;

use PHPUnit\Framework\TestCase;
use pragmatic\webtoolkit\services\MonitorQueueRunner;

/**
 * Covers how PWM runs the queue of sites without a cron or worker.
 */
class MonitorQueueRunnerTest extends TestCase
{
    public function testItRunsJobsUntilTheQueueIsEmpty(): void
    {
        $waiting = 3;

        $result = MonitorQueueRunner::run(function() use (&$waiting): bool {
            if ($waiting === 0) {
                return false;
            }
            $waiting--;

            return true;
        }, fn(): float => 0.0, 25);

        self::assertSame(['ran' => 3, 'stopped' => 'empty'], $result);
    }

    public function testItStopsWhenTheBudgetIsSpent(): void
    {
        $now = 0.0;

        // Every job takes 10 seconds and the queue never empties.
        $result = MonitorQueueRunner::run(function() use (&$now): bool {
            $now += 10;

            return true;
        }, function() use (&$now): float {
            return $now;
        }, 25);

        self::assertSame(['ran' => 3, 'stopped' => 'time'], $result);
    }

    public function testTheBudgetStaysWithinLimits(): void
    {
        self::assertSame(MonitorQueueRunner::DEFAULT_SECONDS, MonitorQueueRunner::seconds(null));
        self::assertSame(MonitorQueueRunner::DEFAULT_SECONDS, MonitorQueueRunner::seconds('soon'));
        self::assertSame(10, MonitorQueueRunner::seconds('10'));
        self::assertSame(1, MonitorQueueRunner::seconds(0));
        self::assertSame(MonitorQueueRunner::MAX_SECONDS, MonitorQueueRunner::seconds(600));
    }
}
