<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio;

use Grav\Plugin\ConverterStudio\Api\ApiResult;
use Grav\Plugin\ConverterStudio\Api\Client;

/**
 * Converts one file through the API, step by step: send it, start the conversion, check on it,
 * download the result next to the original, then keep, leave or delete the original.
 *
 * Every step is saved, so a run that runs out of time (an Admin upload waits only so long) is
 * finished later by the scheduler or the work command: the same upload, the same conversion (an
 * Idempotency-Key guards the one request that could otherwise start a second), never a second
 * charge.
 */
final class Converter
{
    /** Errors worth trying again later rather than failing the file. */
    private const TRANSIENT = ['network', 'serverBusy', 'tooManyRequests', 'http_500', 'http_502', 'http_503', 'http_504'];

    /** Tries of a transient error before the file is failed. */
    private const MAX_ATTEMPTS = 12;

    /** Seconds the API is asked to hold one status check (it allows 60). */
    private const LONG_POLL = 20;

    /** @var \Closure(string, string): void */
    private readonly \Closure $log;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    private readonly Originals $originals;

    /**
     * @param string $root The site's root; jobs keep paths relative to it.
     * @param string $originalsDir Where kept originals go (user/data/converter-studio/originals),
     *     under a name the web server does not serve (see Originals).
     * @param (\Closure(string, string): void)|null $log Gets a level (info, warning) and a line.
     * @param (\Closure(): float)|null $clock The time (microtime(true)); tests pass their own.
     * @param (\Closure(float): void)|null $sleep Waits that many seconds.
     */
    public function __construct(
        private readonly Client $client,
        private readonly JobStore $jobs,
        private readonly References $references,
        private readonly string $root,
        string $originalsDir,
        ?\Closure $log = null,
        ?\Closure $clock = null,
        ?\Closure $sleep = null,
    ) {
        $this->originals = new Originals($originalsDir);
        $this->log = $log ?? static function (string $level, string $message): void {
        };
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    /**
     * The time, as deadlines are given (microtime(true) unless a test says otherwise).
     */
    public function now(): float
    {
        return ($this->clock)();
    }

    public function client(): Client
    {
        return $this->client;
    }

    public function jobs(): JobStore
    {
        return $this->jobs;
    }

    /**
     * The job for a file: the saved one when this exact file (path, size, time, target) has one,
     * otherwise a new one.
     *
     * @param array<string, mixed> $options
     */
    public function jobFor(string $file, string $group, string $to, array $options, int $maxCredits, string $originals): ?Job
    {
        clearstatcache(true, $file);
        $size = @filesize($file);
        $mtime = @filemtime($file);
        $path = $this->relative($file);
        if ($size === false || $mtime === false || $path === null) {
            return null;
        }
        $existing = $this->jobs->get(Job::idFor($path, $size, $mtime, $to));
        if ($existing !== null) {
            return $existing;
        }
        $job = Job::create($path, $size, $mtime, $group, $to, $options, $maxCredits, $originals);
        $job->data['content'] = @sha1_file($file) ?: null;
        $this->jobs->save($job);
        return $job;
    }

    /**
     * A conversion of the same file (same place, same bytes) that failed or waits for approval
     * since $since: an upload sent again within moments (Admin 2's uploader retries a refused
     * upload three times) gets that answer again instead of a new conversion.
     */
    public function recentProblem(string $file, int $since): ?Job
    {
        $path = $this->relative($file);
        $content = @sha1_file($file);
        if ($path === null || $content === false) {
            return null;
        }
        foreach (array_reverse($this->jobs->all()) as $job) {
            if ($job->path() === $path && ($job->data['content'] ?? null) === $content
                && in_array($job->state(), [Job::FAILED, Job::QUOTED], true) && (int) $job->data['updated'] >= $since) {
                return $job;
            }
        }
        return null;
    }

    /**
     * Takes a job as far as it goes before $deadline: finished, waiting for approval, or still
     * running (to be picked up again later).
     */
    public function run(Job $job, float $deadline): Job
    {
        if (!$this->jobs->lock($job->id())) {
            // Another run (the scheduler, a command) is on it right now.
            return $job;
        }
        try {
            $job = $this->jobs->get($job->id()) ?? $job;
            while ($job->pending()) {
                $again = $job->state() === Job::UPLOADING ? $this->upload($job, $deadline) : $this->check($job, $deadline);
                $this->jobs->save($job);
                if (!$again || $this->now() >= $deadline - 3) {
                    break;
                }
            }
            if ($job->finished()) {
                ($this->log)($job->state() === Job::DONE ? 'info' : 'warning', 'Converter Studio: ' . $job->describe());
            }
            return $job;
        } finally {
            $this->jobs->unlock($job->id());
        }
    }

    /**
     * Approves a conversion that waits because it costs more than allowed, then runs it.
     */
    public function confirm(Job $job, float $deadline): Job
    {
        if ($job->state() !== Job::QUOTED || !is_string($job->data['conversion'])) {
            return $job;
        }
        $result = $this->client->confirm($job->data['conversion']);
        if (!$result->ok()) {
            if (!$this->transient($result)) {
                $job->fail($result->code, $result->message());
            }
            $this->jobs->save($job);
            return $job;
        }
        $job->data['state'] = Job::CONVERTING;
        $this->applyView($job, $result);
        $this->jobs->save($job);
        return $this->run($job, $deadline);
    }

    /**
     * Sends the file and starts its conversion.
     *
     * @return bool Whether to go on to checking the conversion now.
     */
    private function upload(Job $job, float $deadline): bool
    {
        $file = $this->absolute($job->path());
        clearstatcache(true, $file);
        if (!is_file($file)) {
            $job->fail('missing', ApiResult::local('missing')->message());
            return false;
        }
        if (@filesize($file) !== (int) $job->data['size'] || @filemtime($file) !== (int) $job->data['mtime']) {
            $job->fail('changed', 'The file was replaced before its conversion started.');
            return false;
        }
        $upload = is_array($job->data['upload']) ? $job->data['upload'] : null;
        $expired = $upload !== null && empty($upload['complete']) && !empty($upload['expires'])
            && (int) strtotime((string) $upload['expires']) < time() + 60;
        if ($upload === null || $expired) {
            $created = $this->client->createUpload($job->name(), (int) $job->data['size']);
            if (!$created->ok()) {
                $this->problem($job, $created);
                return false;
            }
            $upload = [
                'id' => (string) $created->get('id'),
                'url' => (string) $created->get('uploadUrl'),
                'expires' => (string) $created->get('expiresAt'),
                'offset' => 0,
                'size' => (int) $job->data['size'],
                'complete' => false,
            ];
            if ($upload['id'] === '' || $upload['url'] === '') {
                $job->fail('http_' . $created->status, 'Converter Studio did not open an upload.');
                return false;
            }
            $job->data['upload'] = $upload;
        }
        if (empty($upload['complete'])) {
            $state = ['id' => $upload['id'], 'url' => $upload['url'], 'offset' => (int) $upload['offset'], 'size' => (int) $upload['size']];
            $sent = $this->client->sendParts($file, $state, $deadline);
            $upload['offset'] = $state['offset'];
            $job->data['upload'] = $upload;
            if ($sent->code === 'timeUp') {
                return false;
            }
            if (!$sent->ok()) {
                if ($sent->status === 404) {
                    // The slot is gone (it lasts a while): the next run opens a new one.
                    $job->data['upload'] = null;
                    return false;
                }
                $this->problem($job, $sent);
                return false;
            }
            $upload['complete'] = true;
            $job->data['upload'] = $upload;
        }
        $started = $this->client->convert(
            (string) $upload['id'],
            (string) $job->data['to'],
            (array) $job->data['options'],
            (int) $job->data['max_credits'],
            'grav-' . $upload['id'],
        );
        if (!$started->ok()) {
            $this->problem($job, $started);
            return false;
        }
        $id = $started->get('id');
        if (!is_string($id) || $id === '') {
            $job->fail('http_' . $started->status, 'Converter Studio did not start the conversion.');
            return false;
        }
        $job->data['conversion'] = $id;
        $job->data['state'] = Job::CONVERTING;
        $job->data['attempts'] = 0;
        $job->data['error'] = null;
        $this->applyView($job, $started);
        return true;
    }

    /**
     * Checks on the conversion (the API holds the answer up to LONG_POLL seconds until it
     * settles, which paces the checks), and downloads the result once it is done.
     *
     * @return bool Whether to check again now.
     */
    private function check(Job $job, float $deadline): bool
    {
        $status = (string) ($job->data['status'] ?? '');
        if ($status !== 'done' && $status !== 'quoted') {
            $left = $deadline - $this->now();
            if ($left < 3) {
                return false;
            }
            $asked = $this->now();
            $result = $this->client->conversion((string) $job->data['conversion'], (int) max(0, min(self::LONG_POLL, $left - 3)));
            if (!$result->ok()) {
                if ($result->status === 404) {
                    $job->fail('notFound', 'The conversion is no longer on Converter Studio.');
                    return false;
                }
                $this->problem($job, $result);
                return false;
            }
            $this->applyView($job, $result);
            $job->data['error'] = null;
            $status = (string) ($job->data['status'] ?? '');
            if (!in_array($status, ['done', 'quoted', 'failed', 'expired', 'canceled'], true)) {
                // An answer that came at once: pause a moment rather than ask again straight away.
                if ($this->now() - $asked < 1) {
                    ($this->sleep)(1.0);
                }
                return true;
            }
        }
        switch ($status) {
            case 'done':
                $this->finish($job, max($deadline, $this->now() + 60));
                return false;
            case 'quoted':
                $job->data['state'] = Job::QUOTED;
                return false;
            default:
                $error = is_array($job->data['view_error'] ?? null) ? $job->data['view_error'] : [];
                $message = ApiResult::clean((string) ($error['message'] ?? ''));
                $job->fail((string) ($error['code'] ?? $status), $message !== '' ? $message : match ($status) {
                    'expired' => 'The result expired before it was downloaded.',
                    'canceled' => 'The conversion was canceled.',
                    default => 'The conversion failed.',
                });
                return false;
        }
    }

    /**
     * Downloads the result next to the original under a free name, then deals with the original.
     */
    private function finish(Job $job, float $deadline): void
    {
        $file = $this->absolute($job->path());
        $folder = dirname($file);
        $url = (string) ($job->data['download'] ?? '');
        if ($url === '') {
            // The view had no link yet: ask again.
            $job->data['status'] = 'processing';
            return;
        }
        if (!is_dir($folder) || !is_writable($folder)) {
            $job->fail('unwritable', ApiResult::local('unwritable')->message());
            return;
        }
        $temporary = $folder . '/.converter-studio-' . $job->id() . '.part';
        $expected = is_numeric($job->data['output_size'] ?? null) ? (int) $job->data['output_size'] : null;
        $downloaded = $this->client->download($url, $temporary, $deadline, $expected);
        if (!$downloaded->ok()) {
            if ($downloaded->status === 410 || $downloaded->status === 404) {
                $job->fail('fileExpired', 'The result expired before it was downloaded.');
                return;
            }
            $this->problem($job, $downloaded);
            return;
        }
        $target = self::freeName($folder, pathinfo($job->name(), PATHINFO_FILENAME), (string) $job->data['to']);
        if ($target === null || !@rename($temporary, $target)) {
            @unlink($temporary);
            $job->fail('unwritable', ApiResult::local('unwritable')->message());
            return;
        }
        $mode = @fileperms($file);
        @chmod($target, $mode !== false ? $mode & 0666 : 0644);
        $job->data['result'] = $this->relative($target) ?? basename($target);
        $job->data['original'] = $this->dealWithOriginal($job, $file, $target);
        $job->data['state'] = Job::DONE;
        $job->data['error'] = null;
    }

    /**
     * Keeps the original out of the web's reach, leaves it, or deletes it, as the settings say.
     *
     * @return string Where it went (relative to the site), "beside" or "deleted".
     */
    private function dealWithOriginal(Job $job, string $file, string $target): string
    {
        $mode = (string) $job->data['originals'];
        if ($mode === 'beside' || !is_file($file)) {
            return 'beside';
        }
        $folder = dirname($file);
        $where = 'deleted';
        if ($mode === 'delete') {
            @unlink($file);
        } else {
            // photo.heic.original.txt: no web server that follows Grav's rules serves it.
            $destination = $this->originals->destination($job->path());
            @mkdir(dirname($destination), 0775, true);
            if (!@rename($file, $destination)) {
                // Another disk: copy, then remove.
                if (!@copy($file, $destination)) {
                    return 'beside';
                }
                @unlink($file);
            }
            $where = $this->relative($destination) ?? $destination;
        }
        // Grav keeps a medium's title, alt text and caption in <name>.meta.yaml: they belong to
        // the converted file now.
        $meta = $file . '.meta.yaml';
        if (is_file($meta) && !file_exists($target . '.meta.yaml')) {
            @rename($meta, $target . '.meta.yaml');
        }
        $relativeFolder = $this->relative($folder);
        if ($relativeFolder !== null) {
            $this->references->add($relativeFolder, basename($file), basename($target));
        }
        return $where;
    }

    /**
     * Keeps what the API said about the conversion.
     */
    private function applyView(Job $job, ApiResult $result): void
    {
        $status = $result->get('status');
        if (is_string($status)) {
            $job->data['status'] = $status;
        }
        foreach (['credits' => 'credits', 'outputSize' => 'output_size', 'downloadUrl' => 'download'] as $from => $to) {
            $value = $result->get($from);
            if ($value !== null) {
                $job->data[$to] = $value;
            }
        }
        $error = $result->get('error');
        $job->data['view_error'] = is_array($error) ? $error : null;
    }

    /**
     * An error answer: worth another try later, or the end of this file.
     */
    private function problem(Job $job, ApiResult $result): void
    {
        if ($this->transient($result)) {
            $job->data['attempts'] = (int) ($job->data['attempts'] ?? 0) + 1;
            if ($job->data['attempts'] < self::MAX_ATTEMPTS) {
                // Kept for the status command; the job stays pending for the next run.
                $job->data['error'] = ['code' => $result->code, 'message' => $result->message()];
                return;
            }
        }
        $job->fail($result->code, $result->message());
    }

    private function transient(ApiResult $result): bool
    {
        return in_array($result->code, self::TRANSIENT, true);
    }

    /**
     * "photo.jpg" in $folder, or "photo-1.jpg", "photo-2.jpg"… when taken. Null when none is free.
     */
    public static function freeName(string $folder, string $stem, string $extension): ?string
    {
        for ($i = 0; $i < 1000; $i++) {
            $candidate = $folder . '/' . $stem . ($i === 0 ? '' : '-' . $i) . '.' . $extension;
            if (!file_exists($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * A path relative to the site's root, or null when it is outside it.
     */
    public function relative(string $path): ?string
    {
        return self::relativeTo($this->root, $path);
    }

    /**
     * $path relative to $root (both resolved when they exist), or null when it is outside it.
     */
    public static function relativeTo(string $root, string $path): ?string
    {
        $base = rtrim($root, '/') . '/';
        $real = realpath($path);
        $path = $real !== false ? $real : $path;
        $realRoot = realpath($root);
        $base = $realRoot !== false ? rtrim($realRoot, '/') . '/' : $base;
        return str_starts_with($path, $base) ? substr($path, strlen($base)) : null;
    }

    public function absolute(string $relative): string
    {
        return rtrim($this->root, '/') . '/' . ltrim($relative, '/');
    }
}
