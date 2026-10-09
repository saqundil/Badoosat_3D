<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Jobs live in MySQL so shared hosting can run them from a one-minute cron.
 * On a VPS the same table is drained by a long-running worker; the services
 * that push jobs never learn which it is.
 *
 * Double execution is prevented by claiming a row with a conditional UPDATE:
 * only one process can move a job out of the available set.
 */
final class Queue
{
    public const MAX_ATTEMPTS = 3;

    /** Seconds a claimed job may run before another worker may retry it. */
    public const LEASE = 300;

    /**
     * The connection is optional and resolved on first use. A repository built
     * for a request that ends up served entirely from cache must never open a
     * socket, or the cache cannot protect the page from a database outage.
     */
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? Db::pdo();
    }

    public static function make(): self
    {
        return new self();
    }

    public function push(string $type, array $payload, int $delaySeconds = 0): int
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO jobs (type, payload, available_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
        );
        $stmt->execute([$type, json_encode($payload, JSON_UNESCAPED_UNICODE), max(0, $delaySeconds)]);

        return (int) $this->pdo()->lastInsertId();
    }

    /** @return array<string,mixed>|null the claimed job, or null when idle */
    public function reserve(): ?array
    {
        $token = bin2hex(random_bytes(16));

        $claim = $this->pdo()->prepare(
            'UPDATE jobs
                SET reserved_at = NOW(), reserved_token = ?, attempts = attempts + 1
              WHERE reserved_token IS NULL
                AND available_at <= NOW()
                AND (reserved_at IS NULL OR reserved_at < DATE_SUB(NOW(), INTERVAL ? SECOND))
              ORDER BY id ASC
              LIMIT 1'
        );
        $claim->execute([$token, self::LEASE]);

        if ($claim->rowCount() === 0) {
            return null;
        }

        $stmt = $this->pdo()->prepare('SELECT * FROM jobs WHERE reserved_token = ? LIMIT 1');
        $stmt->execute([$token]);
        $job = $stmt->fetch();

        if ($job === false) {
            return null;
        }

        $job['payload'] = json_decode((string) $job['payload'], true) ?: [];

        return $job;
    }

    public function complete(int $id): void
    {
        $this->pdo()->prepare('DELETE FROM jobs WHERE id = ?')->execute([$id]);
    }

    /** Releases a job for another attempt, or files it under failed_jobs. */
    public function fail(array $job, Throwable $error): void
    {
        $id       = (int) $job['id'];
        $attempts = (int) $job['attempts'];

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->pdo()->prepare(
                'INSERT INTO failed_jobs (job_id, type, payload, attempts, error)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $id,
                $job['type'],
                json_encode($job['payload'], JSON_UNESCAPED_UNICODE),
                $attempts,
                substr($error->getMessage(), 0, 2000),
            ]);

            $this->complete($id);

            return;
        }

        // Back off: 1 minute, then 4, then 9.
        $this->pdo()->prepare(
            'UPDATE jobs
                SET reserved_at = NULL, reserved_token = NULL, last_error = ?,
                    available_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
              WHERE id = ?'
        )->execute([substr($error->getMessage(), 0, 2000), 60 * $attempts * $attempts, $id]);
    }

    /**
     * Drains the queue until it is empty or the budget runs out, so a one-minute
     * cron never overlaps itself.
     *
     * @param array<string,callable> $handlers type => fn(array $payload): void
     * @return array{processed:int,failed:int}
     */
    public function work(array $handlers, int $budgetSeconds = 50): array
    {
        $deadline  = time() + $budgetSeconds;
        $processed = 0;
        $failed    = 0;

        while (time() < $deadline) {
            $job = $this->reserve();

            if ($job === null) {
                break;
            }

            $handler = $handlers[$job['type']] ?? null;

            try {
                if ($handler === null) {
                    throw new \RuntimeException('No handler for job type ' . $job['type']);
                }

                $handler($job['payload']);
                $this->complete((int) $job['id']);
                $processed++;
            } catch (Throwable $e) {
                $this->fail($job, $e);
                $failed++;
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }
}
