<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Provides helper methods for measuring query performance and response times.
 */
trait MeasuresPerformance
{
    protected array $queryLog = [];
    protected float $startTime = 0;

    protected function startQueryLog(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
    }

    protected function getQueryCount(): int
    {
        return count(DB::getQueryLog());
    }

    protected function getQueryLog(): array
    {
        return DB::getQueryLog();
    }

    protected function getSlowQueries(float $thresholdMs = 100): array
    {
        return array_filter(DB::getQueryLog(), fn ($q) => $q['time'] > $thresholdMs);
    }

    protected function stopQueryLog(): array
    {
        $log = DB::getQueryLog();
        DB::disableQueryLog();
        return $log;
    }

    protected function startTimer(): void
    {
        $this->startTime = microtime(true);
    }

    protected function getElapsedMs(): float
    {
        return (microtime(true) - $this->startTime) * 1000;
    }

    /**
     * Assert that a callable executes within a time threshold in ms.
     */
    protected function assertExecutesWithin(float $maxMs, callable $callback, string $message = ''): void
    {
        $start = microtime(true);
        $callback();
        $elapsed = (microtime(true) - $start) * 1000;

        $msg = $message ?: "Execution time {$elapsed}ms exceeded {$maxMs}ms threshold";
        $this->assertLessThanOrEqual($maxMs, $elapsed, $msg);
    }

    /**
     * Assert that a callable does not exceed a given query count.
     */
    protected function assertQueryCountBelow(int $maxQueries, callable $callback, string $message = ''): void
    {
        $this->startQueryLog();
        $callback();
        $count = $this->getQueryCount();
        $this->stopQueryLog();

        $msg = $message ?: "Query count {$count} exceeded max {$maxQueries}";
        $this->assertLessThanOrEqual($maxQueries, $count, $msg);
    }

    /**
     * Run multiple concurrent-simulated requests and return aggregate stats.
     */
    protected function simulateConcurrentRequests(string $method, string $uri, int $count, array $headers = []): array
    {
        $results = [
            'total'     => $count,
            'success'   => 0,
            'failures'  => 0,
            'times_ms'  => [],
            'statuses'  => [],
            'errors'    => [],
        ];

        for ($i = 0; $i < $count; $i++) {
            $start = microtime(true);
            try {
                $response = $this->{strtolower($method)}($uri, $headers);
                $elapsed = (microtime(true) - $start) * 1000;
                $results['times_ms'][] = $elapsed;
                $results['statuses'][] = $response->getStatusCode();

                if ($response->isSuccessful() || $response->isRedirection()) {
                    $results['success']++;
                } else {
                    $results['failures']++;
                    $results['errors'][] = "Request {$i}: HTTP {$response->getStatusCode()}";
                }
            } catch (\Throwable $e) {
                $elapsed = (microtime(true) - $start) * 1000;
                $results['times_ms'][] = $elapsed;
                $results['failures']++;
                $results['errors'][] = "Request {$i}: {$e->getMessage()}";
            }
        }

        $results['avg_ms'] = count($results['times_ms']) > 0
            ? array_sum($results['times_ms']) / count($results['times_ms'])
            : 0;
        $results['max_ms'] = count($results['times_ms']) > 0
            ? max($results['times_ms'])
            : 0;
        $results['min_ms'] = count($results['times_ms']) > 0
            ? min($results['times_ms'])
            : 0;
        $results['p95_ms'] = $this->percentile($results['times_ms'], 95);
        $results['success_rate'] = $count > 0 ? ($results['success'] / $count) * 100 : 0;

        return $results;
    }

    protected function percentile(array $data, float $percentile): float
    {
        if (empty($data)) return 0;

        sort($data);
        $index = ($percentile / 100) * (count($data) - 1);
        $lower = floor($index);
        $upper = ceil($index);

        if ($lower == $upper) return $data[(int) $lower];

        return $data[(int) $lower] * ($upper - $index) + $data[(int) $upper] * ($index - $lower);
    }
}
