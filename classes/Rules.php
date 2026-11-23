<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio;

/**
 * The kinds of files this plugin converts, what each becomes, and the API options it sends.
 *
 * Each group is switched on for uploads on its own (nothing converts until a group is on), and the
 * convert command takes the same groups.
 */
final class Rules
{
    /**
     * from: file extensions (lower case); to: allowed targets, the first is the default;
     * kind: which options apply; mime: media types Grav does not know yet (so the Admin accepts
     * the upload and the media list shows the file).
     */
    public const GROUPS = [
        'heic' => [
            'from' => ['heic', 'heif'],
            'to' => ['jpg', 'webp', 'png'],
            'kind' => 'image',
            'mime' => ['heic' => 'image/heic', 'heif' => 'image/heif'],
        ],
        'raw' => [
            'from' => ['dng', 'cr2', 'nef', 'arw'],
            'to' => ['jpg', 'webp', 'png'],
            'kind' => 'image',
            'mime' => [
                'dng' => 'image/x-adobe-dng',
                'cr2' => 'image/x-canon-cr2',
                'nef' => 'image/x-nikon-nef',
                'arw' => 'image/x-sony-arw',
            ],
        ],
        'tiff' => [
            'from' => ['tif', 'tiff', 'bmp'],
            'to' => ['jpg', 'webp', 'png'],
            'kind' => 'image',
            'mime' => ['tif' => 'image/tiff'],
        ],
        'video' => [
            'from' => ['mov', 'avi', 'mkv', 'wmv', 'flv', '3gp'],
            'to' => ['mp4', 'webm'],
            'kind' => 'video',
            'mime' => ['mkv' => 'video/x-matroska', '3gp' => 'video/3gpp'],
        ],
        'office' => [
            'from' => ['doc', 'docx', 'odt', 'rtf', 'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'odp'],
            'to' => ['pdf'],
            'kind' => 'document',
            'mime' => [
                'odt' => 'application/vnd.oasis.opendocument.text',
                'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
                'odp' => 'application/vnd.oasis.opendocument.presentation',
            ],
        ],
        'audio' => [
            'from' => ['wav', 'aif', 'aiff', 'flac', 'wma'],
            'to' => ['mp3'],
            'kind' => 'audio',
            'mime' => ['flac' => 'audio/flac'],
        ],
    ];

    /** Values the settings may take, and what the API calls them. */
    public const QUALITIES = ['high', 'balanced', 'small'];
    public const LONG_SIDES = ['2560', '1920', '1280'];
    public const VIDEO_HEIGHTS = ['1080', '720', '480'];
    public const AUDIO_BITRATES = ['128', '192', '256', '320'];

    /**
     * The group a file belongs to, by its extension; null when none.
     */
    public static function groupOf(string $filename): ?string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === '') {
            return null;
        }
        foreach (self::GROUPS as $name => $group) {
            if (in_array($extension, $group['from'], true)) {
                return $name;
            }
        }
        return null;
    }

    /**
     * Whether a group exists.
     */
    public static function exists(string $group): bool
    {
        return isset(self::GROUPS[$group]);
    }

    /**
     * The target of a group: the one asked for when the group allows it, otherwise its default.
     */
    public static function target(string $group, ?string $asked): string
    {
        $targets = self::GROUPS[$group]['to'];
        $asked = strtolower((string) $asked);
        return in_array($asked, $targets, true) ? $asked : $targets[0];
    }

    /**
     * The API options for a group, from the plugin's settings.
     *
     * @param array<string, mixed> $settings The plugin's "results" settings.
     * @return array<string, mixed>
     */
    public static function options(string $group, string $to, array $settings): array
    {
        $options = [];
        switch (self::GROUPS[$group]['kind']) {
            case 'image':
                $quality = (string) ($settings['image_quality'] ?? 'balanced');
                // PNG is lossless: a quality setting means nothing to it.
                if ($to !== 'png' && in_array($quality, self::QUALITIES, true)) {
                    $options['quality'] = $quality;
                }
                $side = (string) ($settings['image_long_side'] ?? '');
                if (in_array($side, self::LONG_SIDES, true)) {
                    $options['longSide'] = $side;
                }
                if (!empty($settings['keep_metadata'])) {
                    $options['keepMetadata'] = true;
                }
                break;
            case 'video':
                $height = (string) ($settings['video_height'] ?? '1080');
                if (in_array($height, self::VIDEO_HEIGHTS, true)) {
                    $options['height'] = $height;
                }
                $quality = (string) ($settings['video_quality'] ?? 'balanced');
                if (in_array($quality, self::QUALITIES, true)) {
                    $options['quality'] = $quality;
                }
                break;
            case 'audio':
                $bitrate = (string) ($settings['audio_bitrate'] ?? '192');
                if (in_array($bitrate, self::AUDIO_BITRATES, true)) {
                    $options['bitrate'] = $bitrate;
                }
                break;
        }
        return $options;
    }

    /**
     * Media types to add to Grav's list for the groups that convert uploads, so the Admin accepts
     * those files (Grav 1.7 refuses an extension it does not know) and lists them as media.
     *
     * @param string[] $groups
     * @param array<string, mixed> $known Grav's current media types (`media.types`).
     * @return array<string, array{type: string, thumb: string, mime: string}>
     */
    public static function mediaTypes(array $groups, array $known): array
    {
        $add = [];
        foreach ($groups as $group) {
            foreach (self::GROUPS[$group]['mime'] ?? [] as $extension => $mime) {
                if (!isset($known[$extension])) {
                    $add[$extension] = ['type' => 'file', 'thumb' => 'media/thumb.png', 'mime' => $mime];
                }
            }
        }
        return $add;
    }

    /**
     * The MIME types a browser may give for each of those extensions. Grav 1.7's Admin refuses an
     * upload whose browser-given type does not belong to its extension, and browsers give a
     * generic type for files their system does not know (HEIC on Windows, camera RAW).
     *
     * @param array<string, array{mime: string}> $added What mediaTypes() returned.
     * @return array<string, string[]>
     */
    public static function browserMimeTypes(array $added): array
    {
        $types = [];
        foreach ($added as $extension => $type) {
            $list = [$type['mime']];
            if ($extension === 'heic' || $extension === 'heif') {
                array_push($list, 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence');
            }
            $list[] = 'application/octet-stream';
            $types[$extension] = array_values(array_unique($list));
        }
        return $types;
    }
}
