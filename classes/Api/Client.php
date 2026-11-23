<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio\Api;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Converter Studio REST API (v1), as this plugin uses it.
 *
 * Calls carry the account's API key; the upload and download addresses carry their own short-lived
 * tokens instead, so the key is never sent there. Nothing about the site is sent: only the files
 * being converted, with their names and sizes. Nothing here logs a request, an address or the key.
 *
 * @see https://converter.studio/developers
 */
final class Client
{
    /** The API, unless the settings say otherwise (a test server). */
    public const DEFAULT_BASE = 'https://converter.studio/api';

    /** Names this plugin on every request (Converter Studio's statistics of its connectors). */
    public const CLIENT_HEADER = 'X-Converter-Studio-Client';

    /** Bytes in one upload request. The API takes parts of up to 95 MB, in order. */
    public const PART_BYTES = 16 * 1024 * 1024;

    /** The largest result accepted, in bytes (the API's own file limit). */
    public const MAX_RESULT_BYTES = 4 * 1024 * 1024 * 1024;

    /** Seconds an ordinary call may take. */
    private const TIMEOUT = 30.0;

    private readonly string $base;

    /**
     * @param string|null $key The account's API key; null or '' when none is set.
     * @param string $version This plugin's version, sent in CLIENT_HEADER and the User-Agent.
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ?string $key,
        string $base,
        private readonly string $version,
        private readonly int $partBytes = self::PART_BYTES,
    ) {
        $base = rtrim(trim($base), '/');
        $this->base = $base !== '' ? $base : self::DEFAULT_BASE;
    }

    /**
     * The API's address, without a trailing slash.
     */
    public function base(): string
    {
        return $this->base;
    }

    /**
     * Whether an API key is set.
     */
    public function hasKey(): bool
    {
        return is_string($this->key) && $this->key !== '';
    }

    /**
     * The key's account: plan, credits, limits.
     */
    public function account(): ApiResult
    {
        return $this->call('GET', '/v1/account');
    }

    /**
     * Opens an upload slot for a file of $size bytes.
     */
    public function createUpload(string $name, int $size): ApiResult
    {
        return $this->call('POST', '/v1/uploads', ['fileName' => $name, 'size' => $size]);
    }

    /**
     * Sends a file to its upload slot in parts, for as long as time allows. Called again with the
     * same state, it goes on where it stopped.
     *
     * @param array{id: string, url: string, offset: int, size: int} $upload The slot's state, updated
     *     as parts arrive.
     * @param float $deadline When to stop sending (microtime(true)).
     * @return ApiResult OK when the whole file is there; "timeUp" when time ran out first.
     */
    public function sendParts(string $path, array &$upload, float $deadline): ApiResult
    {
        clearstatcache(true, $path);
        $size = @filesize($path);
        if ($size === false) {
            return ApiResult::local('missing');
        }
        if ($size !== (int) $upload['size']) {
            return ApiResult::local('changed');
        }
        if (!$this->sameOrigin($upload['url'])) {
            return ApiResult::local('unexpectedUrl');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return ApiResult::local('missing');
        }
        try {
            while ((int) $upload['offset'] < $size) {
                $left = $deadline - microtime(true);
                if ($left < 3) {
                    return ApiResult::local('timeUp');
                }
                $offset = (int) $upload['offset'];
                $length = min($this->partBytes, $size - $offset);
                fseek($handle, $offset);
                $part = (string) fread($handle, $length);
                if (strlen($part) !== $length) {
                    return ApiResult::local('changed');
                }
                $result = $this->send('PUT', $upload['url'], [
                    'headers' => $this->headers(false) + [
                        'Content-Type' => 'application/octet-stream',
                        'Upload-Offset' => (string) $offset,
                    ],
                    'body' => $part,
                    'timeout' => max(5.0, min(120.0, $left)),
                ]);
                unset($part);
                $received = $result->params()['received'] ?? null;
                if ($result->status === 409 && is_numeric($received)) {
                    // The API has a different amount than we thought: go on from there.
                    $upload['offset'] = (int) $received;
                    continue;
                }
                if (!$result->ok()) {
                    return $result;
                }
                $upload['offset'] = $offset + $length;
            }
        } finally {
            fclose($handle);
        }
        return new ApiResult(200, null, '');
    }

    /**
     * Starts converting an uploaded file. The same $idempotencyKey sent again (a retry after a lost
     * answer) gets the same conversion back instead of a second one.
     *
     * @param array<string, mixed> $options The API's conversion options.
     * @param int $maxCredits It goes ahead on its own up to this price; above it, it waits.
     */
    public function convert(string $uploadId, string $to, array $options, int $maxCredits, string $idempotencyKey): ApiResult
    {
        $body = ['uploadId' => $uploadId, 'to' => $to, 'maxCredits' => $maxCredits];
        if ($options !== []) {
            $body['options'] = $options;
        }
        return $this->call('POST', '/v1/conversions', $body, ['Idempotency-Key' => $idempotencyKey]);
    }

    /**
     * A conversion's state, the API waiting up to $wait seconds for it to settle first.
     */
    public function conversion(string $id, int $wait = 0): ApiResult
    {
        $wait = max(0, min(60, $wait));
        $path = '/v1/conversions/' . rawurlencode($id) . ($wait > 0 ? '?wait=' . $wait : '');
        return $this->call('GET', $path, null, [], self::TIMEOUT + $wait);
    }

    /**
     * Goes ahead with a conversion that costs more than it was allowed to.
     */
    public function confirm(string $id): ApiResult
    {
        return $this->call('POST', '/v1/conversions/' . rawurlencode($id) . '/confirm', []);
    }

    /**
     * Downloads a result to $destination.
     *
     * @param string $url The result's address (it carries its own token).
     * @param float $deadline When to give up (microtime(true)).
     * @param int|null $expected The size the API announced, checked when given.
     */
    public function download(string $url, string $destination, float $deadline, ?int $expected = null): ApiResult
    {
        // A result anywhere but the API would be a mistake or a trick.
        if (!$this->sameOrigin($url)) {
            return ApiResult::local('unexpectedUrl');
        }
        $out = @fopen($destination, 'wb');
        if ($out === false) {
            return ApiResult::local('unwritable');
        }
        $status = 0;
        $error = '';
        $written = 0;
        $problem = null;
        try {
            $response = $this->http->request('GET', $url, $this->options([
                'headers' => $this->headers(false),
                'buffer' => false,
                'timeout' => 60.0,
                'max_duration' => max(10.0, $deadline - microtime(true)),
            ]));
            foreach ($this->http->stream($response) as $chunk) {
                if ($chunk->isFirst()) {
                    $status = $response->getStatusCode();
                    $length = (int) ($response->getHeaders(false)['content-length'][0] ?? 0);
                    if ($status < 300 && $length > self::MAX_RESULT_BYTES) {
                        $response->cancel();
                        $problem = 'tooLarge';
                        break;
                    }
                }
                $content = $chunk->getContent();
                if ($status >= 300) {
                    $error .= strlen($error) < 65536 ? $content : '';
                    continue;
                }
                $written += strlen($content);
                if ($written > self::MAX_RESULT_BYTES) {
                    $response->cancel();
                    $problem = 'tooLarge';
                    break;
                }
                if (fwrite($out, $content) !== strlen($content)) {
                    $response->cancel();
                    $problem = 'unwritable';
                    break;
                }
            }
        } catch (ExceptionInterface) {
            $problem = 'network';
        } finally {
            fclose($out);
        }
        if ($problem === null && $status >= 300) {
            @unlink($destination);
            return ApiResult::fromResponse($status, $error);
        }
        if ($problem === null && $expected !== null && $expected > 0 && $written !== $expected) {
            $problem = 'incomplete';
        }
        if ($problem !== null) {
            @unlink($destination);
            return ApiResult::local($problem);
        }
        return new ApiResult($status, null, '');
    }

    /**
     * Whether an address is on the API's own scheme, host and port.
     */
    public function sameOrigin(string $url): bool
    {
        $api = parse_url($this->base);
        $got = parse_url($url);
        if (!is_array($api) || !is_array($got)) {
            return false;
        }
        foreach (['scheme', 'host', 'port'] as $part) {
            if (strtolower((string) ($api[$part] ?? '')) !== strtolower((string) ($got[$part] ?? ''))) {
                return false;
            }
        }
        return true;
    }

    /**
     * A JSON call with the API key.
     *
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     */
    private function call(string $method, string $path, ?array $json = null, array $headers = [], float $timeout = self::TIMEOUT): ApiResult
    {
        if (!$this->hasKey()) {
            return ApiResult::local('noKey');
        }
        $options = [
            'headers' => $this->headers(true) + $headers,
            'timeout' => $timeout,
            'max_duration' => $timeout + 10,
        ];
        if ($json !== null) {
            // An empty object, not an empty list.
            $options['body'] = $json !== [] ? json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : '{}';
            $options['headers']['Content-Type'] = 'application/json';
        }
        return $this->send($method, $this->base . $path, $options);
    }

    /**
     * Sends a request and reads its whole answer; never throws, never logs (addresses carry
     * tokens, headers the key).
     *
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $url, array $options): ApiResult
    {
        try {
            $response = $this->http->request($method, $url, $this->options($options));
            return ApiResult::fromResponse($response->getStatusCode(), $response->getContent(false));
        } catch (ExceptionInterface) {
            return ApiResult::local('network');
        }
    }

    /**
     * Options every request shares.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function options(array $options): array
    {
        // Redirects are never followed: a key or a token must not travel to another address.
        return $options + ['max_redirects' => 0];
    }

    /**
     * The headers of a request.
     *
     * @param bool $withKey Whether to send the API key (never to the token-carrying addresses).
     * @return array<string, string>
     */
    private function headers(bool $withKey): array
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'ConverterStudio-Grav/' . $this->version,
            self::CLIENT_HEADER => 'grav/' . $this->version,
        ];
        if ($withKey && $this->hasKey()) {
            $headers['Authorization'] = 'Bearer ' . $this->key;
        }
        return $headers;
    }
}
