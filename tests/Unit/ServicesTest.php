<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio\Tests\Unit;

use Grav\Plugin\ConverterStudio\Api\Client;
use Grav\Plugin\ConverterStudio\Converter;
use Grav\Plugin\ConverterStudio\Job;
use Grav\Plugin\ConverterStudio\JobStore;
use Grav\Plugin\ConverterStudio\References;
use Grav\Plugin\ConverterStudio\Services;
use Grav\Plugin\ConverterStudio\Settings;
use Grav\Plugin\ConverterStudio\Tests\FakeApi;
use Grav\Plugin\ConverterStudio\Tests\FakeClock;
use PHPUnit\Framework\TestCase;

final class ServicesTest extends TestCase
{
    private FakeApi $api;
    private FakeClock $clock;
    private string $root;
    private string $page;
    /** @var array<int, array{0: string, 1: string}> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->api = new FakeApi();
        $this->clock = new FakeClock();
        $this->root = sys_get_temp_dir() . '/cs-grav-services-' . bin2hex(random_bytes(4));
        $this->page = $this->root . '/pages/04.videos';
        mkdir($this->page, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function services(): Services
    {
        $log = function (string $level, string $message): void {
            $this->log[] = [$level, $message];
        };
        return Services::create(
            Settings::fromArray(['enabled' => true, 'rules' => ['video' => ['upload' => true]]]),
            $this->root,
            $this->root . '/data/converter-studio',
            fn (References $references): Converter => new Converter(
                new Client($this->api->client(), 'cvs_live_secret', FakeApi::BASE, '1.0.1'),
                new JobStore($this->root . '/data/converter-studio/jobs'),
                $references,
                $this->root,
                $this->root . '/data/converter-studio/originals',
                null,
                $this->clock->clock(),
                $this->clock->sleeper(),
            ),
            $log,
        );
    }

    public function testTheSchedulerJobStopsAfterAbout20SecondsAndGoesOnNextRun(): void
    {
        // A long video: the API says "processing" for a minute of checks.
        $this->api->script = array_merge(array_fill(0, 60, 'processing'), ['done']);
        $services = $this->services();
        file_put_contents($this->page . '/a.mov', 'video a');
        file_put_contents($this->page . '/b.mov', 'video b');
        // Both uploaded, left converting after the upload's wait.
        $services->worker()->uploaded($this->page, ['a.mov', 'b.mov'], 0, $this->clock->now() + 4);

        $started = $this->clock->now();
        $output = $services->runScheduled();
        $spent = $this->clock->now() - $started;

        self::assertLessThanOrEqual(20, Services::SCHEDULER_BUDGET);
        self::assertLessThanOrEqual(Services::SCHEDULER_BUDGET, $spent);
        self::assertGreaterThan(10, $spent);
        self::assertStringContainsString('still converting', $output);
        $store = new JobStore($this->root . '/data/converter-studio/jobs');
        self::assertCount(2, array_filter($store->all(), static fn (Job $job): bool => $job->pending()));

        // The next runs (once each job's pause is over) go on where this one stopped.
        for ($run = 0; $run < 12 && !(is_file($this->page . '/a.mp4') && is_file($this->page . '/b.mp4')); $run++) {
            foreach ($store->all() as $job) {
                $job->data['next_check'] = 0;
                $store->save($job);
            }
            $started = $this->clock->now();
            $services->runScheduled();
            self::assertLessThanOrEqual(Services::SCHEDULER_BUDGET, $this->clock->now() - $started);
        }
        self::assertFileExists($this->page . '/a.mp4');
        self::assertFileExists($this->page . '/b.mp4');
        self::assertCount(2, $this->api->made('POST /v1/uploads'));
    }

    public function testOriginalsKeptBy100AreRenamedWhenThePluginStarts(): void
    {
        $kept = $this->root . '/data/converter-studio/originals/pages/04.videos';
        mkdir($kept, 0777, true);
        file_put_contents($kept . '/IMG_0042.MOV', 'kept by 1.0.0');

        $services = $this->services();

        self::assertSame('kept by 1.0.0', file_get_contents($kept . '/IMG_0042.MOV.original.txt'));
        self::assertFileDoesNotExist($kept . '/IMG_0042.MOV');
        self::assertSame('info', $this->log[0][0]);
        self::assertStringContainsString('1 kept original renamed to end in .original.txt', $this->log[0][1]);
        // Reading the settings and renaming never made the API client.
        self::assertFalse($services->started());
        self::assertSame([], $this->api->requests);
    }

    public function testADisabledPluginDoesNothingOnSchedule(): void
    {
        $services = Services::create(
            Settings::fromArray(['enabled' => false]),
            $this->root,
            $this->root . '/data/converter-studio',
            static function (): Converter {
                throw new \LogicException('not needed');
            },
        );

        self::assertSame('Converter Studio is disabled.', $services->runScheduled());
        self::assertFalse($services->started());
    }
}
