<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio;

/**
 * Remembers which files of a folder were replaced by their converted file (photo.heic became
 * photo.jpg), so that saving a page that still links to the old name links to the new one: the
 * Admin's editor inserts a file under the name it was uploaded with, before it is converted.
 *
 * Only names whose original is no longer in the folder are remembered (an original kept beside its
 * converted file still works as a link).
 */
final class References
{
    /** Renames are forgotten after this many days. */
    private const KEEP_DAYS = 60;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * Records that $old in $folder is now $new.
     */
    public function add(string $folder, string $old, string $new, ?int $now = null): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // Two uploads may finish at once: one writer at a time.
        $lock = @fopen($this->file . '.lock', 'c');
        if ($lock !== false) {
            flock($lock, LOCK_EX);
        }
        try {
            $this->addUnlocked($folder, $old, $new, $now);
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function addUnlocked(string $folder, string $old, string $new, ?int $now): void
    {
        $map = $this->load();
        $now ??= time();
        $map[$folder][$old] = ['to' => $new, 'at' => $now];
        // Forget old renames, so the file stays small.
        foreach ($map as $dir => $names) {
            foreach ($names as $name => $entry) {
                if ((int) ($entry['at'] ?? 0) < $now - self::KEEP_DAYS * 86400) {
                    unset($map[$dir][$name]);
                }
            }
            if ($map[$dir] === []) {
                unset($map[$dir]);
            }
        }
        $this->store($map);
    }

    /**
     * The renames of a folder, old name => new name.
     *
     * @return array<string, string>
     */
    public function forFolder(string $folder): array
    {
        $renames = [];
        foreach ($this->load()[$folder] ?? [] as $old => $entry) {
            if (is_string($old) && is_string($entry['to'] ?? null)) {
                $renames[$old] = $entry['to'];
            }
        }
        return $renames;
    }

    /**
     * $text with each old file name replaced by its new one, wherever the name stands on its own:
     * `![](photo.heic)`, `![](/blog/post/photo.heic?cropResize=300,300)` or `image: photo.heic`,
     * but not `my-photo.heic` or `photo.heic.bak`.
     *
     * @param array<string, string> $renames
     */
    public static function rewrite(string $text, array $renames): string
    {
        foreach ($renames as $old => $new) {
            if ($old === '' || $new === '' || !str_contains($text, $old)) {
                continue;
            }
            $pattern = '/(?<![\w.%-])' . preg_quote($old, '/') . '(?![\w.%-])/u';
            $text = (string) preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $new), $text);
        }
        return $text;
    }

    /**
     * A page header (front matter) with the same replacement in every text value.
     *
     * @param array<mixed> $header
     * @param array<string, string> $renames
     * @return array<mixed>
     */
    public static function rewriteHeader(array $header, array $renames): array
    {
        foreach ($header as $key => $value) {
            if (is_string($value)) {
                $header[$key] = self::rewrite($value, $renames);
            } elseif (is_array($value)) {
                $header[$key] = self::rewriteHeader($value, $renames);
            }
        }
        return $header;
    }

    /**
     * @return array<string, array<string, array{to?: string, at?: int}>>
     */
    private function load(): array
    {
        $raw = @file_get_contents($this->file);
        $map = $raw === false ? null : json_decode($raw, true);
        return is_array($map) ? $map : [];
    }

    /**
     * @param array<string, array<string, array{to?: string, at?: int}>> $map
     */
    private function store(array $map): void
    {
        $temporary = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($temporary, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        rename($temporary, $this->file);
    }
}
