<?php

declare(strict_types=1);

namespace ThousandMails\Resources;

use ThousandMails\Errors\InvalidInputError;
use ThousandMails\Errors\ThousandMailsError;
use ThousandMails\ThousandMails;
use ThousandMails\Utils\Constants;
use ThousandMails\Utils\Http;

/**
 * The delivery event log.
 *
 *   GET /client/logs
 *   GET /client/logs/export   (CSV)
 *   GET /client/logs/:id
 */
final class Logs
{
    public function __construct(private ThousandMails $client)
    {
    }

    private function http(): Http
    {
        return $this->client->http;
    }

    /**
     * One page of events, newest first.
     *
     * @param array{
     *     to?: string, recipient?: string, search?: string,
     *     event?: string, type?: string,
     *     from?: string|\DateTimeInterface, until?: string|\DateTimeInterface,
     *     page?: int, pageSize?: int, limit?: int
     * } $query
     * @param array{timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function list(array $query = [], array $options = []): array
    {
        $pageSize = $query['pageSize'] ?? $query['limit'] ?? null;
        $bounds = Constants::LIMITS['logPageSize'];

        if ($pageSize !== null && (!is_numeric($pageSize) || (int) $pageSize < $bounds['min'] || (int) $pageSize > $bounds['max'])) {
            throw new InvalidInputError(
                "pageSize must be between {$bounds['min']} and {$bounds['max']}",
                ['field' => 'pageSize', 'value' => $pageSize],
            );
        }

        return $this->http()->requestArray([
            'method' => 'GET',
            'path' => '/logs',
            'query' => self::filters($query) + [
                'page' => $query['page'] ?? null,
                'pageSize' => $pageSize,
            ],
            'timeout' => $options['timeout'] ?? null,
        ]);
    }

    /**
     * Walk every page of a filtered log, yielding one event at a time.
     *
     * Deep paging is bounded server-side: past ~10,000 rows the response comes back
     * with `truncated: true` and iteration stops rather than looping on a page that
     * can never advance. Narrow with filters instead of paging that far.
     *
     *   foreach ($client->logs->iterate(['event' => 'bounced']) as $event) { … }
     *
     * @param array<string, mixed> $query
     * @param array{timeout?: int} $options
     *
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    public function iterate(array $query = [], array $options = []): \Generator
    {
        $pageSize = $query['pageSize'] ?? $query['limit'] ?? Constants::LIMITS['logPageSize']['max'];
        $page = (int) ($query['page'] ?? 1);

        while (true) {
            // The loop's page/pageSize must win over whatever the caller passed,
            // or a `page` in $query would pin every iteration to the same page.
            $result = $this->list(array_merge($query, ['pageSize' => $pageSize, 'page' => $page]), $options);

            $events = $result['events'] ?? [];
            foreach ($events as $event) {
                yield $event;
            }

            if (empty($result['hasMore']) || !empty($result['truncated']) || $events === []) {
                return;
            }
            ++$page;
        }
    }

    /**
     * Fetch one event by its `eventId`.
     *
     * @param array{timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function get(string $eventId, array $options = []): array
    {
        if (trim($eventId) === '') {
            throw new InvalidInputError('An eventId is required');
        }

        $data = $this->http()->requestArray([
            'method' => 'GET',
            'path' => '/logs/' . rawurlencode($eventId),
            'timeout' => $options['timeout'] ?? null,
        ]);

        // The endpoint wraps its payload; unwrap so it matches every other event shape.
        return isset($data['event']) && \is_array($data['event']) ? $data['event'] : $data;
    }

    /**
     * The same filtered log as CSV (up to 5000 rows), returned as a string. Paging
     * params do not apply. Columns: eventId, occurredAt, type, recipient,
     * senderEmail, subject, messageId, queueId, dsn, ip.
     *
     * @param array<string, mixed> $query
     * @param array{timeout?: int} $options
     */
    public function export(array $query = [], array $options = []): string
    {
        return (string) $this->http()->request([
            'method' => 'GET',
            'path' => '/logs/export',
            'query' => self::filters($query),
            'parse' => 'text',
            'timeout' => $options['timeout'] ?? null,
        ])['data'];
    }

    /**
     * Convenience: export straight to a file on disk. Returns the row count.
     *
     * @param array<string, mixed> $query
     * @param array{timeout?: int} $options
     */
    public function exportToFile(string $filePath, array $query = [], array $options = []): int
    {
        $csv = $this->export($query, $options);

        if (@file_put_contents($filePath, $csv) === false) {
            throw new ThousandMailsError("Could not write the export to \"{$filePath}\"");
        }

        return self::countRows($csv);
    }

    /**
     * Data rows, excluding the header.
     *
     * Counting line breaks would overcount: the server quotes any cell holding a
     * newline (a subject can), so the CSV is parsed rather than split.
     */
    private static function countRows(string $csv): int
    {
        if (trim($csv) === '') {
            return 0;
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return 0;
        }

        fwrite($handle, $csv);
        rewind($handle);

        $rows = 0;
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            // fgetcsv yields [null] for a blank line, including a trailing one.
            if ($row === [null]) {
                continue;
            }
            ++$rows;
        }
        fclose($handle);

        return max(0, $rows - 1);
    }

    /**
     * `from`/`until` accept a YYYY-MM-DD day (widened to cover it) or any
     * parsable timestamp. The API ignores an unparsable value rather than
     * rejecting it, which silently widens the window — so it is checked here.
     */
    public static function toBound(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        $text = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            return $text;
        }
        if (strtotime($text) === false) {
            throw new InvalidInputError(
                "{$field} must be YYYY-MM-DD, an ISO timestamp, or a DateTimeInterface — "
                . 'the API ignores anything it cannot parse',
                ['field' => $field, 'value' => $text],
            );
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, string|null>
     */
    private static function filters(array $query): array
    {
        $type = $query['event'] ?? $query['type'] ?? null;
        if ($type !== null && !\in_array($type, Constants::EVENT_TYPES, true)) {
            throw new InvalidInputError(
                'event must be one of: ' . implode(', ', Constants::EVENT_TYPES),
                ['field' => 'event', 'value' => $type],
            );
        }

        return [
            'to' => $query['to'] ?? $query['recipient'] ?? null,
            'search' => $query['search'] ?? null,
            'event' => $type,
            'from' => self::toBound($query['from'] ?? null, 'from'),
            'until' => self::toBound($query['until'] ?? null, 'until'),
        ];
    }
}
