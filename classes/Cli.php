<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio;

use Grav\Common\Grav;

/**
 * What the plugin's commands (bin/plugin converter-studio …) share.
 */
final class Cli
{
    /**
     * The folders of every page under user/pages (and the pages folder itself).
     *
     * @return string[]
     */
    public static function pageFolders(Grav $grav): array
    {
        $pages = $grav['locator']->findResource('user://pages', true);
        return is_string($pages) && is_dir($pages) ? self::folders($pages) : [];
    }

    /**
     * The site media folder (user/media, Admin 2's Media page) and its subfolders.
     *
     * @return string[]
     */
    public static function mediaFolders(Grav $grav): array
    {
        $media = $grav['locator']->findResource('user://media', true);
        return is_string($media) && is_dir($media) ? self::folders($media) : [];
    }

    /**
     * $dir and every folder below it, hidden ones left out.
     *
     * @return string[]
     */
    public static function folders(string $dir): array
    {
        $found = [rtrim($dir, '/')];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name[0] === '.' || is_link($dir . '/' . $name) || !is_dir($dir . '/' . $name)) {
                continue;
            }
            array_push($found, ...self::folders($dir . '/' . $name));
        }
        return $found;
    }

    /**
     * Groups from --format values ("heic", "heic,video", "all"); every unknown name is reported.
     *
     * @param string[] $values
     * @return array{0: string[], 1: string[]} The groups, and the names that are not groups.
     */
    public static function groups(array $values): array
    {
        $groups = [];
        $unknown = [];
        foreach ($values as $value) {
            foreach (preg_split('/[\s,]+/', strtolower($value)) ?: [] as $name) {
                if ($name === '') {
                    continue;
                }
                if ($name === 'all') {
                    $groups = array_merge($groups, array_keys(Rules::GROUPS));
                } elseif (Rules::exists($name)) {
                    $groups[] = $name;
                } else {
                    $unknown[] = $name;
                }
            }
        }
        return [array_values(array_unique($groups)), $unknown];
    }

    /**
     * A size for people.
     */
    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $unit = 0;
        while ($size >= 1000 && $unit < count($units) - 1) {
            $size /= 1000;
            $unit++;
        }
        return ($unit === 0 ? (string) $bytes : number_format($size, $size < 10 ? 1 : 0)) . ' ' . $units[$unit];
    }
}
