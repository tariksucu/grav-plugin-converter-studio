<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio\Tests\Unit;

use Grav\Plugin\ConverterStudio\References;
use PHPUnit\Framework\TestCase;

final class ReferencesTest extends TestCase
{
    private const RENAMES = ['photo.heic' => 'photo.jpg', 'Clip 1.mov' => 'Clip 1.mp4'];

    public function testLinksToARenamedFilePointToTheNewOne(): void
    {
        $markdown = <<<'MD'
![A cat](photo.heic)
![Resized](photo.heic?cropResize=300,300 "Title")
![From elsewhere](/blog/post/photo.heic)
<img src="photo.heic" alt="">
[Download the clip](Clip 1.mov)
MD;

        self::assertSame(<<<'MD'
![A cat](photo.jpg)
![Resized](photo.jpg?cropResize=300,300 "Title")
![From elsewhere](/blog/post/photo.jpg)
<img src="photo.jpg" alt="">
[Download the clip](Clip 1.mp4)
MD, References::rewrite($markdown, self::RENAMES));
    }

    public function testOtherNamesAreLeftAlone(): void
    {
        $markdown = '![](my-photo.heic) ![](photo.heic.bak) ![](photo.heics) ![](otherphoto.heic) ![](photo%2Eheic)';

        self::assertSame($markdown, References::rewrite($markdown, self::RENAMES));
    }

    public function testReplacementsAreTakenLiterally(): void
    {
        self::assertSame('![](a$1\\b.jpg)', References::rewrite('![](x.heic)', ['x.heic' => 'a$1\\b.jpg']));
    }

    public function testHeadersAreRewrittenToo(): void
    {
        $header = [
            'title' => 'A photo.heic story',
            'hero' => ['image' => 'photo.heic', 'count' => 3],
            'gallery' => ['photo.heic', 'other.jpg'],
            'published' => true,
        ];

        self::assertSame([
            'title' => 'A photo.jpg story',
            'hero' => ['image' => 'photo.jpg', 'count' => 3],
            'gallery' => ['photo.jpg', 'other.jpg'],
            'published' => true,
        ], References::rewriteHeader($header, self::RENAMES));
    }

    public function testRenamesAreRememberedPerFolderForAWhile(): void
    {
        $file = sys_get_temp_dir() . '/cs-grav-refs-' . bin2hex(random_bytes(4)) . '/renames.json';
        $references = new References($file);
        $long = time() - 90 * 86400;

        $references->add('pages/01.blog/old', 'a.heic', 'a.jpg', $long);
        $references->add('pages/01.blog/post', 'photo.heic', 'photo.jpg');
        $references->add('pages/01.blog/post', 'clip.mov', 'clip.mp4');

        self::assertSame(['photo.heic' => 'photo.jpg', 'clip.mov' => 'clip.mp4'], $references->forFolder('pages/01.blog/post'));
        self::assertSame([], $references->forFolder('pages/01.blog/old'));
        self::assertSame([], $references->forFolder('pages/02.about'));

        array_map('unlink', glob(dirname($file) . '/*') ?: []);
        rmdir(dirname($file));
    }
}
