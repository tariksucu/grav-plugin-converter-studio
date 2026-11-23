<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio\Tests\Unit;

use Grav\Plugin\ConverterStudio\Rules;
use Grav\Plugin\ConverterStudio\Settings;
use PHPUnit\Framework\TestCase;

final class RulesAndSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        $this->tearDown();
    }

    protected function tearDown(): void
    {
        putenv(Settings::KEY_VARIABLE);
        putenv(Settings::URL_VARIABLE);
    }

    public function testFilesBelongToGroupsByExtension(): void
    {
        self::assertSame('heic', Rules::groupOf('IMG_0001.HEIC'));
        self::assertSame('heic', Rules::groupOf('photo.heif'));
        self::assertSame('raw', Rules::groupOf('DSC_1.NEF'));
        self::assertSame('tiff', Rules::groupOf('scan.tif'));
        self::assertSame('video', Rules::groupOf('clip.mov'));
        self::assertSame('office', Rules::groupOf('report.docx'));
        self::assertSame('audio', Rules::groupOf('take.wav'));
        self::assertNull(Rules::groupOf('photo.jpg'));
        self::assertNull(Rules::groupOf('clip.mp4'));
        self::assertNull(Rules::groupOf('README'));
    }

    public function testNoGroupConvertsToItsOwnSources(): void
    {
        foreach (Rules::GROUPS as $name => $group) {
            foreach ($group['to'] as $to) {
                self::assertNull(Rules::groupOf('x.' . $to), "{$name} → {$to} would convert again");
            }
        }
    }

    public function testTargetsFallBackToTheGroupsDefault(): void
    {
        self::assertSame('webp', Rules::target('heic', 'WEBP'));
        self::assertSame('jpg', Rules::target('heic', 'mp4'));
        self::assertSame('jpg', Rules::target('raw', null));
        self::assertSame('webm', Rules::target('video', 'webm'));
        self::assertSame('pdf', Rules::target('office', 'jpg'));
    }

    public function testOptionsFollowTheKindOfFile(): void
    {
        $settings = ['image_quality' => 'high', 'image_long_side' => '1920', 'keep_metadata' => true, 'video_height' => '720', 'video_quality' => 'small', 'audio_bitrate' => '320'];

        self::assertSame(['quality' => 'high', 'longSide' => '1920', 'keepMetadata' => true], Rules::options('heic', 'jpg', $settings));
        self::assertSame(['longSide' => '1920', 'keepMetadata' => true], Rules::options('raw', 'png', $settings));
        self::assertSame(['height' => '720', 'quality' => 'small'], Rules::options('video', 'mp4', $settings));
        self::assertSame([], Rules::options('office', 'pdf', $settings));
        self::assertSame(['bitrate' => '320'], Rules::options('audio', 'mp3', $settings));
        // Values the API does not know are left out.
        self::assertSame(['quality' => 'balanced'], Rules::options('heic', 'jpg', ['image_long_side' => '99999', 'image_quality' => 'balanced']));
    }

    public function testOnlyUnknownMediaTypesAreAdded(): void
    {
        $known = ['tiff' => ['type' => 'file'], 'mov' => ['type' => 'video']];

        $added = Rules::mediaTypes(['heic', 'tiff', 'video'], $known);

        self::assertSame(['heic', 'heif', 'tif', 'mkv', '3gp'], array_keys($added));
        self::assertSame(['type' => 'file', 'thumb' => 'media/thumb.png', 'mime' => 'image/heic'], $added['heic']);
        self::assertSame([], Rules::mediaTypes([], $known));
    }

    public function testNothingConvertsOnUploadUntilSwitchedOn(): void
    {
        $defaults = \Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__, 2) . '/converter-studio.yaml');
        $settings = Settings::fromArray($defaults);

        self::assertSame([], $settings->uploadGroups());
        self::assertTrue($settings->enabled());
        self::assertSame('keep', $settings->originals());
        self::assertSame(20, $settings->maxCredits());
        self::assertSame(40, $settings->uploadWait());
        self::assertSame('https://converter.studio/api', $settings->apiUrl());
        self::assertNull($settings->apiKey());
    }

    public function testTheKeyAndAddressFallBackToTheEnvironment(): void
    {
        putenv(Settings::KEY_VARIABLE . '=cvs_live_env');
        putenv(Settings::URL_VARIABLE . '=http://localhost:8080/api/');

        $empty = Settings::fromArray(['api_key' => ' ', 'api_url' => '']);
        $set = Settings::fromArray(['api_key' => 'cvs_live_config', 'api_url' => 'https://test.example/api']);

        self::assertSame('cvs_live_env', $empty->apiKey());
        self::assertSame('http://localhost:8080/api', $empty->apiUrl());
        self::assertSame('cvs_live_config', $set->apiKey());
        self::assertSame('https://test.example/api', $set->apiUrl());
    }

    public function testBadValuesAreReplacedBySafeOnes(): void
    {
        $settings = Settings::fromArray([
            'api_url' => 'javascript:alert(1)',
            'originals' => 'everywhere',
            'max_credits' => 'lots',
            'upload_wait' => 100000,
            'rules' => ['heic' => ['upload' => true, 'to' => 'exe'], 'nope' => ['upload' => true]],
        ]);

        self::assertSame('https://converter.studio/api', $settings->apiUrl());
        self::assertSame('keep', $settings->originals());
        self::assertSame(20, $settings->maxCredits());
        self::assertSame(300, $settings->uploadWait());
        self::assertSame(['heic'], $settings->uploadGroups());
        self::assertSame('jpg', $settings->target('heic'));
    }
}
