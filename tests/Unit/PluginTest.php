<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio\Tests\Unit;

require_once dirname(__DIR__) . '/GravStubs.php';
require_once dirname(__DIR__, 2) . '/converter-studio.php';

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\ConverterStudio\Api\Client;
use Grav\Plugin\ConverterStudio\Converter;
use Grav\Plugin\ConverterStudio\JobStore;
use Grav\Plugin\ConverterStudio\References;
use Grav\Plugin\ConverterStudio\Services;
use Grav\Plugin\ConverterStudio\Settings;
use Grav\Plugin\ConverterStudio\Tests\FakeApi;
use Grav\Plugin\ConverterStudio\Tests\FakeClock;
use Grav\Plugin\ConverterStudioPlugin;
use PHPUnit\Framework\TestCase;
use RocketTheme\Toolbox\Event\Event;

/**
 * The plugin's listeners, called as Grav calls them (with stand-ins for Grav's services): when
 * they run, that they never throw out of Grav's event chain, and that requests which convert
 * nothing never make the API client.
 */
final class PluginTest extends TestCase
{
    private string $root;
    private string $page;
    private Grav $grav;
    private Config $config;
    /** @var array<int, array{0: string, 1: string}> */
    private array $messages = [];
    /** @var array<int, array{0: string, 1: string}> */
    private array $log = [];

    protected function setUp(): void
    {
        putenv(Settings::KEY_VARIABLE);
        putenv(Settings::URL_VARIABLE);
        $this->root = sys_get_temp_dir() . '/cs-grav-plugin-' . bin2hex(random_bytes(4));
        $this->page = $this->root . '/user/pages/03.trip';
        mkdir($this->page, 0777, true);
        mkdir($this->root . '/user/data', 0777, true);
        $this->config = new Config(['plugins' => ['converter-studio' => [
            'enabled' => true,
            'api_key' => 'cvs_live_secret',
            'rules' => ['heic' => ['upload' => true, 'to' => 'jpg']],
            'originals' => 'keep',
            'upload_wait' => 10,
        ]], 'media' => ['types' => ['jpg' => ['type' => 'image']]]]);
        $this->grav = new Grav();
        $this->grav['config'] = $this->config;
        $root = $this->root;
        $this->grav['locator'] = new class ($root) {
            public function __construct(private string $root)
            {
            }

            public function findResource(string $uri, bool $absolute = true, bool $first = false): string
            {
                return $this->root . '/user/' . substr($uri, strlen('user://'));
            }
        };
        $this->grav['messages'] = new class (function (string $scope, string $message): void {
            $this->messages[] = [$scope, $message];
        }) {
            public function __construct(private \Closure $note)
            {
            }

            public function add(string $message, string $scope = 'info'): void
            {
                ($this->note)($scope, $message);
            }
        };
        $this->grav['log'] = new class (function (string $level, string $message): void {
            $this->log[] = [$level, $message];
        }) {
            public function __construct(private \Closure $note)
            {
            }

            public function info(string $message): void
            {
                ($this->note)('info', $message);
            }

            public function warning(string $message): void
            {
                ($this->note)('warning', $message);
            }
        };
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function plugin(?Services $services = null): ConverterStudioPlugin
    {
        $plugin = new ConverterStudioPlugin('converter-studio', $this->grav, $this->config);
        if ($services !== null) {
            (new \ReflectionProperty($plugin, 'services'))->setValue($plugin, $services);
        }
        return $plugin;
    }

    private static function started(ConverterStudioPlugin $plugin): bool
    {
        return (new \ReflectionProperty($plugin, 'services'))->getValue($plugin) !== null;
    }

    /**
     * Services on the fake API (as Services::fromGrav() makes them for a site).
     *
     * @param (\Closure(References): Converter)|null $converter
     */
    private function services(FakeApi $api, ?\Closure $converter = null): Services
    {
        $clock = new FakeClock();
        $user = $this->root . '/user';
        $settings = Settings::fromArray((array) $this->config->get('plugins.converter-studio'));
        $log = function (string $level, string $message): void {
            $this->log[] = [$level, $message];
        };
        return Services::create($settings, $user, $user . '/data/converter-studio', $converter ?? static fn (References $references): Converter => new Converter(
            new Client($api->client(), 'cvs_live_secret', FakeApi::BASE, ConverterStudioPlugin::VERSION),
            new JobStore($user . '/data/converter-studio/jobs'),
            $references,
            $user,
            $user . '/data/converter-studio/originals',
            $log,
            $clock->clock(),
            $clock->sleeper(),
        ), $log);
    }

    private function page(): PageInterface
    {
        return new class ($this->page) implements PageInterface {
            public function __construct(private string $folder)
            {
            }

            public function path($var = null)
            {
                return $this->folder;
            }

            public function rawMarkdown($var = null)
            {
                throw new \RuntimeException('The page cannot be read.');
            }

            public function header($var = null)
            {
                return (object) [];
            }
        };
    }

    private function upload(string $name, string $content = 'heic bytes'): Event
    {
        file_put_contents($this->page . '/' . $name, $content);
        return new Event(['page' => $this->page(), 'filenames' => [$name]]);
    }

    public function testUploadsAreConvertedAfterGravsOwnListeners(): void
    {
        $events = ConverterStudioPlugin::getSubscribedEvents();

        self::assertSame(['onApiMediaUploaded', -1000], $events['onApiMediaUploaded']);
        self::assertSame(['onAdminAfterAddMedia', -1000], $events['onAdminAfterAddMedia']);
        // The API's webhooks (-100) and audit trail (-200) record the upload first.
        self::assertLessThan(-200, $events['onApiMediaUploaded'][1]);
    }

    public function testAFailedConversionKeepsTheUploadAndThrowsNothing(): void
    {
        $api = new FakeApi();
        $api->script = ['processing', 'failed'];
        $api->failure = ['code' => 'conversionFailed', 'message' => 'This file is damaged.'];
        $plugin = $this->plugin($this->services($api));

        $plugin->onApiMediaUploaded($this->upload('broken.heic'));

        self::assertFileExists($this->page . '/broken.heic');
        self::assertFileDoesNotExist($this->page . '/broken.jpg');
        self::assertSame([['error', 'broken.heic was not converted: This file is damaged.']], $this->messages);
        self::assertSame(['warning', 'Converter Studio: broken.heic was not converted: This file is damaged.'], $this->log[0]);
    }

    public function testAConvertedUploadIsReported(): void
    {
        $plugin = $this->plugin($this->services(new FakeApi()));

        $plugin->onApiMediaUploaded($this->upload('IMG_0001.heic'));

        self::assertFileExists($this->page . '/IMG_0001.jpg');
        self::assertFileExists($this->root . '/user/data/converter-studio/originals/pages/03.trip/IMG_0001.heic.original.txt');
        self::assertSame([['info', 'IMG_0001.heic → IMG_0001.jpg (1 credit)']], $this->messages);
    }

    public function testAnUnexpectedErrorIsLoggedNotThrown(): void
    {
        $services = $this->services(new FakeApi(), static function (): Converter {
            throw new \RuntimeException('The disk is full.');
        });
        $plugin = $this->plugin($services);

        $plugin->onApiMediaUploaded($this->upload('IMG_0002.heic'));
        $plugin->onAdminAfterAddMedia(new Event(['page' => $this->page()]));

        self::assertFileExists($this->page . '/IMG_0002.heic');
        self::assertSame('error', $this->messages[0][0]);
        self::assertStringContainsString('The disk is full.', $this->messages[0][1]);
        self::assertSame('warning', $this->log[0][0]);
    }

    public function testAnUploadWithoutAKeyIsKeptAndSaysSo(): void
    {
        $this->config->set('plugins.converter-studio.api_key', '');
        $plugin = $this->plugin();

        $plugin->onApiMediaUploaded($this->upload('IMG_0003.heic'));

        self::assertFileExists($this->page . '/IMG_0003.heic');
        self::assertSame('error', $this->messages[0][0]);
        self::assertStringContainsString('no Converter Studio API key is set', $this->messages[0][1]);
        self::assertSame('warning', $this->log[0][0]);
    }

    public function testAPageSaveIsNeverStopped(): void
    {
        $references = new References($this->root . '/user/data/converter-studio/renames.json');
        $references->add('pages/03.trip', 'gone.heic', 'here.jpg');
        file_put_contents($this->page . '/here.jpg', 'jpg');
        $plugin = $this->plugin();

        // The page's rawMarkdown() throws: the save goes on, the problem is logged.
        $plugin->onAdminSave(new Event(['page' => $this->page()]));

        self::assertSame('warning', $this->log[0][0]);
        self::assertStringContainsString('The page cannot be read.', $this->log[0][1]);
    }

    public function testRequestsThatConvertNothingOnlyReadTheSettings(): void
    {
        $this->config->set('plugins.converter-studio.rules.heic.upload', false);
        $plugin = $this->plugin();
        $plugin->onPluginsInitialized();
        self::assertFalse(self::started($plugin));

        // With a kind switched on, the Admin is told about its file types, still without the client.
        $this->config->set('plugins.converter-studio.rules.heic.upload', true);
        $plugin->onPluginsInitialized();
        self::assertSame('image/heic', $this->config->get('media.types.heic.mime'));
        self::assertFalse(self::started($plugin));

        $scheduler = new class () {
            /** @var string[] */
            public array $added = [];

            public function addFunction(string $function, array $args, string $id): object
            {
                $this->added[] = $id;
                return new class () {
                    public function at(string $when): void
                    {
                    }

                    public function backlink(string $link): void
                    {
                    }
                };
            }
        };
        $plugin->onSchedulerInitialized(new Event(['scheduler' => $scheduler]));
        self::assertSame(['converter-studio'], $scheduler->added);
        self::assertFalse(self::started($plugin));

        // An upload of a kind that is off makes nothing either.
        $this->config->set('plugins.converter-studio.rules.heic.upload', false);
        $plugin->onApiMediaUploaded($this->upload('IMG_0004.heic'));
        self::assertFalse(self::started($plugin));
        self::assertSame([], $this->messages);
    }

    public function testTheApiClientIsMadeOnlyWhenAConversionNeedsIt(): void
    {
        $made = 0;
        $services = $this->services(new FakeApi(), function (References $references) use (&$made): Converter {
            $made++;
            return new Converter(
                new Client((new FakeApi())->client(), 'cvs_live_secret', FakeApi::BASE, ConverterStudioPlugin::VERSION),
                new JobStore($this->root . '/user/data/converter-studio/jobs'),
                $references,
                $this->root . '/user',
                $this->root . '/user/data/converter-studio/originals',
            );
        });

        $services->settings();
        $services->references();
        $services->relative($this->page);
        self::assertSame(0, $made);
        self::assertFalse($services->started());

        $services->worker();
        $services->converter();
        self::assertSame(1, $made);
        self::assertTrue($services->started());
    }
}
