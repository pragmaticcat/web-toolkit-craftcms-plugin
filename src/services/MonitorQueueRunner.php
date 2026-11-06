<?php

namespace pragmatic\webtoolkit\services;

/**
 * Runs Craft queue jobs one by one within a time budget, for sites without a cron or worker.
 * PWM calls it through the signed monitor/run-queue endpoint when jobs are waiting.
 */
final class MonitorQueueRunner
{
    public const DEFAULT_SECONDS = 25;
    public const MAX_SECONDS = 50;

    /**
     * The time budget PWM asked for, kept between 1 and MAX_SECONDS.
     */
    public static function seconds(mixed $requested): int
    {
        if (!is_numeric($requested)) {
            return self::DEFAULT_SECONDS;
        }

        return max(1, min(self::MAX_SECONDS, (int)$requested));
    }

    /**
     * Runs jobs until the queue is empty or the budget is spent. A job already started
     * always finishes, so a slow job can go past the budget.
     *
     * @param callable(): bool $executeJob Runs the next waiting job; false when there is none.
     * @param callable(): float $clock Current time in seconds.
     * @return array{ran: int, stopped: 'empty'|'time'}
     */
    public static function run(callable $executeJob, callable $clock, int $seconds): array
    {
        $deadline = $clock() + $seconds;
        $ran = 0;

        while ($clock() < $deadline) {
            if (!$executeJob()) {
                return ['ran' => $ran, 'stopped' => 'empty'];
            }
            $ran++;
        }

        return ['ran' => $ran, 'stopped' => 'time'];
    }
}
