<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio;

/**
 * Kept originals: user/data/converter-studio/originals, each under a name no web server that
 * follows Grav's rules will serve.
 *
 * Grav's current web server rules (1.7.53.1 and later, Grav 2) refuse files in user/data unless
 * they end in a media type such as .mov, .wav, .flac or .bmp, so a kept IMG_0042.MOV would be
 * served. Older rules, which Grav's updates never replace (.htaccess is left alone, nginx and
 * the others are copied by hand), serve the whole of user/data. Every rule set Grav has shipped
 * (Apache, nginx, IIS, lighttpd, PHP's built-in server, the current Caddyfile) refuses .txt files
 * anywhere under user/: a kept original is stored as IMG_0042.MOV.original.txt.
 *
 * Version 1.0.0 kept originals under their own names; migrate() renames those, once.
 */
final class Originals
{
    /** Added to a kept original's name. */
    public const SUFFIX = '.original.txt';

    /** Written in the originals folder once every original of 1.0.0 has its suffix. */
    private const RENAMED = '.renamed-1.0.1';

    public function __construct(private readonly string $dir)
    {
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * Where to keep the original of $path (relative to the site's user folder, such as
     * pages/01.blog/photo.heic): the same place under the originals folder, with SUFFIX, and a
     * number before the extension when that name is taken.
     */
    public function destination(string $path): string
    {
        $folder = dirname($this->dir . '/' . ltrim($path, '/'));
        return $folder . '/' . self::freeName($folder, basename($path));
    }

    /**
     * "photo.heic.original.txt" in $folder, or "photo-1.heic.original.txt"… when taken, also by an
     * original of 1.0.0 that still has its own name (it gets the suffix later).
     *
     * @param bool $renaming Whether $name itself is the 1.0.0 original being renamed.
     */
    public static function freeName(string $folder, string $name, bool $renaming = false): string
    {
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        for ($i = 0; $i < 1000; $i++) {
            $candidate = $i === 0 ? $name : $stem . '-' . $i . ($extension !== '' ? '.' . $extension : '');
            $taken = file_exists($folder . '/' . $candidate . self::SUFFIX)
                || (!($renaming && $i === 0) && file_exists($folder . '/' . $candidate));
            if (!$taken) {
                return $candidate . self::SUFFIX;
            }
        }
        return $stem . '-' . bin2hex(random_bytes(4)) . ($extension !== '' ? '.' . $extension : '') . self::SUFFIX;
    }

    /**
     * The name a kept original was uploaded with: IMG_0042.MOV for IMG_0042.MOV.original.txt.
     */
    public static function uploadedName(string $name): string
    {
        return str_ends_with($name, self::SUFFIX) ? substr($name, 0, -strlen(self::SUFFIX)) : $name;
    }

    /**
     * Gives the originals that 1.0.0 kept under their own names the suffix, once. Each file is
     * renamed on its own, in place, so a run that stops half-way goes on where it stopped the next
     * time; a marker file records that all are done, after which this costs one stat. Two
     * processes never rename at once (a lock; the second one leaves it to the first).
     *
     * @return array<string, string> Old path => new path, of the files renamed by this call.
     */
    public function migrate(): array
    {
        if (!is_dir($this->dir) || is_file($this->dir . '/' . self::RENAMED)) {
            return [];
        }
        $lock = @fopen($this->dir . '/' . self::RENAMED . '.lock', 'c');
        if ($lock === false) {
            return [];
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return [];
        }
        try {
            $renamed = [];
            if ($this->renameIn($this->dir, $renamed)) {
                @touch($this->dir . '/' . self::RENAMED);
            }
            return $renamed;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param array<string, string> $renamed
     * @return bool Whether every file below $folder has the suffix now.
     */
    private function renameIn(string $folder, array &$renamed): bool
    {
        $complete = true;
        foreach (scandir($folder) ?: [] as $name) {
            $path = $folder . '/' . $name;
            // Hidden names are this class's own files (and never an original); links are not
            // followed out of the folder.
            if ($name[0] === '.' || is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $complete = $this->renameIn($path, $renamed) && $complete;
                continue;
            }
            if (!is_file($path) || str_ends_with($name, self::SUFFIX)) {
                continue;
            }
            $target = $folder . '/' . self::freeName($folder, $name, true);
            if (@rename($path, $target)) {
                $renamed[$path] = $target;
            } else {
                $complete = false;
            }
        }
        return $complete;
    }
}
