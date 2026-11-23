<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio\Tests\Unit;

use Grav\Plugin\ConverterStudio\Api\Client;
use Grav\Plugin\ConverterStudio\Converter;
use Grav\Plugin\ConverterStudio\Job;
use Grav\Plugin\ConverterStudio\JobStore;
use Grav\Plugin\ConverterStudio\References;
use Grav\Plugin\ConverterStudio\Tests\FakeApi;
use Grav\Plugin\ConverterStudio\Tests\FakeClock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ConverterTest extends TestCase
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
        $this->root = sys_get_temp_dir() . '/cs-grav-converter-' . bin2hex(random_bytes(4));
        $this->page = $this->root . '/pages/01.blog/post';
        mkdir($this->page, 0777, true);
    }

    protected function tearDown(): void
    {
        self::remove($this->root);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    self::remove($path . '/' . $name);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    private function converter(int $partBytes = Client::PART_BYTES): Converter
    {
        return new Converter(
            new Client($this->api->client(), 'cvs_live_secret', FakeApi::BASE, '1.0.0', $partBytes),
            new JobStore($this->root . '/data/converter-studio/jobs'),
            new References($this->root . '/data/converter-studio/renames.json'),
            $this->root,
            $this->root . '/data/converter-studio/originals',
            function (string $level, string $message): void {
                $this->log[] = [$level, $message];
            },
            $this->clock->clock(),
            $this->clock->sleeper(),
        );
    }

    private function photo(string $name = 'photo.heic', string $content = 'heic bytes'): string
    {
        $file = $this->page . '/' . $name;
        file_put_contents($file, $content);
        return $file;
    }

    private function job(Converter $converter, string $file, string $originals = 'keep', int $maxCredits = 20, string $to = 'jpg'): Job
    {
        $job = $converter->jobFor($file, 'heic', $to, ['quality' => 'balanced'], $maxCredits, $originals);
        self::assertNotNull($job);
        return $job;
    }

    public function testAPhotoIsConvertedAndItsOriginalKeptOutOfThePage(): void
    {
        $converter = $this->converter();
        $file = $this->photo();

        $job = $converter->run($this->job($converter, $file), $this->clock->now() + 60);

        self::assertSame(Job::DONE, $job->state());
        self::assertSame('converted bytes', file_get_contents($this->page . '/photo.jpg'));
        self::assertFileDoesNotExist($file);
        self::assertSame('heic bytes', file_get_contents($this->root . '/data/converter-studio/originals/pages/01.blog/post/photo.heic'));
        self::assertSame('pages/01.blog/post/photo.jpg', $job->data['result']);
        self::assertSame('photo.heic → photo.jpg (1 credit)', $job->describe());
        self::assertSame(['photo.heic' => 'photo.jpg'], (new References($this->root . '/data/converter-studio/renames.json'))->forFolder('pages/01.blog/post'));
        self::assertSame([], glob($this->page . '/.converter-studio-*') ?: []);
        self::assertSame('info', $this->log[0][0]);
        // What was asked: the right target and options, an upload key on the start request.
        $post = $this->api->made('POST /v1/conversions')[0];
        $upload = $this->api->made('POST /v1/uploads')[0];
        self::assertSame(['fileName' => 'photo.heic', 'size' => 10], json_decode($upload['body'], true));
        self::assertSame('jpg', json_decode($post['body'], true)['to']);
        self::assertSame(['quality' => 'balanced'], json_decode($post['body'], true)['options']);
        self::assertStringStartsWith('grav-up', $post['headers']['idempotency-key']);
    }

    public function testAnOriginalCanStayBesideOrGo(): void
    {
        $converter = $this->converter();
        $beside = $this->photo('a.heic');
        $deleted = $this->photo('b.heic', 'other bytes');

        $converter->run($this->job($converter, $beside, 'beside'), $this->clock->now() + 60);
        $converter->run($this->job($converter, $deleted, 'delete'), $this->clock->now() + 60);

        self::assertFileExists($beside);
        self::assertFileExists($this->page . '/a.jpg');
        self::assertFileDoesNotExist($deleted);
        self::assertFileExists($this->page . '/b.jpg');
        self::assertDirectoryDoesNotExist($this->root . '/data/converter-studio/originals');
        // A link to a.heic still works: only b.heic is remembered as renamed.
        self::assertSame(['b.heic' => 'b.jpg'], (new References($this->root . '/data/converter-studio/renames.json'))->forFolder('pages/01.blog/post'));
    }

    public function testAResultNeverOverwritesAFile(): void
    {
        $converter = $this->converter();
        file_put_contents($this->page . '/photo.jpg', 'another photo');
        $file = $this->photo();

        $job = $converter->run($this->job($converter, $file), $this->clock->now() + 60);

        self::assertSame('another photo', file_get_contents($this->page . '/photo.jpg'));
        self::assertSame('converted bytes', file_get_contents($this->page . '/photo-1.jpg'));
        self::assertSame('pages/01.blog/post/photo-1.jpg', $job->data['result']);
    }

    public function testTheMediaDetailsFollowTheConvertedFile(): void
    {
        $converter = $this->converter();
        $file = $this->photo();
        file_put_contents($file . '.meta.yaml', "alt_text: A cat\n");

        $converter->run($this->job($converter, $file), $this->clock->now() + 60);

        self::assertSame("alt_text: A cat\n", file_get_contents($this->page . '/photo.jpg.meta.yaml'));
        self::assertFileDoesNotExist($file . '.meta.yaml');
    }

    public function testAFailedConversionKeepsTheOriginalAndSaysWhy(): void
    {
        $this->api->script = ['processing', 'failed'];
        $this->api->failure = ['code' => 'conversionFailed', 'message' => 'This file is damaged and cannot be converted.'];
        $converter = $this->converter();
        $file = $this->photo();

        $job = $converter->run($this->job($converter, $file), $this->clock->now() + 60);

        self::assertSame(Job::FAILED, $job->state());
        self::assertFileExists($file);
        self::assertFileDoesNotExist($this->page . '/photo.jpg');
        self::assertSame('photo.heic was not converted: This file is damaged and cannot be converted.', $job->describe());
        self::assertSame('warning', $this->log[0][0]);
    }

    public function testAWrongKeyFailsWithTheApisMessage(): void
    {
        $this->api->overrides[] = ['POST /v1/uploads', $this->api->json(401, ['error' => ['code' => 'invalidApiKey', 'message' => 'This API key does not work. Make a new one on your account page.']])];
        $converter = $this->converter();

        $job = $converter->run($this->job($converter, $this->photo()), $this->clock->now() + 60);

        self::assertSame(Job::FAILED, $job->state());
        self::assertSame('invalidApiKey', $job->data['error']['code']);
        self::assertStringContainsString('This API key does not work.', $job->describe());
    }

    public function testAnExpensiveConversionWaitsForApprovalThenFinishes(): void
    {
        $this->api->credits = 31;
        $converter = $this->converter();
        $file = $this->photo('clip.mov', 'video bytes');

        $job = $converter->run($converter->jobFor($file, 'video', 'mp4', [], 20, 'keep'), $this->clock->now() + 60);

        self::assertSame(Job::QUOTED, $job->state());
        self::assertFileDoesNotExist($this->page . '/clip.mp4');
        self::assertStringContainsString('would cost 31 credits, more than the 20 allowed per file', $job->describe());
        self::assertStringContainsString('bin/plugin converter-studio confirm ' . $job->id(), $job->describe());

        $job = $converter->confirm($job, $this->clock->now() + 60);

        self::assertSame(Job::DONE, $job->state());
        self::assertFileExists($this->page . '/clip.mp4');
        self::assertCount(1, $this->api->made('POST /v1/conversions/'));
    }

    public function testASlowConversionIsPickedUpLaterWithoutAnotherUpload(): void
    {
        $this->api->script = ['processing', 'processing', 'processing', 'done'];
        $converter = $this->converter();
        $file = $this->photo();
        $job = $this->job($converter, $file);

        // An upload's wait: only enough time to send and start.
        $job = $converter->run($job, $this->clock->now() + 4);

        self::assertSame(Job::CONVERTING, $job->state());
        self::assertTrue($job->pending());
        self::assertFileExists($file);

        // The scheduler, later: the same job, found by the same file.
        $again = $this->converter()->jobFor($file, 'heic', 'jpg', ['quality' => 'balanced'], 20, 'keep');
        self::assertNotNull($again);
        self::assertSame($job->id(), $again->id());
        $done = $this->converter()->run($again, $this->clock->now() + 60);

        self::assertSame(Job::DONE, $done->state());
        self::assertCount(1, $this->api->made('POST /v1/uploads'));
        self::assertCount(1, $this->api->made('POST /v1/conversions'));
    }

    public function testALostAnswerToTheStartIsRetriedWithTheSameKey(): void
    {
        $this->api->overrides[] = ['POST /v1/conversions', static function (): MockResponse {
            throw new TransportException('Timeout');
        }];
        $converter = $this->converter();
        $job = $converter->run($this->job($converter, $this->photo()), $this->clock->now() + 60);

        self::assertSame(Job::UPLOADING, $job->state());
        self::assertTrue($job->data['upload']['complete']);
        self::assertStringContainsString('will be tried again', $job->describe());

        $job = $converter->run($job, $this->clock->now() + 60);

        self::assertSame(Job::DONE, $job->state());
        self::assertCount(1, $this->api->made('POST /v1/uploads'));
        $starts = $this->api->made('POST /v1/conversions');
        self::assertCount(2, $starts);
        self::assertSame($starts[0]['headers']['idempotency-key'], $starts[1]['headers']['idempotency-key']);
    }

    public function testAnUploadInPartsResumesWhereItStopped(): void
    {
        $converter = $this->converter(4);
        $file = $this->photo('photo.heic', '0123456789');
        $job = $this->job($converter, $file);
        // The first part goes through; the second's connection drops.
        $this->api->overrides[] = ['PUT /v1/uploads', static fn (): ?MockResponse => null];
        $this->api->overrides[] = ['PUT /v1/uploads', static function (): MockResponse {
            throw new TransportException('Reset');
        }];

        $job = $converter->run($job, $this->clock->now() + 60);
        self::assertSame(Job::UPLOADING, $job->state());
        self::assertSame(4, $job->data['upload']['offset']);

        $job = $converter->run($job, $this->clock->now() + 60);
        self::assertSame(Job::DONE, $job->state());
        $id = $job->data['upload']['id'];
        self::assertSame('0123456789', $this->api->uploads[$id]['received']);
    }

    public function testAFileReplacedMeanwhileIsNotConverted(): void
    {
        $converter = $this->converter();
        $file = $this->photo();
        $job = $this->job($converter, $file);
        file_put_contents($file, 'a different photo');
        touch($file, time() + 5);

        $job = $converter->run($job, $this->clock->now() + 60);

        self::assertSame(Job::FAILED, $job->state());
        self::assertSame('changed', $job->data['error']['code']);
        self::assertSame([], $this->api->requests);
    }

    public function testAnExpiredResultFails(): void
    {
        $this->api->overrides[] = ['GET /jobs/', $this->api->json(410, ['error' => ['code' => 'fileExpired', 'message' => 'Expired.']])];
        $converter = $this->converter();
        $file = $this->photo();

        $job = $converter->run($this->job($converter, $file), $this->clock->now() + 60);

        self::assertSame(Job::FAILED, $job->state());
        self::assertFileExists($file);
        self::assertSame([], glob($this->page . '/*.jpg') ?: []);
    }

    public function testABusyJobIsLeftToWhoeverHasIt(): void
    {
        $converter = $this->converter();
        $job = $this->job($converter, $this->photo());
        $other = new JobStore($this->root . '/data/converter-studio/jobs');
        self::assertTrue($other->lock($job->id()));

        $same = $converter->run($job, $this->clock->now() + 60);

        self::assertSame(Job::UPLOADING, $same->state());
        self::assertSame([], $this->api->requests);
        $other->unlock($job->id());
    }

    public function testFreeNames(): void
    {
        self::assertSame($this->page . '/a.jpg', Converter::freeName($this->page, 'a', 'jpg'));
        touch($this->page . '/a.jpg');
        touch($this->page . '/a-1.jpg');
        self::assertSame($this->page . '/a-2.jpg', Converter::freeName($this->page, 'a', 'jpg'));
    }

    public function testPathsOutsideTheSiteHaveNoJob(): void
    {
        $outside = sys_get_temp_dir() . '/cs-outside-' . bin2hex(random_bytes(4)) . '.heic';
        file_put_contents($outside, 'x');
        try {
            self::assertNull($this->converter()->jobFor($outside, 'heic', 'jpg', [], 20, 'keep'));
        } finally {
            unlink($outside);
        }
    }
}
