<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * What GPM and the Admin read: blueprints.yaml, the default settings, the translations and the
 * changelog, kept in agreement with each other and with the code.
 */
final class PackageTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @return array<string, mixed> */
    private static function blueprints(): array
    {
        return Yaml::parseFile(self::ROOT . '/blueprints.yaml');
    }

    public function testTheVersionIsTheSameEverywhere(): void
    {
        $version = self::blueprints()['version'];
        preg_match("/const VERSION = '([^']+)'/", (string) file_get_contents(self::ROOT . '/converter-studio.php'), $constant);
        preg_match('/^# v?(\S+)$/m', (string) file_get_contents(self::ROOT . '/CHANGELOG.md'), $changelog);

        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
        self::assertSame($version, $constant[1] ?? null, 'ConverterStudioPlugin::VERSION');
        self::assertSame($version, $changelog[1] ?? null, 'the top of CHANGELOG.md');
    }

    public function testTheManifestHasWhatGpmNeeds(): void
    {
        $blueprints = self::blueprints();

        foreach (['name', 'slug', 'type', 'version', 'description', 'icon', 'author', 'homepage', 'keywords', 'bugs', 'license', 'docs'] as $field) {
            self::assertNotEmpty($blueprints[$field] ?? null, $field);
        }
        self::assertSame('converter-studio', $blueprints['slug']);
        self::assertSame('plugin', $blueprints['type']);
        self::assertSame('MIT', $blueprints['license']);
        self::assertSame(['1.7', '2.0'], $blueprints['compatibility']['grav']);
        // GPM reads the grav dependency first (see Grav's plugin compatibility notes).
        self::assertSame('grav', $blueprints['dependencies'][0]['name']);
        self::assertFileExists(self::ROOT . '/converter-studio.php');
        self::assertFileExists(self::ROOT . '/LICENSE');
        self::assertFileExists(self::ROOT . '/README.md');
    }

    public function testEveryLabelIsTranslated(): void
    {
        $english = Yaml::parseFile(self::ROOT . '/languages.yaml')['en']['PLUGIN_CONVERTER_STUDIO'];
        preg_match_all('/PLUGIN_CONVERTER_STUDIO\.([A-Z_]+)/', (string) file_get_contents(self::ROOT . '/blueprints.yaml'), $used);

        foreach (array_unique($used[1]) as $key) {
            self::assertArrayHasKey($key, $english, $key);
            self::assertNotSame('', trim((string) $english[$key]), $key);
        }
        foreach (array_keys($english) as $key) {
            self::assertContains($key, $used[1], "{$key} is never used");
        }
    }

    public function testEverySettingHasAFieldAndADefault(): void
    {
        $fields = array_keys(self::blueprints()['form']['fields']);
        $defaults = self::flatten(Yaml::parseFile(self::ROOT . '/converter-studio.yaml'));

        foreach (array_keys($defaults) as $key) {
            self::assertContains($key, $fields, "{$key} has no field");
        }
        foreach ($fields as $field) {
            if (!str_ends_with($field, '_section')) {
                self::assertArrayHasKey($field, $defaults, "{$field} has no default");
            }
        }
    }

    public function testTheChangelogIsInGravsFormat(): void
    {
        // The comment keeps Prettier away from Grav's layout.
        $changelog = (string) preg_replace('/\A<!-- prettier-ignore-start -->\n/', '', (string) file_get_contents(self::ROOT . '/CHANGELOG.md'));

        self::assertMatchesRegularExpression('/\A# v?\d+\.\d+\.\d+\n## \d{4}-\d{2}-\d{2}\n\n1\. \[\]\(#(new|improved|bugfix)\)\n(    \* .+\n)+/', $changelog);
    }

    public function testTheReleaseArchiveLeavesDevelopmentFilesOut(): void
    {
        $attributes = (string) file_get_contents(self::ROOT . '/.gitattributes');

        foreach (['/tests', '/composer.json', '/phpunit.xml.dist', '/.github', '/dev', '/SUBMISSION.md'] as $path) {
            self::assertMatchesRegularExpression('#^' . preg_quote($path, '#') . '\s+export-ignore$#m', $attributes, $path);
        }
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $flat += self::flatten($value, $prefix . $key . '.');
            } else {
                $flat[$prefix . $key] = $value;
            }
        }
        return $flat;
    }
}
