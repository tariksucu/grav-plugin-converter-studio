<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio\Tests\Unit;

use Grav\Plugin\ConverterStudio\Originals;
use PHPUnit\Framework\TestCase;

/**
 * Kept originals: names the web server does not serve, and the one-time rename of those kept by
 * version 1.0.0 under their own names.
 */
final class OriginalsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cs-grav-originals-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/pages/03.trip', 0777, true);
        mkdir($this->dir . '/media', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function put(string $path, string $content = 'bytes'): void
    {
        file_put_contents($this->dir . '/' . $path, $content);
    }

    /**
     * @return string[] Every file below the originals folder, hidden ones left out.
     */
    private function files(): array
    {
        $found = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            if ($item->isFile() && $item->getFilename()[0] !== '.') {
                $found[] = substr($item->getPathname(), strlen($this->dir) + 1);
            }
        }
        sort($found);
        return $found;
    }

    public function testEveryNameEndsInASuffixGravsWebServerRulesRefuse(): void
    {
        // The current rules serve these from user/data; .txt under user/ is refused by every rule
        // set Grav has shipped (Apache, nginx, IIS, lighttpd, the built-in server's router).
        foreach (['IMG_0042.MOV', 'take.wav', 'song.flac', 'scan.bmp', 'photo.heic'] as $name) {
            $kept = Originals::freeName($this->dir, $name);
            self::assertSame($name . '.original.txt', $kept);
            self::assertMatchesRegularExpression('/\.(txt|md|json|yaml|yml|php|twig|sh|bat)$/', $kept);
            self::assertDoesNotMatchRegularExpression('/\.(jpe?g|png|gif|webp|avif|bmp|ico|mp4|webm|ogg|ogv|mov|mp3|wav|m4a|flac|pdf|woff2|woff|ttf|otf|eot|css|js)$/i', $kept);
            self::assertSame($name, Originals::uploadedName($kept));
        }
    }

    public function testTheDestinationKeepsTheFoldersAndNeverReplacesAFile(): void
    {
        $originals = new Originals($this->dir);
        $this->put('pages/03.trip/clip.mov.original.txt');

        self::assertSame($this->dir . '/media/clip.mov.original.txt', $originals->destination('media/clip.mov'));
        self::assertSame($this->dir . '/pages/03.trip/clip-1.mov.original.txt', $originals->destination('pages/03.trip/clip.mov'));
    }

    public function testOriginalsKeptBy100AreRenamedOnce(): void
    {
        $this->put('pages/03.trip/IMG_0042.MOV', 'video');
        $this->put('pages/03.trip/take.wav', 'audio');
        $this->put('pages/03.trip/photo.heic.original.txt', 'kept by 1.0.1');
        $this->put('media/scan.bmp', 'image');

        $renamed = (new Originals($this->dir))->migrate();

        self::assertCount(3, $renamed);
        self::assertSame([
            'media/scan.bmp.original.txt',
            'pages/03.trip/IMG_0042.MOV.original.txt',
            'pages/03.trip/photo.heic.original.txt',
            'pages/03.trip/take.wav.original.txt',
        ], $this->files());
        self::assertSame('video', file_get_contents($this->dir . '/pages/03.trip/IMG_0042.MOV.original.txt'));
        self::assertSame('kept by 1.0.1', file_get_contents($this->dir . '/pages/03.trip/photo.heic.original.txt'));

        // Done once: a file that turns up later (copied back by hand) is left as it is.
        $this->put('pages/03.trip/later.mov');
        self::assertSame([], (new Originals($this->dir))->migrate());
        self::assertFileExists($this->dir . '/pages/03.trip/later.mov');
    }

    public function testARenameThatStoppedHalfWayGoesOnNextTime(): void
    {
        // As left by a run that stopped after the first file: no marker yet.
        $this->put('pages/03.trip/a.mov.original.txt', 'first');
        $this->put('pages/03.trip/b.mov', 'second');

        $renamed = (new Originals($this->dir))->migrate();

        self::assertSame([$this->dir . '/pages/03.trip/b.mov' => $this->dir . '/pages/03.trip/b.mov.original.txt'], $renamed);
        self::assertSame(['pages/03.trip/a.mov.original.txt', 'pages/03.trip/b.mov.original.txt'], $this->files());
        self::assertSame('first', file_get_contents($this->dir . '/pages/03.trip/a.mov.original.txt'));
    }

    public function testARenameNeverReplacesAFile(): void
    {
        $this->put('pages/03.trip/clip.mov', 'old');
        $this->put('pages/03.trip/clip.mov.original.txt', 'newer');

        (new Originals($this->dir))->migrate();

        self::assertSame('newer', file_get_contents($this->dir . '/pages/03.trip/clip.mov.original.txt'));
        self::assertSame('old', file_get_contents($this->dir . '/pages/03.trip/clip-1.mov.original.txt'));
    }

    public function testAFileThatCannotBeRenamedIsTriedAgain(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root renames in a read-only folder');
        }
        $this->put('pages/03.trip/clip.mov');
        chmod($this->dir . '/pages/03.trip', 0555);
        try {
            self::assertSame([], (new Originals($this->dir))->migrate());
        } finally {
            chmod($this->dir . '/pages/03.trip', 0777);
        }

        $renamed = (new Originals($this->dir))->migrate();

        self::assertCount(1, $renamed);
        self::assertFileExists($this->dir . '/pages/03.trip/clip.mov.original.txt');
    }

    public function testNothingHappensWithoutAnOriginalsFolder(): void
    {
        $missing = $this->dir . '/nowhere';

        self::assertSame([], (new Originals($missing))->migrate());
        self::assertDirectoryDoesNotExist($missing);
    }

    public function testAnotherProcessRenamingIsLeftToIt(): void
    {
        $this->put('pages/03.trip/clip.mov');
        $lock = fopen($this->dir . '/.renamed-1.0.1.lock', 'c');
        self::assertNotFalse($lock);
        flock($lock, LOCK_EX);
        try {
            self::assertSame([], (new Originals($this->dir))->migrate());
            self::assertFileExists($this->dir . '/pages/03.trip/clip.mov');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::assertCount(1, (new Originals($this->dir))->migrate());
    }
}
