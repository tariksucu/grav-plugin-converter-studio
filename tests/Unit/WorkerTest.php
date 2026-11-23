<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio\Tests\Unit;

use Grav\Plugin\ConverterStudio\Api\Client;
use Grav\Plugin\ConverterStudio\Converter;
use Grav\Plugin\ConverterStudio\Job;
use Grav\Plugin\ConverterStudio\JobStore;
use Grav\Plugin\ConverterStudio\References;
use Grav\Plugin\ConverterStudio\Settings;
use Grav\Plugin\ConverterStudio\Tests\FakeApi;
use Grav\Plugin\ConverterStudio\Tests\FakeClock;
use Grav\Plugin\ConverterStudio\Worker;
use PHPUnit\Framework\TestCase;

final class WorkerTest extends TestCase
{
    private FakeApi $api;
    private FakeClock $clock;
    private string $root;
    private string $page;

    protected function setUp(): void
    {
        $this->api = new FakeApi();
        $this->clock = new FakeClock();
        $this->root = sys_get_temp_dir() . '/cs-grav-worker-' . bin2hex(random_bytes(4));
        $this->page = $this->root . '/pages/02.gallery';
        mkdir($this->page, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function worker(array $config): Worker
    {
        $settings = Settings::fromArray($config + ['enabled' => true, 'originals' => 'keep']);
        $converter = new Converter(
            new Client($this->api->client(), 'cvs_live_secret', FakeApi::BASE, '1.0.0'),
            new JobStore($this->root . '/data/converter-studio/jobs'),
            new References($this->root . '/data/converter-studio/renames.json'),
            $this->root,
            $this->root . '/data/converter-studio/originals',
            null,
            $this->clock->clock(),
            $this->clock->sleeper(),
        );
        return new Worker($converter, $settings);
    }

    private function file(string $name, int $mtime = 0): string
    {
        $file = $this->page . '/' . $name;
        file_put_contents($file, 'bytes of ' . $name);
        if ($mtime > 0) {
            touch($file, $mtime);
        }
        return $file;
    }

    public function testOnlyNewUploadsOfSwitchedOnKindsConvert(): void
    {
        $worker = $this->worker(['rules' => ['heic' => ['upload' => true], 'video' => ['upload' => false]]]);
        $this->file('old.heic', time() - 3600);
        $this->file('new.heic');
        $this->file('clip.mov');
        $this->file('cat.jpg');

        $jobs = $worker->uploaded($this->page, null, time() - 60, $this->clock->now() + 60);

        self::assertCount(1, $jobs);
        self::assertSame(Job::DONE, $jobs[0]->state());
        self::assertFileExists($this->page . '/new.jpg');
        self::assertFileExists($this->page . '/old.heic');
        self::assertFileExists($this->page . '/clip.mov');
    }

    public function testNamedUploadsAreTakenAsGiven(): void
    {
        $worker = $this->worker(['rules' => ['heic' => ['upload' => true, 'to' => 'webp']]]);
        $this->file('a.heic', time() - 3600);
        $this->file('b.heic');

        $jobs = $worker->uploaded($this->page, ['a.heic', '../../escape.heic'], time(), $this->clock->now() + 60);

        self::assertCount(1, $jobs);
        self::assertFileExists($this->page . '/a.webp');
        self::assertFileExists($this->page . '/b.heic');
        self::assertSame('webp', json_decode($this->api->made('POST /v1/conversions')[0]['body'], true)['to']);
    }

    public function testNothingHappensWhenNoKindIsOn(): void
    {
        $this->file('a.heic');

        self::assertSame([], $this->worker([])->uploaded($this->page, null, 0, $this->clock->now() + 60));
        self::assertSame([], $this->api->requests);
    }

    public function testAnUploadAlreadyConvertedIsNotConvertedTwice(): void
    {
        $worker = $this->worker(['rules' => ['heic' => ['upload' => true]], 'originals' => 'beside']);
        $this->file('a.heic');

        $worker->uploaded($this->page, ['a.heic'], 0, $this->clock->now() + 60);
        $again = $worker->uploaded($this->page, ['a.heic'], 0, $this->clock->now() + 60);

        self::assertSame(Job::DONE, $again[0]->state());
        self::assertCount(1, $this->api->made('POST /v1/uploads'));
        self::assertFileDoesNotExist($this->page . '/a-1.jpg');
    }

    public function testAnUploaderRetryGetsTheSameFailureWithoutANewConversion(): void
    {
        $this->api->script = ['failed'];
        $worker = $this->worker(['rules' => ['heic' => ['upload' => true]]]);
        $file = $this->file('broken.heic');

        $first = $worker->uploaded($this->page, ['broken.heic'], 0, $this->clock->now() + 60);
        // Admin 2's uploader sends the refused file again: same bytes, a new modification time.
        touch($file, time() + 2);
        $retry = $worker->uploaded($this->page, ['broken.heic'], 0, $this->clock->now() + 60);

        self::assertSame(Job::FAILED, $first[0]->state());
        self::assertSame($first[0]->id(), $retry[0]->id());
        self::assertCount(1, $this->api->made('POST /v1/uploads'));

        // Later, the same file is tried again.
        $store = new JobStore($this->root . '/data/converter-studio/jobs');
        $old = $store->get($first[0]->id());
        $old->data['updated'] = time() - 600;
        file_put_contents($store->dir() . '/' . $old->id() . '.json', json_encode($old->data));
        $later = $worker->uploaded($this->page, ['broken.heic'], 0, $this->clock->now() + 60);

        self::assertNotSame($first[0]->id(), $later[0]->id());
        self::assertCount(2, $this->api->made('POST /v1/uploads'));
    }

    public function testExistingFilesSkipThoseAlreadyConverted(): void
    {
        $worker = $this->worker([]);
        $this->file('a.heic');
        $this->file('b.heic');
        $this->file('b.jpg');
        $this->file('report.docx');

        $found = $worker->existing([$this->page], ['heic', 'office']);
        $forced = $worker->existing([$this->page], ['heic'], [], true);
        $webp = $worker->existing([$this->page], ['heic'], ['heic' => 'webp']);

        self::assertSame(['a.heic', 'report.docx'], array_map(static fn (array $item): string => basename($item['file']), $found));
        self::assertSame(['jpg', 'pdf'], array_column($found, 'to'));
        self::assertCount(2, $forced);
        self::assertCount(2, $webp);
    }

    public function testPendingJobsAreFinishedWithAPauseBetweenLooks(): void
    {
        $this->api->script = array_merge(array_fill(0, 10, 'processing'), ['done']);
        $worker = $this->worker(['rules' => ['heic' => ['upload' => true]]]);
        $this->file('a.heic');
        $jobs = $worker->uploaded($this->page, ['a.heic'], 0, $this->clock->now() + 4);
        self::assertTrue($jobs[0]->pending());

        $first = $worker->pending($this->clock->now() + 9);
        self::assertTrue($first[0]->pending());
        self::assertSame(1, $first[0]->data['checks']);
        self::assertGreaterThan(time() + 20, $first[0]->data['next_check']);

        // Not looked at again before its pause is over, unless asked.
        self::assertSame([], $worker->pending($this->clock->now() + 60));
        $now = $worker->pending($this->clock->now() + 60, true);
        self::assertSame(Job::DONE, $now[0]->state());
        self::assertFileExists($this->page . '/a.jpg');
    }

    public function testFinishedJobsAreForgottenAfterAWhile(): void
    {
        $store = new JobStore($this->root . '/jobs');
        $old = Job::create('pages/a.heic', 1, 1, 'heic', 'jpg', [], 20, 'keep');
        $old->data['state'] = Job::DONE;
        $store->save($old);
        $running = Job::create('pages/b.heic', 1, 1, 'heic', 'jpg', [], 20, 'keep');
        $store->save($running);

        self::assertSame(0, $store->prune());
        self::assertSame(1, $store->prune(time() + 8 * 86400));
        self::assertNull($store->get($old->id()));
        self::assertNotNull($store->get($running->id()));
        self::assertNull($store->get('../../etc/passwd'));
    }
}
