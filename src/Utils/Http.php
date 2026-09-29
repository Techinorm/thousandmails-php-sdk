<?php

declare(strict_types=1);

namespace ThousandMails\Utils;

use ThousandMails\Errors\ConfigError;
use ThousandMails\Errors\ConnectionError;
use ThousandMails\Errors\ErrorFactory;
use ThousandMails\Errors\ServerError;

/**
 * The transport every resource goes through: URL building, auth header, timeout,
 * retry policy, and turning a non-2xx response into a typed error.
 */
final class Http
{
    private const RETRY_BASE_MS = 500;
    private const RETRY_MAX_MS = 8000;

    /**
     * Returned by retryAfterMs when the server's hint is longer than
     * RETRY_MAX_MS. Distinct from null, which means the response carried no
     * hint at all. A real delay is never negative, so -1 is unambiguous.
     */
    private const TOO_LONG_TO_WAIT = -1;

    /**
     * Statuses worth trying again. 429 and 503 are both refusals issued before the
     * handler ran (the rate limiter, and the attachment admission gate), so they are
     * safe to retry whatever the method. A 5xx is ambiguous for a send — the mail may
     * already be on its way — so those only retry when an idempotency key makes a
     * repeat harmless.
     *
     * @var list<int>
     */
    private const ALWAYS_RETRY = [429, 503];

    public string $apiKey;

    public string $baseUrl;

    /**
     * Per-attempt timeout in milliseconds; 0 disables. A retried request gets a
     * fresh window rather than sharing one budget across the whole call.
     */
    public int $timeout;

    public int $maxRetries;

    /** @var array<string, string> */
    public array $defaultHeaders;

    public string $userAgent;

    public Transport $transport;

    /**
     * Rate-limit headers from the most recent response, for callers that want to
     * pace themselves rather than wait for a 429.
     *
     * @var array{limit: int, remaining: int, reset: int}|null
     */
    public ?array $lastRateLimit = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $apiKey = $options['apiKey'] ?? getenv('THOUSANDMAILS_API_KEY');

        if (!\is_string($apiKey) || trim($apiKey) === '') {
            throw new ConfigError(
                'An API key is required: new ThousandMails([\'apiKey\' => ...]) or the THOUSANDMAILS_API_KEY environment variable',
            );
        }

        $this->apiKey = trim($apiKey);

        $baseUrl = $options['baseUrl'] ?? null;
        $baseUrl = \is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : Constants::defaultBaseUrl();
        $this->baseUrl = self::normaliseBaseUrl($baseUrl);

        $this->timeout = isset($options['timeout']) ? (int) $options['timeout'] : Constants::DEFAULT_TIMEOUT_MS;
        $this->maxRetries = isset($options['maxRetries']) ? (int) $options['maxRetries'] : Constants::DEFAULT_MAX_RETRIES;

        /** @var array<string, string> $headers */
        $headers = $options['headers'] ?? [];
        $this->defaultHeaders = $headers;

        $userAgent = $options['userAgent'] ?? null;
        $this->userAgent = \is_string($userAgent) && $userAgent !== ''
            ? $userAgent
            : 'thousandmails-php/' . Constants::VERSION . ' php/' . PHP_VERSION;

        $transport = $options['transport'] ?? null;
        if ($transport !== null && !$transport instanceof Transport) {
            throw new ConfigError('transport must implement ' . Transport::class);
        }
        $this->transport = $transport ?? new CurlTransport();
    }

    /** Absolute URL for a client-API path, with empty query params dropped. */
    public function url(string $path, array $query = []): string
    {
        $url = $this->baseUrl . Constants::CLIENT_PREFIX . $path;

        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $pairs[$key] = \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $pairs === [] ? $url : $url . '?' . http_build_query($pairs, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Perform one API call, retrying per the policy above.
     *
     * @param array{
     *     method: string,
     *     path: string,
     *     query?: array<string, mixed>,
     *     json?: mixed,
     *     form?: array{body: string, contentType: string},
     *     idempotencyKey?: string|null,
     *     parse?: 'json'|'text',
     *     retryUnsafe?: bool,
     *     timeout?: int|null,
     *     headers?: array<string, string>
     * } $opts
     *
     * @return array{data: mixed, status: int, headers: array<string, string>}
     */
    public function request(array $opts): array
    {
        $method = $opts['method'];
        $parse = $opts['parse'] ?? 'json';
        $idempotencyKey = $opts['idempotencyKey'] ?? null;
        $json = \array_key_exists('json', $opts) ? $opts['json'] : null;
        $hasJson = \array_key_exists('json', $opts);
        $form = $opts['form'] ?? null;

        $url = $this->url($opts['path'], $opts['query'] ?? []);
        $timeout = $opts['timeout'] ?? $this->timeout;
        $retryable = $method === 'GET' || ($idempotencyKey !== null && $idempotencyKey !== '') || ($opts['retryUnsafe'] ?? false);

        $attempt = 0;

        while (true) {
            $headers = [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => $parse === 'text' ? 'text/csv, text/plain, */*' : 'application/json',
                'User-Agent' => $this->userAgent,
            ];
            $headers = array_merge($headers, $this->defaultHeaders, $opts['headers'] ?? []);

            $body = null;
            if ($form !== null) {
                $headers['Content-Type'] = $form['contentType'];
                $body = $form['body'];
            } elseif ($hasJson) {
                $headers['Content-Type'] = 'application/json';
                $body = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $headers['Idempotency-Key'] = $idempotencyKey;
            }

            try {
                $response = $this->transport->send([
                    'method' => $method,
                    'url' => $url,
                    'headers' => $headers,
                    'body' => $body,
                    'timeoutMs' => (int) $timeout,
                ]);
            } catch (ConnectionError $failure) {
                // A connection that never produced a response may still have delivered
                // the request, so a send is only replayed when a key makes that safe.
                if ($retryable && $attempt < $this->maxRetries) {
                    usleep(self::backoff($attempt) * 1000);
                    ++$attempt;

                    continue;
                }

                throw $failure;
            }

            $this->lastRateLimit = self::readRateLimit($response['headers']);
            $status = $response['status'];

            if ($status >= 200 && $status < 300) {
                return [
                    'data' => self::readBody($response['body'], $parse),
                    'status' => $status,
                    'headers' => $response['headers'],
                ];
            }

            $shouldRetry = $attempt < $this->maxRetries
                && (\in_array($status, self::ALWAYS_RETRY, true) || ($status >= 500 && $retryable));

            // A Retry-After longer than we are willing to wait means the window
            // will still be shut when the retry lands, so the attempts would be
            // spent for nothing. Give up now instead and let the caller pace
            // itself off `$error->retryAfter`, which carries the server's real
            // figure.
            $hinted = $shouldRetry ? self::retryAfterMs($response['headers']) : null;

            if ($shouldRetry && $hinted !== self::TOO_LONG_TO_WAIT) {
                usleep(($hinted ?? self::backoff($attempt)) * 1000);
                ++$attempt;

                continue;
            }

            throw ErrorFactory::fromResponse(
                $status,
                self::readBody($response['body'], 'json'),
                $response['headers'],
                $method,
                $url,
            );
        }
    }

    /**
     * `request()`, for the endpoints that always answer with a JSON object.
     *
     * A 2xx whose body is empty or isn't JSON means something between here and
     * the API rewrote the response — a proxy error page, a truncated reply. The
     * resource methods are typed to return arrays, so without this the caller
     * would get a TypeError from deep inside the SDK instead of something that
     * names the problem.
     *
     * @param array<string, mixed> $opts
     *
     * @return array<string, mixed>
     */
    public function requestArray(array $opts): array
    {
        $response = $this->request($opts);
        $data = $response['data'];

        if (!\is_array($data)) {
            $url = $this->url($opts['path'], $opts['query'] ?? []);
            $shown = $data === null ? 'an empty body' : 'a non-JSON body';

            throw new ServerError(
                "The API returned {$shown} with status {$response['status']}, where a JSON object was expected",
                $response['status'],
                $data,
                $response['headers'],
                $opts['method'],
                $url,
            );
        }

        return $data;
    }

    /**
     * Streaming GET — used by the SSE endpoint, which must not time out.
     *
     * The Node SDK's `open()` hands back a readable body; PHP's transport is
     * push-based, so the consumer arrives as a callback instead. Returning false
     * from it closes the connection.
     *
     * @param callable(string): bool $onChunk
     * @param array<string, mixed>   $options
     */
    public function stream(string $path, callable $onChunk, array $options = []): void
    {
        $url = $this->url($path, $options['query'] ?? []);

        $headers = array_merge([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'User-Agent' => $this->userAgent,
        ], $this->defaultHeaders, $options['headers'] ?? []);

        $response = $this->transport->stream([
            'method' => 'GET',
            'url' => $url,
            'headers' => $headers,
            'body' => null,
            'timeoutMs' => 0,
        ], $onChunk);

        $status = $response['status'];
        if ($status < 200 || $status >= 300) {
            throw ErrorFactory::fromResponse(
                $status,
                self::readBody($response['body'], 'json'),
                $response['headers'],
                'GET',
                $url,
            );
        }
    }

    /** Exponential with full jitter, so a fleet retrying together spreads out. */
    public static function backoff(int $attempt): int
    {
        $ceiling = min(self::RETRY_BASE_MS * (2 ** $attempt), self::RETRY_MAX_MS);

        return (int) round($ceiling * (0.5 + (mt_rand() / mt_getrandmax()) * 0.5));
    }

    private static function normaliseBaseUrl(string $baseUrl): string
    {
        $trimmed = rtrim(trim($baseUrl), '/');

        $parts = parse_url($trimmed);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new ConfigError("baseUrl is not a usable absolute URL: \"{$baseUrl}\"");
        }

        return $trimmed;
    }

    /**
     * @return mixed
     */
    private static function readBody(string $text, string $parse)
    {
        if ($parse === 'text') {
            return $text;
        }
        if ($text === '') {
            return null;
        }

        $decoded = json_decode($text, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $text;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{limit: int, remaining: int, reset: int}|null
     */
    private static function readRateLimit(array $headers): ?array
    {
        if (!isset($headers['ratelimit-limit'])) {
            return null;
        }

        return [
            'limit' => (int) $headers['ratelimit-limit'],
            'remaining' => (int) ($headers['ratelimit-remaining'] ?? 0),
            'reset' => (int) ($headers['ratelimit-reset'] ?? 0),
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    /**
     * How long the server asked us to wait, in ms — or TOO_LONG_TO_WAIT when
     * that exceeds the retry ceiling, or null when it said nothing.
     */
    private static function retryAfterMs(array $headers): ?int
    {
        $raw = $headers['retry-after'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            $ms = (float) $raw * 1000;
        } else {
            $at = strtotime($raw);
            if ($at === false) {
                return null;
            }
            $ms = ($at - time()) * 1000;
        }

        // A date already in the past, or a negative delay, means "now".
        $ms = max(0.0, (float) $ms);

        return $ms > self::RETRY_MAX_MS ? self::TOO_LONG_TO_WAIT : (int) $ms;
    }
}
