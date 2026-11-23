<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio\Tests;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A small stand-in for the Converter Studio API: uploads in parts, conversions that move through
 * their states as they are checked, and downloads.
 */
final class FakeApi
{
    public const BASE = 'https://api.example.test/api';

    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    /** @var array<string, array{size: int, received: string}> */
    public array $uploads = [];

    /** @var array<string, array<string, mixed>> */
    public array $conversions = [];

    /** @var array<string, string> Idempotency-Key => conversion id */
    public array $keys = [];

    /** Statuses a conversion goes through, one per check, after it starts. */
    public array $script = ['processing', 'done'];

    /** What the converted file contains. */
    public string $result = 'converted bytes';

    /** The price, and the error a failing script ends with. */
    public int $credits = 1;
    public ?array $failure = null;

    /** Answers to give before the normal ones, by "METHOD path" prefix (consumed in order). */
    public array $overrides = [];

    public function client(): MockHttpClient
    {
        return new MockHttpClient(fn (string $method, string $url, array $options) => $this->answer($method, $url, $options), self::BASE);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function answer(string $method, string $url, array $options): MockResponse
    {
        $headers = [];
        foreach ($options['headers'] ?? [] as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        $body = $options['body'] ?? '';
        if (is_callable($body)) {
            $all = '';
            while (($chunk = $body(16384)) !== '') {
                $all .= $chunk;
            }
            $body = $all;
        }
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => (string) $body];
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = substr($path, strlen((string) parse_url(self::BASE, PHP_URL_PATH)));
        foreach ($this->overrides as $i => [$prefix, $response]) {
            if (str_starts_with($method . ' ' . $path, $prefix)) {
                unset($this->overrides[$i]);
                // A callable may answer null to let this request through as usual.
                $answer = $response instanceof MockResponse ? $response : $response();
                if ($answer !== null) {
                    return $answer;
                }
                break;
            }
        }
        if ($method === 'GET' && $path === '/v1/account') {
            return $this->json(200, ['plan' => 'pro', 'credits' => 500]);
        }
        if ($method === 'POST' && $path === '/v1/uploads') {
            $data = json_decode((string) $body, true);
            $id = 'up' . str_pad((string) (count($this->uploads) + 1), 8, '0', STR_PAD_LEFT);
            $this->uploads[$id] = ['size' => (int) $data['size'], 'received' => ''];
            return $this->json(201, [
                'id' => $id,
                'uploadUrl' => self::BASE . '/v1/uploads/' . $id . '?token=tok_' . $id,
                'expiresAt' => gmdate('c', time() + 3600),
                'partBytes' => 95000000,
            ]);
        }
        if ($method === 'PUT' && str_starts_with($path, '/v1/uploads/')) {
            $id = substr($path, strlen('/v1/uploads/'));
            if (!isset($this->uploads[$id])) {
                return $this->json(404, ['error' => ['code' => 'notFound', 'message' => 'Not found.']]);
            }
            $offset = (int) ($headers['upload-offset'] ?? 0);
            if ($offset !== strlen($this->uploads[$id]['received'])) {
                return $this->json(409, ['error' => ['code' => 'invalidRequest', 'message' => 'Gap.', 'params' => ['received' => strlen($this->uploads[$id]['received'])]]]);
            }
            $this->uploads[$id]['received'] .= $body;
            $ready = strlen($this->uploads[$id]['received']) >= $this->uploads[$id]['size'];
            return $this->json(200, ['id' => $id, 'status' => $ready ? 'ready' : 'partial', 'received' => strlen($this->uploads[$id]['received'])]);
        }
        if ($method === 'POST' && $path === '/v1/conversions') {
            $data = json_decode((string) $body, true);
            $key = $headers['idempotency-key'] ?? '';
            if ($key !== '' && isset($this->keys[$key])) {
                return $this->json(200, $this->view($this->keys[$key]));
            }
            $id = (string) $data['uploadId'];
            if (!isset($this->uploads[$id])) {
                return $this->json(404, ['error' => ['code' => 'notFound', 'message' => 'Not found.']]);
            }
            $quoted = $this->credits > (int) $data['maxCredits'];
            $this->conversions[$id] = [
                'id' => $id,
                'to' => $data['to'],
                'options' => $data['options'] ?? [],
                'status' => $quoted ? 'quoted' : 'queued',
                'step' => 0,
                'confirmed' => !$quoted,
            ];
            if ($key !== '') {
                $this->keys[$key] = $id;
            }
            return $this->json(201, $this->view($id));
        }
        if ($method === 'POST' && preg_match('#^/v1/conversions/([^/]+)/confirm$#', $path, $m)) {
            $this->conversions[$m[1]]['confirmed'] = true;
            $this->conversions[$m[1]]['status'] = 'queued';
            return $this->json(200, $this->view($m[1]));
        }
        if ($method === 'GET' && preg_match('#^/v1/conversions/([^/?]+)$#', $path, $m)) {
            $id = $m[1];
            if (!isset($this->conversions[$id])) {
                return $this->json(404, ['error' => ['code' => 'notFound', 'message' => 'Not found.']]);
            }
            $conversion = &$this->conversions[$id];
            if ($conversion['confirmed'] && !in_array($conversion['status'], ['done', 'failed'], true)) {
                $conversion['status'] = $this->script[min($conversion['step'], count($this->script) - 1)];
                $conversion['step']++;
            }
            return $this->json(200, $this->view($id));
        }
        if ($method === 'GET' && str_starts_with($path, '/jobs/')) {
            return new MockResponse($this->result, ['http_code' => 200, 'response_headers' => ['Content-Length' => (string) strlen($this->result)]]);
        }
        return $this->json(404, ['error' => ['code' => 'notFound', 'message' => 'Not found.']]);
    }

    /**
     * @return array<string, mixed>
     */
    public function view(string $id): array
    {
        $conversion = $this->conversions[$id];
        $done = $conversion['status'] === 'done';
        $failed = $conversion['status'] === 'failed';
        return [
            'id' => $id,
            'status' => $conversion['status'],
            'to' => $conversion['to'],
            'credits' => $this->credits,
            'outputSize' => $done ? strlen($this->result) : null,
            'downloadUrl' => $done ? self::BASE . '/jobs/' . $id . '/download?token=dl' : null,
            'error' => $failed ? ($this->failure ?? ['code' => 'conversionFailed', 'message' => 'The file could not be converted.']) : null,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    public function json(int $status, array $body): MockResponse
    {
        return new MockResponse((string) json_encode($body), ['http_code' => $status, 'response_headers' => ['Content-Type' => 'application/json']]);
    }

    /**
     * The requests made to a path ("PUT /v1/uploads", "POST /v1/conversions"…).
     *
     * @return array<int, array{method: string, url: string, headers: array<string, string>, body: string}>
     */
    public function made(string $prefix): array
    {
        $base = (string) parse_url(self::BASE, PHP_URL_PATH);
        return array_values(array_filter($this->requests, static function (array $request) use ($prefix, $base): bool {
            $path = substr((string) parse_url($request['url'], PHP_URL_PATH), strlen($base));
            return str_starts_with($request['method'] . ' ' . $path, $prefix);
        }));
    }
}
