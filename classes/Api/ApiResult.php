<?php

declare(strict_types=1);

namespace Grav\Plugin\ConverterStudio\Api;

/**
 * One answer of the Converter Studio API, or a problem found before an answer came.
 */
final class ApiResult
{
    /** Messages for the problems this plugin finds itself (the API words its own). */
    private const LOCAL_MESSAGES = [
        'noKey' => 'No Converter Studio API key is set. Add one in the plugin settings.',
        'network' => 'Converter Studio could not be reached. Try again in a few minutes.',
        'timeUp' => 'The conversion is still running.',
        'unexpectedUrl' => 'The API answered with an address that is not its own.',
        'tooLarge' => 'The result is larger than this plugin accepts.',
        'missing' => 'The file is no longer there.',
        'changed' => 'The file changed while it was being sent.',
        'incomplete' => 'The result was not downloaded completely.',
        'unwritable' => 'The result could not be written next to the original.',
    ];

    /**
     * @param int $status The HTTP status; 0 when no answer came.
     * @param array<string, mixed>|null $body The decoded JSON body.
     * @param string $code Empty when it worked; otherwise the API's error code (such as
     *     notEnoughCredits), one of LOCAL_MESSAGES' keys, or "http_<status>".
     * @param string $message The API's own explanation of an error, as plain text.
     */
    public function __construct(
        public readonly int $status,
        public readonly ?array $body,
        public readonly string $code,
        private readonly string $message = '',
    ) {
    }

    /**
     * A result from a status and a raw body.
     */
    public static function fromResponse(int $status, string $raw): self
    {
        $body = json_decode($raw, true);
        $body = is_array($body) ? $body : null;
        if ($status >= 200 && $status < 300) {
            return new self($status, $body, '');
        }
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $code = is_string($error['code'] ?? null) && preg_match('/^[A-Za-z_]{1,64}$/', $error['code'])
            ? $error['code']
            : 'http_' . $status;
        $message = is_string($error['message'] ?? null) ? $error['message'] : '';
        return new self($status, $body, $code, $message);
    }

    /**
     * A problem this plugin found itself (no key, no answer, a file that changed…).
     */
    public static function local(string $code): self
    {
        return new self(0, null, $code);
    }

    /**
     * Whether the request worked.
     */
    public function ok(): bool
    {
        return $this->code === '';
    }

    /**
     * What went wrong, in a sentence for people: the API's own message when it gave one.
     */
    public function message(): string
    {
        if ($this->ok()) {
            return '';
        }
        $text = self::clean($this->message);
        if ($text !== '') {
            return $text;
        }
        if (isset(self::LOCAL_MESSAGES[$this->code])) {
            return self::LOCAL_MESSAGES[$this->code];
        }
        return $this->status === 401
            ? 'The Converter Studio API key is not valid.'
            : sprintf('Converter Studio answered with an error (%s).', $this->code);
    }

    /**
     * The error's parameters (such as needed and balance for credits).
     *
     * @return array<string, string|int|float>
     */
    public function params(): array
    {
        $params = $this->body['error']['params'] ?? [];
        if (!is_array($params)) {
            return [];
        }
        return array_filter($params, static fn ($value): bool => is_scalar($value) && !is_bool($value));
    }

    /**
     * A value of the body.
     */
    public function get(string $name): mixed
    {
        return $this->body[$name] ?? null;
    }

    /**
     * Text from the API made safe to show anywhere: no markup, one line, a sensible length.
     */
    public static function clean(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
        return mb_strlen($text) > 300 ? mb_substr($text, 0, 299) . '…' : $text;
    }
}
