<?php

declare(strict_types=1);

namespace ThousandMails\Utils;

use ThousandMails\Errors\ConnectionError;
use ThousandMails\Errors\TimeoutError;

/**
 * The default Transport: ext-curl, no Composer dependencies.
 *
 * cURL is chosen over stream wrappers because it reports a timeout distinctly
 * from a connection failure (which the retry policy needs), and because its
 * write callback lets the SSE endpoint be consumed incrementally rather than
 * buffered whole.
 */
final class CurlTransport implements Transport
{
    public function __construct()
    {
        if (!\extension_loaded('curl')) {
            throw new ConnectionError('The curl extension is required. Enable ext-curl, or pass your own transport.');
        }
    }

    public function send(array $request): array
    {
        $headers = [];
        $handle = $this->prepare($request, $headers);

        $body = curl_exec($handle);
        $this->assertOk($handle, $request);

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [
            'status' => $status,
            'headers' => $headers,
            'body' => \is_string($body) ? $body : '',
        ];
    }

    public function stream(array $request, callable $onChunk): array
    {
        $headers = [];
        $handle = $this->prepare($request, $headers);

        // Until the status line has been seen the response could still be an
        // error page, so bytes are buffered rather than dispatched. Once a 2xx
        // is confirmed they go straight to the consumer.
        $errorBody = '';
        $stopped = false;

        curl_setopt($handle, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function ($_handle, string $chunk) use (
            $onChunk,
            &$errorBody,
            &$stopped,
            &$headers,
        ): int {
            $length = \strlen($chunk);
            $status = (int) ($headers[':status'] ?? 0);

            if ($status >= 400 || $status === 0) {
                $errorBody .= $chunk;

                return $length;
            }

            if ($stopped) {
                return -1; // aborts the transfer
            }

            if ($onChunk($chunk) === false) {
                $stopped = true;

                return -1;
            }

            return $length;
        });

        curl_exec($handle);

        // A caller-requested stop surfaces as CURLE_WRITE_ERROR; that is a clean
        // shutdown, not a failure, so it must not become a ConnectionError.
        if (!$stopped) {
            $this->assertOk($handle, $request);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        unset($headers[':status']);

        return ['status' => $status, 'headers' => $headers, 'body' => $errorBody];
    }

    /**
     * @param array{method: string, url: string, headers: array<string, string>, body: string|null, timeoutMs: int} $request
     * @param array<string, string>                                                                                 $headers captured response headers, by reference
     *
     * @return \CurlHandle
     */
    private function prepare(array $request, array &$headers)
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new ConnectionError('Could not initialise a curl handle');
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $request['url'],
            CURLOPT_CUSTOMREQUEST => $request['method'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => self::formatHeaders($request['headers']),
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if ($request['body'] !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request['body']);
        }

        // 0 disables, matching the SDK's documented `timeout: 0`.
        $timeout = $request['timeoutMs'];
        if ($timeout > 0) {
            curl_setopt($handle, CURLOPT_TIMEOUT_MS, $timeout);
        } else {
            curl_setopt($handle, CURLOPT_TIMEOUT, 0);
        }

        curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function ($_handle, string $line) use (&$headers): int {
            $length = \strlen($line);
            $trimmed = trim($line);

            // A redirect or a 100-continue means a second status line; the last
            // one wins, and its headers replace what came before.
            if (str_starts_with($trimmed, 'HTTP/')) {
                $parts = explode(' ', $trimmed);
                $headers = [':status' => $parts[1] ?? '0'];

                return $length;
            }

            $colon = strpos($trimmed, ':');
            if ($colon !== false) {
                $name = strtolower(trim(substr($trimmed, 0, $colon)));
                $headers[$name] = trim(substr($trimmed, $colon + 1));
            }

            return $length;
        });

        return $handle;
    }

    /**
     * @param \CurlHandle                                                                                           $handle
     * @param array{method: string, url: string, headers: array<string, string>, body: string|null, timeoutMs: int} $request
     */
    private function assertOk($handle, array $request): void
    {
        $errno = curl_errno($handle);
        if ($errno === 0) {
            return;
        }

        $message = curl_error($handle);
        $method = $request['method'];
        $url = $request['url'];
        curl_close($handle);

        if ($errno === \CURLE_OPERATION_TIMEDOUT) {
            throw new TimeoutError("{$method} {$url} timed out");
        }

        throw new ConnectionError("{$method} {$url} failed: " . ($message !== '' ? $message : 'connection error'));
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private static function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = "{$name}: {$value}";
        }

        // Left unset, curl adds "Expect: 100-continue" to large POSTs, which costs
        // a round trip against a server that never sends the continuation.
        $formatted[] = 'Expect:';

        return $formatted;
    }
}
