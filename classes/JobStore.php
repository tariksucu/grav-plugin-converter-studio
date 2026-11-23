<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio;

/**
 * Jobs as small JSON files in user/data/converter-studio/jobs (not reachable from the web), one
 * per file, each with a lock so the scheduler and a command never work on the same job at once.
 */
final class JobStore
{
    /** Finished jobs are forgotten after this many days. */
    private const KEEP_DONE_DAYS = 7;
    private const KEEP_FAILED_DAYS = 30;

    /** @var array<string, resource> Locks held by this process. */
    private array $locks = [];

    public function __construct(private readonly string $dir)
    {
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function get(string $id): ?Job
    {
        if (!self::validId($id)) {
            return null;
        }
        $raw = @file_get_contents($this->file($id));
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) && ($data['id'] ?? null) === $id ? new Job($data) : null;
    }

    public function save(Job $job): void
    {
        $this->ensureDir();
        $job->data['updated'] = time();
        $file = $this->file($job->id());
        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($temporary, json_encode($job->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        rename($temporary, $file);
    }

    /**
     * Every job, oldest first.
     *
     * @return Job[]
     */
    public function all(): array
    {
        $jobs = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $job = $this->get(basename($file, '.json'));
            if ($job !== null) {
                $jobs[] = $job;
            }
        }
        usort($jobs, static fn (Job $a, Job $b): int => ((int) $a->data['created']) <=> ((int) $b->data['created']));
        return $jobs;
    }

    /**
     * Takes a job's lock; false when another process holds it.
     */
    public function lock(string $id): bool
    {
        if (isset($this->locks[$id])) {
            return true;
        }
        if (!self::validId($id)) {
            return false;
        }
        $this->ensureDir();
        $handle = @fopen($this->dir . '/' . $id . '.lock', 'c');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        $this->locks[$id] = $handle;
        return true;
    }

    public function unlock(string $id): void
    {
        if (!isset($this->locks[$id])) {
            return;
        }
        flock($this->locks[$id], LOCK_UN);
        fclose($this->locks[$id]);
        unset($this->locks[$id]);
        @unlink($this->dir . '/' . $id . '.lock');
    }

    /**
     * Forgets finished jobs after a while.
     */
    public function prune(?int $now = null): int
    {
        $now ??= time();
        $removed = 0;
        foreach ($this->all() as $job) {
            $days = match ($job->state()) {
                Job::DONE => self::KEEP_DONE_DAYS,
                Job::FAILED, Job::QUOTED => self::KEEP_FAILED_DAYS,
                default => null,
            };
            if ($days !== null && (int) $job->data['updated'] < $now - $days * 86400 && $this->lock($job->id())) {
                @unlink($this->file($job->id()));
                $this->unlock($job->id());
                $removed++;
            }
        }
        return $removed;
    }

    public function remove(string $id): void
    {
        if (self::validId($id)) {
            @unlink($this->file($id));
        }
    }

    public static function validId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{24}$/', $id);
    }

    private function file(string $id): string
    {
        return $this->dir . '/' . $id . '.json';
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }
}
