<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio;

/**
 * One file's conversion, kept on disk between requests so a slow conversion can be finished by a
 * later run (the scheduler or the work command) without uploading or paying twice.
 */
final class Job
{
    /** Sending the file. */
    public const UPLOADING = 'uploading';
    /** Converting on Converter Studio; checked until it ends. */
    public const CONVERTING = 'converting';
    /** It costs more than allowed and waits for approval (the confirm command). Nothing is charged until then. */
    public const QUOTED = 'quoted';
    public const DONE = 'done';
    public const FAILED = 'failed';

    /** @var array<string, mixed> */
    public array $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->data = $data + [
            'state' => self::UPLOADING,
            'upload' => null,
            'conversion' => null,
            'credits' => null,
            'checks' => 0,
            'next_check' => 0,
            'error' => null,
            'result' => null,
            'original' => null,
        ];
    }

    /**
     * A new job for a file.
     *
     * @param string $path The file, relative to the site's root.
     * @param array<string, mixed> $options
     */
    public static function create(string $path, int $size, int $mtime, string $group, string $to, array $options, int $maxCredits, string $originals): self
    {
        return new self([
            'id' => self::idFor($path, $size, $mtime, $to),
            'path' => $path,
            'size' => $size,
            'mtime' => $mtime,
            'group' => $group,
            'to' => $to,
            'options' => $options,
            'max_credits' => $maxCredits,
            'originals' => $originals,
            'created' => time(),
            'updated' => time(),
        ]);
    }

    /**
     * The same file (path, size, time) converted to the same format is the same job.
     */
    public static function idFor(string $path, int $size, int $mtime, string $to): string
    {
        return substr(hash('sha256', $path . "\0" . $size . "\0" . $mtime . "\0" . $to), 0, 24);
    }

    public function id(): string
    {
        return (string) $this->data['id'];
    }

    public function state(): string
    {
        return (string) $this->data['state'];
    }

    public function path(): string
    {
        return (string) $this->data['path'];
    }

    public function name(): string
    {
        return basename($this->path());
    }

    /**
     * Whether a later run has something to do (sending the file or checking the conversion).
     */
    public function pending(): bool
    {
        return in_array($this->state(), [self::UPLOADING, self::CONVERTING], true);
    }

    public function finished(): bool
    {
        return in_array($this->state(), [self::DONE, self::FAILED], true);
    }

    /**
     * The error's message, when it failed.
     */
    public function errorMessage(): string
    {
        return (string) ($this->data['error']['message'] ?? '');
    }

    public function fail(string $code, string $message): void
    {
        $this->data['state'] = self::FAILED;
        $this->data['error'] = ['code' => $code, 'message' => $message];
    }

    /**
     * One line for people: what happened to the file.
     */
    public function describe(): string
    {
        $name = $this->name();
        $credits = is_numeric($this->data['credits']) ? (int) $this->data['credits'] : null;
        $cost = $credits === null ? '' : sprintf(' (%d credit%s)', $credits, $credits === 1 ? '' : 's');
        switch ($this->state()) {
            case self::DONE:
                return sprintf('%s → %s%s', $name, basename((string) $this->data['result']), $cost);
            case self::FAILED:
                return sprintf('%s was not converted: %s', $name, $this->errorMessage());
            case self::QUOTED:
                return sprintf(
                    '%s would cost %s, more than the %d allowed per file. Approve it with: bin/plugin converter-studio confirm %s',
                    $name,
                    $credits === null ? 'more credits' : sprintf('%d credit%s', $credits, $credits === 1 ? '' : 's'),
                    (int) $this->data['max_credits'],
                    $this->id(),
                );
            default:
                $error = $this->errorMessage();
                return $error !== ''
                    ? sprintf('%s will be tried again: %s', $name, $error)
                    : sprintf('%s is still converting; the converted file is added when it is done.', $name);
        }
    }
}
