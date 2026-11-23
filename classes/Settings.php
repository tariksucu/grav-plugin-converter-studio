<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio;

use Grav\Plugin\ConverterStudio\Api\Client;

/**
 * The plugin's settings (`plugins.converter-studio`), read once and checked.
 */
final class Settings
{
    /** Where the original goes once its converted file is in place. */
    public const ORIGINALS = ['keep', 'beside', 'delete'];

    /** The environment variables read when the settings leave the key or the address empty. */
    public const KEY_VARIABLE = 'CONVERTER_STUDIO_API_KEY';
    public const URL_VARIABLE = 'CONVERTER_STUDIO_API_URL';

    /**
     * @param array<string, mixed> $config
     */
    private function __construct(private readonly array $config)
    {
    }

    /**
     * @param array<string, mixed> $config The plugin's configuration.
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    public function enabled(): bool
    {
        return !empty($this->config['enabled']);
    }

    /**
     * The API key: the one in the settings, otherwise CONVERTER_STUDIO_API_KEY.
     */
    public function apiKey(): ?string
    {
        $key = trim((string) ($this->config['api_key'] ?? ''));
        if ($key === '') {
            $key = trim((string) getenv(self::KEY_VARIABLE));
        }
        return $key !== '' ? $key : null;
    }

    /**
     * The API's address: the one in the settings, otherwise CONVERTER_STUDIO_API_URL, otherwise
     * https://converter.studio/api. Only http(s) addresses count.
     */
    public function apiUrl(): string
    {
        foreach ([(string) ($this->config['api_url'] ?? ''), (string) getenv(self::URL_VARIABLE)] as $url) {
            $url = rtrim(trim($url), '/');
            if ($url !== '' && preg_match('#^https?://[^/\s]+#i', $url)) {
                return $url;
            }
        }
        return Client::DEFAULT_BASE;
    }

    /**
     * The groups whose uploads convert in the Admin.
     *
     * @return string[]
     */
    public function uploadGroups(): array
    {
        $groups = [];
        foreach (array_keys(Rules::GROUPS) as $group) {
            if (!empty($this->config['rules'][$group]['upload'])) {
                $groups[] = $group;
            }
        }
        return $groups;
    }

    /**
     * What a group's files become.
     */
    public function target(string $group): string
    {
        return Rules::target($group, $this->config['rules'][$group]['to'] ?? null);
    }

    /**
     * The API options for a group.
     *
     * @return array<string, mixed>
     */
    public function options(string $group, string $to): array
    {
        return Rules::options($group, $to, (array) ($this->config['results'] ?? []));
    }

    /**
     * keep (moved to user/data/converter-studio/originals), beside (left where it is) or delete.
     */
    public function originals(): string
    {
        $value = (string) ($this->config['originals'] ?? 'keep');
        return in_array($value, self::ORIGINALS, true) ? $value : 'keep';
    }

    /**
     * Credits a file may cost before it waits for approval (the API's own default is 20).
     */
    public function maxCredits(): int
    {
        $value = $this->config['max_credits'] ?? 20;
        return is_numeric($value) ? max(0, min(100000, (int) $value)) : 20;
    }

    /**
     * Seconds an Admin upload waits for its conversions before leaving them to the scheduler.
     */
    public function uploadWait(): int
    {
        $value = $this->config['upload_wait'] ?? 40;
        return is_numeric($value) ? max(5, min(300, (int) $value)) : 40;
    }

    /**
     * Whether Grav's scheduler finishes conversions that outlast an upload.
     */
    public function scheduler(): bool
    {
        return (bool) ($this->config['scheduler'] ?? true);
    }

    /**
     * Whether saving a page points its links at the converted files.
     */
    public function fixReferences(): bool
    {
        return (bool) ($this->config['fix_references'] ?? true);
    }
}
