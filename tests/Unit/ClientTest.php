<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio\Tests\Unit;

use Grav\Plugin\ConverterStudio\Api\ApiResult;
use Grav\Plugin\ConverterStudio\Api\Client;
use Grav\Plugin\ConverterStudio\Tests\FakeApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ClientTest extends TestCase
{
    private FakeApi $api;
    private string $dir;

    protected function setUp(): void
    {
        $this->api = new FakeApi();
        $this->dir = sys_get_temp_dir() . '/cs-grav-client-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    private function client(?string $key = 'cvs_live_secret', int $partBytes = Client::PART_BYTES): Client
    {
        return new Client($this->api->client(), $key, FakeApi::BASE . '/', '1.2.3', $partBytes);
    }

    public function testApiCallsCarryTheKeyAndTheClientHeader(): void
    {
        $result = $this->client()->account();

        self::assertTrue($result->ok());
        self::assertSame('pro', $result->get('plan'));
        $request = $this->api->requests[0];
        self::assertSame(FakeApi::BASE . '/v1/account', $request['url']);
        self::assertSame('Bearer cvs_live_secret', $request['headers']['authorization']);
        self::assertSame('grav/1.2.3', $request['headers']['x-converter-studio-client']);
        self::assertSame('ConverterStudio-Grav/1.2.3', $request['headers']['user-agent']);
        self::assertArrayNotHasKey('referer', $request['headers']);
    }

    public function testWithoutAKeyNothingIsSent(): void
    {
        $result = $this->client('')->account();

        self::assertSame('noKey', $result->code);
        self::assertStringContainsString('No Converter Studio API key', $result->message());
        self::assertSame([], $this->api->requests);
    }

    public function testUploadsGoInPartsWithoutTheKey(): void
    {
        $file = $this->dir . '/photo.heic';
        file_put_contents($file, str_repeat('a', 25));
        $client = $this->client('cvs_live_secret', 10);
        $slot = $client->createUpload('photo.heic', 25);
        $upload = ['id' => (string) $slot->get('id'), 'url' => (string) $slot->get('uploadUrl'), 'offset' => 0, 'size' => 25];

        $sent = $client->sendParts($file, $upload, microtime(true) + 60);

        self::assertTrue($sent->ok());
        self::assertSame(25, $upload['offset']);
        $puts = $this->api->made('PUT /v1/uploads');
        self::assertCount(3, $puts);
        self::assertSame(['0', '10', '20'], array_map(static fn (array $put): string => $put['headers']['upload-offset'], $puts));
        foreach ($puts as $put) {
            self::assertArrayNotHasKey('authorization', $put['headers']);
            self::assertSame('grav/1.2.3', $put['headers']['x-converter-studio-client']);
        }
        self::assertSame(str_repeat('a', 25), $this->api->uploads[$upload['id']]['received']);
    }

    public function testAnUploadGoesOnFromWhatTheApiHas(): void
    {
        $file = $this->dir . '/photo.heic';
        file_put_contents($file, '0123456789abcdefghij');
        $client = $this->client('k', 10);
        $slot = $client->createUpload('photo.heic', 20);
        $id = (string) $slot->get('id');
        // The API already has the first part, but we think it has nothing.
        $this->api->uploads[$id]['received'] = '0123456789';
        $upload = ['id' => $id, 'url' => (string) $slot->get('uploadUrl'), 'offset' => 0, 'size' => 20];

        $sent = $client->sendParts($file, $upload, microtime(true) + 60);

        self::assertTrue($sent->ok());
        self::assertSame('0123456789abcdefghij', $this->api->uploads[$id]['received']);
    }

    public function testAnUploadStopsWhenTimeIsUp(): void
    {
        $file = $this->dir . '/photo.heic';
        file_put_contents($file, 'abc');
        $upload = ['id' => 'up1', 'url' => FakeApi::BASE . '/v1/uploads/up1?token=x', 'offset' => 0, 'size' => 3];

        $sent = $this->client()->sendParts($file, $upload, microtime(true) + 1);

        self::assertSame('timeUp', $sent->code);
        self::assertSame([], $this->api->requests);
    }

    public function testAFileThatChangedIsNotSent(): void
    {
        $file = $this->dir . '/photo.heic';
        file_put_contents($file, 'abcd');
        $upload = ['id' => 'up1', 'url' => FakeApi::BASE . '/v1/uploads/up1?token=x', 'offset' => 0, 'size' => 3];

        self::assertSame('changed', $this->client()->sendParts($file, $upload, microtime(true) + 60)->code);
        self::assertSame([], $this->api->requests);
    }

    public function testAnUploadAddressElsewhereIsRefused(): void
    {
        $file = $this->dir . '/photo.heic';
        file_put_contents($file, 'abc');
        $upload = ['id' => 'up1', 'url' => 'https://evil.example/v1/uploads/up1', 'offset' => 0, 'size' => 3];

        self::assertSame('unexpectedUrl', $this->client()->sendParts($file, $upload, microtime(true) + 60)->code);
        self::assertSame([], $this->api->requests);
    }

    public function testConvertSendsTheOptionsAndAnIdempotencyKey(): void
    {
        $client = $this->client();
        $slot = $client->createUpload('a.heic', 1);
        $this->api->uploads[(string) $slot->get('id')]['received'] = 'a';

        $first = $client->convert((string) $slot->get('id'), 'jpg', ['quality' => 'high'], 20, 'grav-key');
        $again = $client->convert((string) $slot->get('id'), 'jpg', ['quality' => 'high'], 20, 'grav-key');

        self::assertTrue($first->ok());
        self::assertSame(201, $first->status);
        self::assertSame(200, $again->status);
        self::assertSame($first->get('id'), $again->get('id'));
        $post = $this->api->made('POST /v1/conversions')[0];
        self::assertSame('grav-key', $post['headers']['idempotency-key']);
        self::assertSame('application/json', $post['headers']['content-type']);
        self::assertSame(
            ['uploadId' => $slot->get('id'), 'to' => 'jpg', 'maxCredits' => 20, 'options' => ['quality' => 'high']],
            json_decode($post['body'], true),
        );
    }

    public function testAStatusCheckAsksTheApiToWait(): void
    {
        $this->client()->conversion('abc', 20);
        $this->client()->conversion('abc', 500);

        self::assertStringEndsWith('/v1/conversions/abc?wait=20', $this->api->requests[0]['url']);
        self::assertStringEndsWith('/v1/conversions/abc?wait=60', $this->api->requests[1]['url']);
    }

    public function testTheApisErrorMessageIsKept(): void
    {
        $this->api->overrides[] = ['GET /v1/account', $this->api->json(401, ['error' => ['code' => 'invalidApiKey', 'message' => 'This <b>API key</b> does not work.']])];

        $result = $this->client()->account();

        self::assertFalse($result->ok());
        self::assertSame('invalidApiKey', $result->code);
        self::assertSame('This API key does not work.', $result->message());
    }

    public function testNoAnswerIsANetworkProblem(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused');
        });
        $result = (new Client($http, 'k', FakeApi::BASE, '1.0.0'))->account();

        self::assertSame('network', $result->code);
        self::assertSame(0, $result->status);
    }

    public function testRedirectsAreNotFollowed(): void
    {
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options['max_redirects'];
            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://elsewhere.example/']]);
        });
        $result = (new Client($http, 'k', FakeApi::BASE, '1.0.0'))->account();

        self::assertSame([0], $seen);
        self::assertSame('http_302', $result->code);
    }

    public function testDownloadsWriteTheResult(): void
    {
        $destination = $this->dir . '/result.jpg';
        $result = $this->client()->download(FakeApi::BASE . '/jobs/abc/download?token=t', $destination, microtime(true) + 60, strlen($this->api->result));

        self::assertTrue($result->ok());
        self::assertSame($this->api->result, file_get_contents($destination));
        self::assertArrayNotHasKey('authorization', $this->api->requests[0]['headers']);
    }

    public function testADownloadOfTheWrongSizeIsThrownAway(): void
    {
        $destination = $this->dir . '/result.jpg';
        $result = $this->client()->download(FakeApi::BASE . '/jobs/abc/download?token=t', $destination, microtime(true) + 60, 999);

        self::assertSame('incomplete', $result->code);
        self::assertFileDoesNotExist($destination);
    }

    public function testADownloadElsewhereIsRefused(): void
    {
        $destination = $this->dir . '/result.jpg';
        $result = $this->client()->download('https://evil.example/jobs/abc', $destination, microtime(true) + 60);

        self::assertSame('unexpectedUrl', $result->code);
        self::assertSame([], $this->api->requests);
        self::assertFileDoesNotExist($destination);
    }

    public function testAFailedDownloadReadsTheError(): void
    {
        $this->api->overrides[] = ['GET /jobs/', $this->api->json(410, ['error' => ['code' => 'fileExpired', 'message' => 'The file has expired.']])];
        $destination = $this->dir . '/result.jpg';

        $result = $this->client()->download(FakeApi::BASE . '/jobs/abc/download?token=t', $destination, microtime(true) + 60);

        self::assertSame('fileExpired', $result->code);
        self::assertSame(410, $result->status);
        self::assertSame('The file has expired.', $result->message());
        self::assertFileDoesNotExist($destination);
    }

    public function testSameOriginComparesSchemeHostAndPort(): void
    {
        $client = new Client($this->api->client(), 'k', 'http://localhost:8080/api', '1.0.0');

        self::assertTrue($client->sameOrigin('http://localhost:8080/api/jobs/1'));
        self::assertFalse($client->sameOrigin('http://localhost:8081/api/jobs/1'));
        self::assertFalse($client->sameOrigin('https://localhost:8080/api/jobs/1'));
        self::assertFalse($client->sameOrigin('http://localhost.evil:8080/'));
    }

    public function testAnEmptyBaseIsTheService(): void
    {
        self::assertSame('https://converter.studio/api', (new Client($this->api->client(), 'k', '', '1.0.0'))->base());
    }

    public function testLocalProblemsHaveMessages(): void
    {
        foreach (['network', 'missing', 'changed', 'unexpectedUrl', 'tooLarge', 'incomplete', 'unwritable'] as $code) {
            self::assertNotSame('', ApiResult::local($code)->message(), $code);
        }
        self::assertSame('Converter Studio answered with an error (http_500).', ApiResult::fromResponse(500, 'oops')->message());
        self::assertSame('The Converter Studio API key is not valid.', ApiResult::fromResponse(401, '')->message());
    }
}
