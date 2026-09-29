<?php

declare(strict_types=1);

namespace ThousandMails\Resources;

use ThousandMails\Errors\InvalidInputError;
use ThousandMails\ThousandMails;
use ThousandMails\Utils\Constants;
use ThousandMails\Utils\Http;

/**
 * Aggregate statistics.
 *
 *   GET /client/stats/summary
 *   GET /client/stats/timeseries
 *   GET /client/stats/by-sender
 *   GET /client/stats/by-tag
 *
 * `from`/`to` are YYYY-MM-DD in UTC and default server-side to the last 30 days.
 * A DateTimeInterface is accepted here and converted, because handing one to a
 * query string otherwise yields a form the API silently ignores.
 */
final class Stats
{
    public function __construct(private ThousandMails $client)
    {
    }

    private function http(): Http
    {
        return $this->client->http;
    }

    /**
     * Totals for the window, the preceding window of equal length (for
     * period-over-period deltas), and the derived rates.
     *
     * @param array{from?: string|\DateTimeInterface, to?: string|\DateTimeInterface} $params
     * @param array{timeout?: int}                                                    $options
     *
     * @return array<string, mixed>
     */
    public function summary(array $params = [], array $options = []): array
    {
        return $this->http()->requestArray([
            'method' => 'GET',
            'path' => '/stats/summary',
            'query' => self::range($params),
            'timeout' => $options['timeout'] ?? null,
        ]);
    }

    /**
     * Per-interval buckets across the window. `interval`: day (default) | week | month.
     *
     * @param array{from?: string|\DateTimeInterface, to?: string|\DateTimeInterface, interval?: string} $params
     * @param array{timeout?: int}                                                                       $options
     *
     * @return array<string, mixed>
     */
    public function timeseries(array $params = [], array $options = []): array
    {
        $interval = $params['interval'] ?? null;
        if ($interval !== null && !\in_array($interval, Constants::INTERVALS, true)) {
            throw new InvalidInputError(
                'interval must be one of: ' . implode(', ', Constants::INTERVALS),
                ['field' => 'interval', 'value' => $interval],
            );
        }

        return $this->http()->requestArray([
            'method' => 'GET',
            'path' => '/stats/timeseries',
            'query' => self::range($params) + ['interval' => $interval],
            'timeout' => $options['timeout'] ?? null,
        ]);
    }

    /**
     * Totals grouped by sender address.
     *
     * @param array{from?: string|\DateTimeInterface, to?: string|\DateTimeInterface} $params
     * @param array{timeout?: int}                                                    $options
     *
     * @return array<string, mixed>
     */
    public function bySender(array $params = [], array $options = []): array
    {
        return $this->http()->requestArray([
            'method' => 'GET',
            'path' => '/stats/by-sender',
            'query' => self::range($params),
            'timeout' => $options['timeout'] ?? null,
        ]);
    }

    /**
     * Send outcomes grouped by the record's `tag`, optionally narrowed to a list.
     *
     * Note: no send endpoint accepts a tag yet, so in practice every send is
     * untagged and the whole window collapses into a single `tag: null` row.
     *
     * @param array{from?: string|\DateTimeInterface, to?: string|\DateTimeInterface, tags?: string|list<string>} $params
     * @param array{timeout?: int}                                                                                $options
     *
     * @return array<string, mixed>
     */
    public function byTag(array $params = [], array $options = []): array
    {
        $tags = $params['tags'] ?? null;
        if (\is_array($tags)) {
            $tags = implode(',', $tags);
        }

        return $this->http()->requestArray([
            'method' => 'GET',
            'path' => '/stats/by-tag',
            'query' => self::range($params) + ['tags' => $tags],
            'timeout' => $options['timeout'] ?? null,
        ]);
    }

    /**
     * Normalise a YYYY-MM-DD day, rejecting anything the API would silently drop.
     */
    public static function toDay(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $text = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
            throw new InvalidInputError(
                "{$field} must be a YYYY-MM-DD date or a DateTimeInterface — the API silently ignores "
                . 'anything else and falls back to its default window',
                ['field' => $field, 'value' => $text],
            );
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{from: string|null, to: string|null}
     */
    private static function range(array $params): array
    {
        return [
            'from' => self::toDay($params['from'] ?? null, 'from'),
            'to' => self::toDay($params['to'] ?? null, 'to'),
        ];
    }
}
