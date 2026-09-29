<?php

declare(strict_types=1);

namespace ThousandMails\Resources;

use ThousandMails\Errors\InvalidInputError;
use ThousandMails\ThousandMails;
use ThousandMails\Utils\Constants;
use ThousandMails\Utils\EventStream;
use ThousandMails\Utils\Http;

/**
 * Live counters, feeds and the event stream.
 *
 *   GET /client/realtime/stats
 *   GET /client/realtime/per-minute
 *   GET /client/realtime/per-second
 *   GET /client/realtime/activity
 *   GET /client/realtime/stream   (Server-Sent Events)
 */
final class Realtime
{
    public function __construct(private ThousandMails $client)
    {
    }

    private function http(): Http
    {
        return $this->client->http;
    }

    /**
     * Every metric summed over the last N minutes (1–120, default 60).
     *
     * @param array{minutes?: int}  $params
     * @param array{timeout?: int}  $options
     *
     * @return array<string, mixed>
     */
    public function stats(array $params = [], array $options = []): array
    {
        return $this->http()->requestArray([
            'method' => 'GET',
            'path' => '/realtime/stats',
            'query' => ['minutes' => self::bounded($params['minutes'] ?? null, 'minutes', Constants::LIMITS['realtimeMinutes'])],
            'timeout' => $options['timeout'] ?? null,
        ]);
    }

    /**
     * The last N per-minute buckets, oldest first (1–120, default 60).
     *
     * @param array{minutes?: int} $params
     * @param array{timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function perMinute(array $params = [], array $options = []): array
    {
        return $this->http()->requestArray([
            'method' => 'GET',
            'path' => '/realtime/per-minute',
            'query' => ['minutes' => self::bounded($params['minutes'] ?? null, 'minutes', Constants::LIMITS['realtimeMinutes'])],
            'timeout' => $options['timeout'] ?? null,
        ]);
    }

    /**
     * Per-second buckets over the last N seconds (1–600, default 120), zero-filled
     * so the series is continuous.
     *
     * The API derives these from at most 1000 raw events, newest first, so an
     * account busier than that in the window will see the oldest seconds reported
     * as idle. Narrow the window when volume is high.
     *
     * @param array{seconds?: int} $params
     * @param array{timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function perSecond(array $params = [], array $options = []): array
    {
        return $this->http()->requestArray([
            'method' => 'GET',
            'path' => '/realtime/per-second',
            'query' => ['seconds' => self::bounded($params['seconds'] ?? null, 'seconds', Constants::LIMITS['realtimeSeconds'])],
            'timeout' => $options['timeout'] ?? null,
        ]);
    }

    /**
     * The most recent events, newest first (1–100, default 20).
     *
     * @param array{limit?: int}   $params
     * @param array{timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function activity(array $params = [], array $options = []): array
    {
        return $this->http()->requestArray([
            'method' => 'GET',
            'path' => '/realtime/activity',
            'query' => ['limit' => self::bounded($params['limit'] ?? null, 'limit', Constants::LIMITS['activityLimit'])],
            'timeout' => $options['timeout'] ?? null,
        ]);
    }

    /**
     * Open the live event stream. Returns an EventStream that dispatches to
     * handlers through `listen()`, or yields email events through `events()`.
     * Reconnects with backoff unless `reconnect => false` or the failure is an
     * auth error, which will never resolve itself.
     *
     * Always call `close()` (or `break` out of the loop) — the connection is held
     * open by design and blocks the script otherwise.
     *
     * @param array<string, mixed> $options
     */
    public function stream(array $options = []): EventStream
    {
        return new EventStream($this->http(), $options);
    }

    /**
     * The API clamps out-of-range values silently, which makes a typo look like data.
     * Rejecting locally keeps the window a caller asked for and the one they get the
     * same thing.
     *
     * @param array{min: int, max: int, default: int} $bounds
     */
    private static function bounded(mixed $value, string $name, array $bounds): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (int) $value < $bounds['min'] || (int) $value > $bounds['max']) {
            throw new InvalidInputError(
                "{$name} must be a number between {$bounds['min']} and {$bounds['max']}",
                ['field' => $name, 'value' => $value],
            );
        }

        return (int) $value;
    }
}
