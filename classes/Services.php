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
 * The plugin's parts, put together from a Grav instance.
 */
final class Services
{
    /** Seconds one scheduler run (every minute) spends on unfinished conversions. */
    private const SCHEDULER_BUDGET = 50;

    private function __construct(
        private readonly Settings $settings,
        private readonly Converter $converter,
        private readonly References $references,
        private readonly Worker $worker,
        private readonly string $userDir,
    ) {
    }

    public static function fromGrav(Grav $grav): self
    {
        $settings = Settings::fromArray((array) $grav['config']->get('plugins.converter-studio', []));
        $locator = $grav['locator'];
        $userDir = rtrim((string) $locator->findResource('user://', true), '/');
        $dataDir = rtrim((string) $locator->findResource('user://data', true, true), '/') . '/converter-studio';
        $client = new Client(self::http(), $settings->apiKey(), $settings->apiUrl(), ConverterStudioPlugin::VERSION);
        $references = new References($dataDir . '/renames.json');
        $log = static function (string $level, string $message) use ($grav): void {
            $grav['log']->{$level === 'info' ? 'info' : 'warning'}($message);
        };
        $converter = new Converter($client, new JobStore($dataDir . '/jobs'), $references, $userDir, $dataDir . '/originals', $log);
        return new self($settings, $converter, $references, new Worker($converter, $settings), $userDir);
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
        return $this->converter;
    }

    public function references(): References
    {
        return $this->references;
    }

    public function worker(): Worker
    {
        return $this->worker;
    }

    /**
     * The site's user folder (jobs keep paths relative to it).
     */
    public function userDir(): string
    {
        return $this->userDir;
    }

    /**
     * The scheduler's job: finishes unfinished conversions for up to 50 seconds and forgets old
     * finished ones.
     */
    public static function scheduled(): string
    {
        $services = self::fromGrav(Grav::instance());
        if (!$services->settings->enabled()) {
            return 'Converter Studio is disabled.';
        }
        $jobs = $services->worker->pending(microtime(true) + self::SCHEDULER_BUDGET);
        $services->converter->jobs()->prune();
        $lines = array_map(static fn (Job $job): string => $job->describe(), $jobs);
        return $lines === [] ? 'Nothing to convert.' : implode("\n", $lines);
    }
}
