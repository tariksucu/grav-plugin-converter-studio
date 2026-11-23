<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Data\Data;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Plugin;
use Grav\Framework\Mime\MimeTypes;
use Grav\Plugin\ConverterStudio\Job;
use Grav\Plugin\ConverterStudio\Rules;
use Grav\Plugin\ConverterStudio\Services;
use Grav\Plugin\ConverterStudio\References;
use RocketTheme\Toolbox\Event\Event;

/**
 * Converter Studio: converts the files that browsers and Grav cannot use (iPhone HEIC photos,
 * camera RAW, TIFF, old video formats, Office documents, uncompressed audio) into JPG, WebP, MP4,
 * PDF or MP3 through the Converter Studio API, when they are uploaded in the Admin and with the
 * `bin/plugin converter-studio convert` command.
 *
 * Only Admin uploads are converted, never visitors' uploads through front-end forms (a
 * conversion spends the site's credits).
 */
class ConverterStudioPlugin extends Plugin
{
    /** Kept equal to blueprints.yaml (a unit test checks it). */
    public const VERSION = '1.0.0';

    /** @var array<string, true> Files already handled in this request (Grav 2 fires two events per upload). */
    private array $handled = [];

    private ?Services $services = null;

    /**
     * Subscribed statically: under Admin 2 the admin context only exists once the API has
     * dispatched its route, long after plugins are initialised.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            // Classic Admin (Grav 1.7) and the API (Grav 2) both fire it after a page upload.
            'onAdminAfterAddMedia' => ['onAdminAfterAddMedia', 0],
            // The API (Grav 2): page and site media uploads, with the stored names.
            'onApiMediaUploaded' => ['onApiMediaUploaded', 0],
            'onAdminSave' => ['onAdminSave', 0],
            'onSchedulerInitialized' => ['onSchedulerInitialized', 0],
        ];
    }

    /**
     * The plugin's classes (PSR-4 under classes/; the plugin has no dependencies of its own).
     */
    public function autoload(): ClassLoader
    {
        $loader = new ClassLoader();
        $loader->addPsr4('Grav\\Plugin\\ConverterStudio\\', __DIR__ . '/classes/');
        $loader->register();
        return $loader;
    }

    /**
     * Lets the Admin accept the file types the switched-on groups convert (Grav 1.7 refuses an
     * extension it has no media type for, HEIC and camera RAW among them).
     */
    public function onPluginsInitialized(): void
    {
        $groups = $this->services()->settings()->uploadGroups();
        if ($groups === []) {
            return;
        }
        $added = Rules::mediaTypes($groups, (array) $this->config->get('media.types', []));
        if ($added === []) {
            return;
        }
        foreach ($added as $extension => $type) {
            $this->config->set('media.types.' . $extension, $type);
        }
        foreach (Rules::browserMimeTypes($added) as $extension => $mimes) {
            $known = (array) $this->config->get('mime.types.' . $extension, []);
            $this->config->set('mime.types.' . $extension, array_values(array_unique(array_merge($known, $mimes))));
        }
        // Grav's MIME list (which the classic Admin checks uploads against) may already have been
        // built from the configuration before these types were added: build it again, as Grav does.
        if (class_exists(MimeTypes::class)) {
            unset($this->grav['mime']);
            $this->grav['mime'] = static function ($container): MimeTypes {
                $config = $container['config'];
                $mimes = (array) $config->get('mime.types', []);
                foreach ((array) $config->get('media.types', []) as $extension => $media) {
                    if (!empty($media['mime'])) {
                        $mimes[$extension] = array_unique(array_merge([$media['mime']], $mimes[$extension] ?? []));
                    }
                }
                return MimeTypes::createFromMimes($mimes);
            };
        }
    }

    /**
     * After an Admin page upload: converts the new files of the page's folder.
     */
    public function onAdminAfterAddMedia(Event $event): void
    {
        // Through the API (Admin 2), onApiMediaUploaded follows with the stored names.
        $admin = $this->grav['admin'] ?? null;
        if (is_object($admin) && $admin instanceof \Grav\Plugin\Api\AdminProxy) {
            return;
        }
        $page = $event['page'] ?? $event['object'] ?? null;
        if ($page instanceof PageInterface) {
            $this->convertUploads((string) $page->path(), null);
        }
    }

    /**
     * After an upload through the API (Admin 2): converts the stored files, in a page's folder or
     * in the site's media folder (user/media).
     */
    public function onApiMediaUploaded(Event $event): void
    {
        $names = array_map('strval', (array) ($event['filenames'] ?? []));
        $page = $event['page'] ?? null;
        if ($page instanceof PageInterface) {
            $this->convertUploads((string) $page->path(), $names);
            return;
        }
        $media = $this->grav['locator']->findResource('user://media', true);
        if (is_string($media) && $media !== '') {
            $path = trim((string) ($event['path'] ?? ''), '/');
            $folder = $path === '' ? $media : $media . '/' . $path;
            // The API has already confined `path` to the media folder; check again all the same.
            $real = realpath($folder);
            if ($real !== false && str_starts_with($real . '/', rtrim((string) realpath($media), '/') . '/')) {
                $this->convertUploads($real, $names);
            }
        }
    }

    /**
     * Before a page is saved in the Admin: links to a file that was converted after it was
     * inserted (`![](photo.heic)`) point to the converted file (`![](photo.jpg)`).
     */
    public function onAdminSave(Event $event): void
    {
        $page = $event['page'] ?? $event['object'] ?? null;
        if ($page instanceof Data) {
            $this->keepApiKey($page);
            return;
        }
        if (!$page instanceof PageInterface) {
            return;
        }
        $services = $this->services();
        if (!$services->settings()->fixReferences()) {
            return;
        }
        $folder = (string) $page->path();
        $relative = $folder !== '' ? $services->converter()->relative($folder) : null;
        if ($relative === null) {
            return;
        }
        $renames = array_filter(
            $services->references()->forFolder($relative),
            static fn (string $new, string $old): bool => !file_exists($folder . '/' . $old) && file_exists($folder . '/' . $new),
            ARRAY_FILTER_USE_BOTH,
        );
        if ($renames === []) {
            return;
        }
        $raw = (string) $page->rawMarkdown();
        $rewritten = References::rewrite($raw, $renames);
        if ($rewritten !== $raw) {
            $page->rawMarkdown($rewritten);
        }
        $header = json_decode((string) json_encode($page->header()), true);
        if (is_array($header)) {
            $fixed = References::rewriteHeader($header, $renames);
            if ($fixed !== $header) {
                $page->header((object) $fixed);
            }
        }
    }

    /**
     * The classic Admin never shows a saved password field and sends it back empty, which would
     * erase the API key on every save of the settings: an empty key keeps the saved one. (To remove
     * it, delete it from user/config/plugins/converter-studio.yaml.)
     */
    private function keepApiKey(Data $data): void
    {
        $file = $data->file();
        $name = $file !== null ? str_replace('\\', '/', (string) $file->filename()) : '';
        if (!str_ends_with($name, '/plugins/converter-studio.yaml')) {
            return;
        }
        $submitted = $data->get('api_key');
        $saved = (string) $this->config->get('plugins.converter-studio.api_key', '');
        if (($submitted === null || $submitted === '') && $saved !== '') {
            $data->set('api_key', $saved);
        }
    }

    /**
     * Grav's scheduler (bin/grav scheduler, run by cron every minute) finishes the conversions
     * that outlast an upload, such as videos.
     */
    public function onSchedulerInitialized(Event $event): void
    {
        if (!$this->services()->settings()->scheduler()) {
            return;
        }
        $scheduler = $event['scheduler'] ?? null;
        if (is_object($scheduler) && method_exists($scheduler, 'addFunction')) {
            $job = $scheduler->addFunction('Grav\Plugin\ConverterStudio\Services::scheduled', [], 'converter-studio');
            $job->at('* * * * *');
            $job->backlink('/plugins/converter-studio');
        }
    }

    /**
     * Converts the files just uploaded into $folder, within the time an upload may wait, and tells
     * the person uploading what happened. A file that cannot be converted stays as it was
     * uploaded; the error is shown in the uploader.
     *
     * @param string[]|null $names
     */
    private function convertUploads(string $folder, ?array $names): void
    {
        $services = $this->services();
        $settings = $services->settings();
        $groups = $settings->uploadGroups();
        if ($folder === '' || $groups === [] || !is_dir($folder)) {
            return;
        }
        // Without names (the classic Admin), the files written during this request.
        $since = (int) ($_SERVER['REQUEST_TIME'] ?? time()) - 2;
        if ($settings->apiKey() === null) {
            $files = [];
            foreach ($services->worker()->candidates($folder, $names, $since) as $file => $group) {
                if (!isset($this->handled[$file])) {
                    $this->handled[$file] = true;
                    $files[] = basename($file);
                }
            }
            if ($files !== []) {
                $this->tell([], [sprintf(
                    '%s %s not converted: no Converter Studio API key is set. Add one in the plugin settings.',
                    implode(', ', $files),
                    count($files) === 1 ? 'was' : 'were',
                )]);
            }
            return;
        }
        $wait = $settings->uploadWait();
        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0 && $limit < $wait + 30 && function_exists('set_time_limit')) {
            // The upload request has to outlast the conversions it waits for.
            @set_time_limit($wait + 30);
        }
        if ($names !== null) {
            $names = array_values(array_filter($names, fn (string $name): bool => !isset($this->handled[$folder . '/' . basename($name)])));
        }
        $jobs = $services->worker()->uploaded($folder, $names, $since, microtime(true) + $wait);
        $notes = [];
        $problems = [];
        foreach ($jobs as $job) {
            $file = $services->converter()->absolute($job->path());
            if (isset($this->handled[$file])) {
                continue;
            }
            $this->handled[$file] = true;
            if ($job->state() === Job::FAILED || $job->state() === Job::QUOTED) {
                $problems[] = $job->describe();
            } else {
                $notes[] = $job->describe() . ($job->pending() && !$settings->scheduler() ? ' Run: bin/plugin converter-studio work' : '');
            }
        }
        $this->tell($notes, $problems);
    }

    /**
     * Shows what happened: Grav's messages (the classic Admin shows them), the log, and for
     * problems an error the uploader shows (the file itself was stored all the same).
     *
     * @param string[] $notes
     * @param string[] $problems
     */
    private function tell(array $notes, array $problems): void
    {
        $messages = $this->grav['messages'] ?? null;
        foreach ($notes as $note) {
            if (is_object($messages) && method_exists($messages, 'add')) {
                $messages->add($note, 'info');
            }
        }
        if ($problems === []) {
            return;
        }
        // The conversion itself has been logged already.
        $text = implode(' ', $problems);
        $detail = 'Saved as uploaded. ' . $text;
        // Grav 2's API (Admin 2) shows an ApiException's detail; the classic Admin the message.
        $api = 'Grav\Plugin\Api\Exceptions\ApiException';
        if (class_exists($api)) {
            throw new $api(422, 'Converter Studio', $detail);
        }
        throw new \RuntimeException($detail, 400);
    }

    private function services(): Services
    {
        return $this->services ??= Services::fromGrav($this->grav);
    }
}
