<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio;

use Grav\Common\Grav;
use Grav\Common\HTTP\Client as GravHttp;
use Grav\Plugin\ConverterStudioPlugin;
use Grav\Plugin\ConverterStudio\Api\Client;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The plugin's parts, put together from a Grav instance. Only the settings are read up front;
 * the API client (and its HTTP client) is made the first time a conversion needs it.
 */
final class Services
{
    /**
     * Seconds one scheduler run (every minute) spends on unfinished conversions. Grav runs the
     * job inline, so the other jobs of that minute wait for it; what is left goes on next minute.
     */
    public const SCHEDULER_BUDGET = 20;

    private ?Converter $converter = null;

    private ?Worker $worker = null;

    private readonly References $references;

    /**
     * @param \Closure(References): Converter $makeConverter
     * @param \Closure(string, string): void $log
     */
    private function __construct(
        private readonly Settings $settings,
        private readonly string $userDir,
        private readonly string $dataDir,
        private readonly \Closure $makeConverter,
        private readonly \Closure $log,
    ) {
        $this->references = new References($dataDir . '/renames.json');
    }

    public static function fromGrav(Grav $grav): self
    {
        $settings = Settings::fromArray((array) $grav['config']->get('plugins.converter-studio', []));
        $locator = $grav['locator'];
        $userDir = rtrim((string) $locator->findResource('user://', true), '/');
        $dataDir = rtrim((string) $locator->findResource('user://data', true, true), '/') . '/converter-studio';
        $log = static function (string $level, string $message) use ($grav): void {
            $grav['log']->{$level === 'info' ? 'info' : 'warning'}($message);
        };
        return self::create(
            $settings,
            $userDir,
            $dataDir,
            static fn (References $references): Converter => new Converter(
                new Client(self::http(), $settings->apiKey(), $settings->apiUrl(), ConverterStudioPlugin::VERSION),
                new JobStore($dataDir . '/jobs'),
                $references,
                $userDir,
                $dataDir . '/originals',
                $log,
            ),
            $log,
        );
    }

    /**
     * The parts from their pieces (fromGrav() for a site; tests pass their own converter).
     *
     * @param \Closure(References): Converter $makeConverter Called once, when first needed.
     * @param (\Closure(string, string): void)|null $log
     */
    public static function create(Settings $settings, string $userDir, string $dataDir, \Closure $makeConverter, ?\Closure $log = null): self
    {
        $services = new self($settings, $userDir, $dataDir, $makeConverter, $log ?? static function (string $level, string $message): void {
        });
        $services->renameOldOriginals();
        return $services;
    }

    /**
     * Grav's own HTTP client when there is one (it honours the site's proxy and certificate
     * settings), without Grav's default headers: nothing about the site is sent.
     */
    private static function http(): HttpClientInterface
    {
        if (class_exists(GravHttp::class)) {
            return GravHttp::getClient(['headers' => []]);
        }
        return HttpClient::create();
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    public function converter(): Converter
    {
        return $this->converter ??= ($this->makeConverter)($this->references);
    }

    public function references(): References
    {
        return $this->references;
    }

    public function worker(): Worker
    {
        return $this->worker ??= new Worker($this->converter(), $this->settings);
    }

    /**
     * Whether the API client has been made (tests check that reading the settings never does).
     */
    public function started(): bool
    {
        return $this->converter !== null;
    }

    /**
     * The site's user folder (jobs keep paths relative to it).
     */
    public function userDir(): string
    {
        return $this->userDir;
    }

    /**
     * A path relative to the site's user folder, or null when it is outside it.
     */
    public function relative(string $path): ?string
    {
        return Converter::relativeTo($this->userDir, $path);
    }

    /**
     * The originals kept by version 1.0.0 under their own names get the suffix that keeps the web
     * server from serving them (see Originals), the first time the plugin works after the update.
     */
    private function renameOldOriginals(): void
    {
        $renamed = (new Originals($this->dataDir . '/originals'))->migrate();
        if ($renamed !== []) {
            ($this->log)('info', sprintf(
                'Converter Studio: %d kept original%s renamed to end in %s, which the web server does not serve.',
                count($renamed),
                count($renamed) === 1 ? '' : 's',
                Originals::SUFFIX,
            ));
        }
    }

    /**
     * The scheduler's job: finishes unfinished conversions for up to SCHEDULER_BUDGET seconds
     * and forgets old finished ones.
     */
    public static function scheduled(): string
    {
        return self::fromGrav(Grav::instance())->runScheduled();
    }

    public function runScheduled(): string
    {
        if (!$this->settings->enabled()) {
            return 'Converter Studio is disabled.';
        }
        $converter = $this->converter();
        $jobs = $this->worker()->pending($converter->now() + self::SCHEDULER_BUDGET);
        $converter->jobs()->prune();
        $lines = array_map(static fn (Job $job): string => $job->describe(), $jobs);
        return $lines === [] ? 'Nothing to convert.' : implode("\n", $lines);
    }
}
