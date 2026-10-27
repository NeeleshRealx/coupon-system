<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * Runs code in REAL parallel OS processes (pcntl_fork), each with its own
 * database connection, so row locks, unique indexes and transaction isolation
 * are exercised exactly as they are under production traffic.
 *
 * Why not RefreshDatabase? It wraps each test in a transaction that is never
 * committed, so child processes (separate connections) could not see the
 * fixture data. DatabaseTruncation commits fixtures and truncates afterwards.
 *
 * Why MySQL only? SQLite has no row-level locks (FOR UPDATE is ignored) and
 * serialises all writers on a file lock, so a "passing" race test there proves
 * nothing about production behaviour.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('The pcntl and posix extensions are required for concurrency tests.');
        }

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concurrency tests must run against MySQL (real row locks). See README.');
        }
    }

    protected function tearDown(): void
    {
        // Fixtures were COMMITTED (children must see them). Clean up so later
        // RefreshDatabase-based tests start from empty tables regardless of order.
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    /**
     * Fork one process per task, release them all at (roughly) the same instant
     * and collect their results in task order.
     *
     * @param  list<callable(): mixed>  $tasks
     * @param  list<float>  $startOffsets  optional per-task delay (seconds) after the common start
     * @return list<array{ok: bool, value: mixed, error: ?string, message: ?string}>
     */
    protected function runConcurrently(array $tasks, array $startOffsets = []): array
    {
        // A forked child inherits the parent's open socket. If a child closed it,
        // the parent's connection would die. So: no open connection at fork time.
        DB::disconnect();

        $startAt = microtime(true) + 0.5; // time for every child to be forked and waiting
        $children = [];

        foreach (array_values($tasks) as $index => $task) {
            $resultFile = tempnam(sys_get_temp_dir(), 'coupon-race-');
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('pcntl_fork() failed.');
            }

            if ($pid === 0) {
                $this->runChild($task, $resultFile, $startAt + ($startOffsets[$index] ?? 0.0));
            }

            $children[] = ['pid' => $pid, 'file' => $resultFile];
        }

        $results = [];

        foreach ($children as $child) {
            pcntl_waitpid($child['pid'], $status);
            $raw = (string) @file_get_contents($child['file']);
            @unlink($child['file']);

            $decoded = json_decode($raw, true);
            $results[] = is_array($decoded)
                ? $decoded
                : ['ok' => false, 'value' => null, 'error' => 'ChildCrashed', 'message' => 'No result written.'];
        }

        return $results;
    }

    private function runChild(callable $task, string $resultFile, float $startAt): never
    {
        try {
            DB::purge(); // forget the inherited connection; a fresh one is opened lazily

            $wait = $startAt - microtime(true);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }

            $result = ['ok' => true, 'value' => $task(), 'error' => null, 'message' => null];
        } catch (Throwable $e) {
            $result = ['ok' => false, 'value' => null, 'error' => class_basename($e), 'message' => $e->getMessage()];
        }

        file_put_contents($resultFile, json_encode($result));

        // Kill ourselves without running PHPUnit/Laravel shutdown handlers or
        // destructors, which would otherwise run a second time inside the child.
        posix_kill(posix_getpid(), SIGKILL);
        exit(1); // unreachable
    }

    /**
     * @param  list<array{ok: bool, value: mixed, error: ?string, message: ?string}>  $results
     * @return array<string, int> e.g. ['ok' => 1, 'CouponQuotaExhaustedException' => 9]
     */
    protected function tally(array $results): array
    {
        $counts = [];

        foreach ($results as $result) {
            $key = $result['ok'] ? 'ok' : (string) $result['error'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }
}
