<?php

declare(strict_types=1);

namespace ThousandMails;

use ThousandMails\Resources\Emails;
use ThousandMails\Resources\Logs;
use ThousandMails\Resources\Realtime;
use ThousandMails\Resources\Stats;
use ThousandMails\Utils\EventStream;
use ThousandMails\Utils\Http;

/**
 * The ThousandMails client.
 *
 *   $thousandmails = new ThousandMails(['apiKey' => getenv('THOUSANDMAILS_API_KEY')]);
 *   $thousandmails->sendMail(['senderemail' => …, 'to' => …, 'subject' => …, 'html' => …]);
 *
 * Every endpoint is reachable two ways: through its resource
 * (`$client->emails->send(…)`) or through a flat alias on the client itself
 * (`$client->sendMail(…)`). They call the same code — pick whichever reads better.
 */
final class ThousandMails
{
    public Http $http;

    public Emails $emails;

    public Stats $stats;

    public Realtime $realtime;

    public Logs $logs;

    /** Run the local pre-flight checks before sending. */
    public bool $validateInput;

    /** Mint an Idempotency-Key per single send so a retry can't double-send. */
    public bool $autoIdempotency;

    /** @var array<string, mixed> */
    public array $validateOptions;

    /**
     * @param array<string, mixed>|string $options an options array, or the API key on its own
     *
     * Recognised options:
     *   apiKey             string   defaults to THOUSANDMAILS_API_KEY
     *   baseUrl            string   defaults to THOUSANDMAILS_BASE_URL
     *   timeout            int      per-attempt timeout in ms (default 30000); 0 disables.
     *                               A retry gets a fresh window, so a call can take up
     *                               to (maxRetries + 1) x timeout in the worst case.
     *   maxRetries         int      retries for safe requests (default 2)
     *   validateInput      bool     local pre-flight checks (default true)
     *   validateRecipients bool     check recipient syntax locally (default true)
     *   autoIdempotency    bool     mint a key per single send (default true)
     *   headers            array    extra headers on every request
     *   userAgent          string
     *   transport          Utils\Transport   inject an HTTP implementation
     */
    public function __construct(array|string $options = [])
    {
        $config = \is_string($options) ? ['apiKey' => $options] : $options;

        $this->http = new Http($config);

        $this->validateInput = ($config['validateInput'] ?? true) !== false;
        $this->autoIdempotency = ($config['autoIdempotency'] ?? true) !== false;
        $this->validateOptions = [
            'validateRecipients' => ($config['validateRecipients'] ?? true) !== false,
        ];

        $this->emails = new Emails($this);
        $this->stats = new Stats($this);
        $this->realtime = new Realtime($this);
        $this->logs = new Logs($this);
    }

    /** Where this client points. */
    public function baseUrl(): string
    {
        return $this->http->baseUrl;
    }

    /**
     * Rate-limit headers from the most recent response, or null before the first
     * one: `['limit' => …, 'remaining' => …, 'reset' => …]`. The budget is per API
     * key (1000 requests per 15 minutes), which is the tighter of the two limiters
     * in front of the API and therefore the one worth pacing against.
     *
     * @return array{limit: int, remaining: int, reset: int}|null
     */
    public function rateLimit(): ?array
    {
        return $this->http->lastRateLimit;
    }

    /**
     * Escape hatch for anything this SDK doesn't wrap yet. `path` is relative to
     * /mailerapi/client, and the response body is returned as-is.
     *
     *   $client->request(['method' => 'GET', 'path' => '/stats/summary']);
     *
     * @param array<string, mixed> $options
     */
    public function request(array $options): mixed
    {
        return $this->http->request($options)['data'];
    }

    /* ----------------------------- flat aliases ----------------------------- */

    /**
     * @param array<string, mixed>                                $message
     * @param array{idempotencyKey?: string|false, timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function sendMail(array $message, array $options = []): array
    {
        return $this->emails->send($message, $options);
    }

    /**
     * @param list<array<string, mixed>>|array{messages: list<array<string, mixed>>} $messages
     * @param array{timeout?: int}                                                   $options
     *
     * @return array<string, mixed>
     */
    public function sendBatch(array $messages, array $options = []): array
    {
        return $this->emails->sendBatch($messages, $options);
    }

    /**
     * @param array<string, mixed>                                $message
     * @param array{idempotencyKey?: string|false, timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function sendMailWithAttachments(array $message, array $options = []): array
    {
        return $this->emails->sendWithAttachments($message, $options);
    }

    /**
     * @param list<array<string, mixed>>|array{messages: list<array<string, mixed>>} $messages
     * @param array{timeout?: int}                                                   $options
     *
     * @return array<string, mixed>
     */
    public function sendBatchWithAttachments(array $messages, array $options = []): array
    {
        return $this->emails->sendBatchWithAttachments($messages, $options);
    }

    /**
     * @param array{timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function getMessage(string $id, array $options = []): array
    {
        return $this->emails->get($id, $options);
    }

    /**
     * @param array<string, mixed> $params
     * @param array{timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function getStatsSummary(array $params = [], array $options = []): array
    {
        return $this->stats->summary($params, $options);
    }

    /**
     * @param array<string, mixed> $params
     * @param array{timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function getStatsTimeseries(array $params = [], array $options = []): array
    {
        return $this->stats->timeseries($params, $options);
    }

    /**
     * @param array<string, mixed> $params
     * @param array{timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function getStatsBySender(array $params = [], array $options = []): array
    {
        return $this->stats->bySender($params, $options);
    }

    /**
     * @param array<string, mixed> $params
     * @param array{timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function getStatsByTag(array $params = [], array $options = []): array
    {
        return $this->stats->byTag($params, $options);
    }

    /**
     * @param array{minutes?: int} $params
     * @param array{timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function getRealtimeStats(array $params = [], array $options = []): array
    {
        return $this->realtime->stats($params, $options);
    }

    /**
     * @param array{minutes?: int} $params
     * @param array{timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function getRealtimePerMinute(array $params = [], array $options = []): array
    {
        return $this->realtime->perMinute($params, $options);
    }

    /**
     * @param array{seconds?: int} $params
     * @param array{timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function getRealtimePerSecond(array $params = [], array $options = []): array
    {
        return $this->realtime->perSecond($params, $options);
    }

    /**
     * @param array{limit?: int}   $params
     * @param array{timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function getRealtimeActivity(array $params = [], array $options = []): array
    {
        return $this->realtime->activity($params, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function streamEvents(array $options = []): EventStream
    {
        return $this->realtime->stream($options);
    }

    /**
     * @param array<string, mixed> $query
     * @param array{timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function getLogs(array $query = [], array $options = []): array
    {
        return $this->logs->list($query, $options);
    }

    /**
     * @param array<string, mixed> $query
     * @param array{timeout?: int} $options
     *
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    public function iterateLogs(array $query = [], array $options = []): \Generator
    {
        yield from $this->logs->iterate($query, $options);
    }

    /**
     * @param array{timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function getLog(string $eventId, array $options = []): array
    {
        return $this->logs->get($eventId, $options);
    }

    /**
     * @param array<string, mixed> $query
     * @param array{timeout?: int} $options
     */
    public function exportLogs(array $query = [], array $options = []): string
    {
        return $this->logs->export($query, $options);
    }

    /**
     * @param array<string, mixed> $query
     * @param array{timeout?: int} $options
     */
    public function exportLogsToFile(string $filePath, array $query = [], array $options = []): int
    {
        return $this->logs->exportToFile($filePath, $query, $options);
    }
}
