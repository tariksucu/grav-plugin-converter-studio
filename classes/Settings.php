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

    /** The key, when the settings have none. */
    public const KEY_VARIABLE = 'CONVERTER_STUDIO_API_KEY';

    /** Another API address (a test server); only the environment can set one. */
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
     * The API's address, which gets the API key: https://converter.studio/api, or
     * CONVERTER_STUDIO_API_URL when the environment sets an http(s) address (a test server).
     * Never the settings: anyone who can edit the configuration could point them at a server of
     * their own and receive the key.
     */
    public function apiUrl(): string
    {
        $url = rtrim(trim((string) getenv(self::URL_VARIABLE)), '/');
        return $url !== '' && preg_match('#^https?://[^/\s]+#i', $url) ? $url : Client::DEFAULT_BASE;
    }

    /**
     * An address left in the settings (`api_url`, which version 1.0.0 read) that is not the
     * default: it is ignored, and the status command says so.
     */
    public function ignoredApiUrl(): ?string
    {
        $url = rtrim(trim((string) ($this->config['api_url'] ?? '')), '/');
        return $url !== '' && $url !== Client::DEFAULT_BASE ? $url : null;
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
