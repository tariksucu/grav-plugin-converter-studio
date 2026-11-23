<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio;

/**
 * Finds the files to convert (just uploaded, or existing media for the command) and finishes the
 * conversions an earlier run left running.
 */
final class Worker
{
    /** A pending job is looked at again after this many seconds at first, doubling up to MAX_PAUSE. */
    private const FIRST_PAUSE = 30;
    private const MAX_PAUSE = 900;

    /**
     * Seconds during which the same file uploaded again gets the earlier failure or quote back
     * (an uploader's automatic retries), rather than a new conversion.
     */
    private const RETRY_WINDOW = 120;

    public function __construct(private readonly Converter $converter, private readonly Settings $settings)
    {
    }

    public function converter(): Converter
    {
        return $this->converter;
    }

    /**
     * Converts the files just uploaded into $folder, for the groups switched on for uploads.
     *
     * @param string[]|null $names The uploaded names; null to take every file changed since $since.
     * @param float $deadline When the upload must answer (microtime(true)).
     * @return Job[]
     */
    public function uploaded(string $folder, ?array $names, int $since, float $deadline): array
    {
        $jobs = [];
        foreach ($this->candidates($folder, $names, $since) as $file => $group) {
            $recent = $this->converter->recentProblem($file, time() - self::RETRY_WINDOW);
            if ($recent !== null) {
                $jobs[] = $recent;
                continue;
            }
            $job = $this->jobFor($file, $group, null);
            if ($job === null) {
                continue;
            }
            $jobs[] = $job->pending() ? $this->converter->run($job, $deadline) : $job;
        }
        return $jobs;
    }

    /**
     * The just-uploaded files of $folder that a switched-on group converts.
     *
     * @param string[]|null $names The uploaded names; null to take every file changed since $since.
     * @return array<string, string> File => group.
     */
    public function candidates(string $folder, ?array $names, int $since): array
    {
        $groups = $this->settings->uploadGroups();
        if ($groups === [] || !is_dir($folder)) {
            return [];
        }
        $files = [];
        if ($names !== null) {
            foreach ($names as $name) {
                $files[] = $folder . '/' . basename((string) $name);
            }
        } else {
            foreach (scandir($folder) ?: [] as $name) {
                $file = $folder . '/' . $name;
                if ($name[0] !== '.' && is_file($file) && (int) @filemtime($file) >= $since) {
                    $files[] = $file;
                }
            }
        }
        $found = [];
        foreach ($files as $file) {
            $group = Rules::groupOf($file);
            if ($group !== null && in_array($group, $groups, true) && is_file($file)) {
                $found[$file] = $group;
            }
        }
        return $found;
    }

    /**
     * The files of $folders that the convert command would take: files of the given groups that
     * have no converted file next to them yet (photo.heic beside photo.jpg is left alone, unless
     * $force).
     *
     * @param string[] $folders
     * @param string[] $groups
     * @param array<string, string> $targets Group => target, to override the settings.
     * @return array<int, array{file: string, group: string, to: string}>
     */
    public function existing(array $folders, array $groups, array $targets = [], bool $force = false): array
    {
        $found = [];
        foreach ($folders as $folder) {
            foreach (scandir($folder) ?: [] as $name) {
                $file = $folder . '/' . $name;
                if ($name[0] === '.' || !is_file($file)) {
                    continue;
                }
                $group = Rules::groupOf($name);
                if ($group === null || !in_array($group, $groups, true)) {
                    continue;
                }
                $to = Rules::target($group, $targets[$group] ?? $this->settings->target($group));
                if (!$force && file_exists($folder . '/' . pathinfo($name, PATHINFO_FILENAME) . '.' . $to)) {
                    continue;
                }
                $found[] = ['file' => $file, 'group' => $group, 'to' => $to];
            }
        }
        return $found;
    }

    /**
     * The job for one file, with the settings' options.
     */
    public function jobFor(string $file, string $group, ?string $to, ?string $originals = null, ?int $maxCredits = null): ?Job
    {
        $to = Rules::target($group, $to ?? $this->settings->target($group));
        return $this->converter->jobFor(
            $file,
            $group,
            $to,
            $this->settings->options($group, $to),
            $maxCredits ?? $this->settings->maxCredits(),
            $originals ?? $this->settings->originals(),
        );
    }

    /**
     * Finishes what earlier runs left running, oldest first, until $deadline. A job that is still
     * converting is looked at again after a pause that doubles each time (30 s up to 15 min),
     * unless $now says to look at every one.
     *
     * @param (\Closure(Job): void)|null $report Called with each job looked at.
     * @return Job[]
     */
    public function pending(float $deadline, bool $now = false, ?\Closure $report = null): array
    {
        $done = [];
        foreach ($this->converter->jobs()->all() as $job) {
            if (!$job->pending()) {
                continue;
            }
            if ($this->converter->now() >= $deadline - 5) {
                break;
            }
            if (!$now && (int) $job->data['next_check'] > time()) {
                continue;
            }
            $job = $this->converter->run($job, $deadline);
            if ($job->pending()) {
                $checks = (int) $job->data['checks'] + 1;
                $job->data['checks'] = $checks;
                $job->data['next_check'] = time() + min(self::MAX_PAUSE, self::FIRST_PAUSE * (2 ** min(5, $checks - 1)));
                $this->converter->jobs()->save($job);
            }
            $done[] = $job;
            if ($report !== null) {
                $report($job);
            }
        }
        return $done;
    }
}
