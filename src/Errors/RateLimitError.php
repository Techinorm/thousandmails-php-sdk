<?php

declare(strict_types=1);

namespace ThousandMails\Errors;

/** 429 — the per-key limiter tripped. `retryAfter` is in seconds. */
class RateLimitError extends APIError
{
    /** Seconds to wait, when the response said so. */
    public ?float $retryAfter = null;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $message,
        int $status = 0,
        mixed $body = null,
        array $headers = [],
        string $method = '',
        string $url = '',
    ) {
        parent::__construct($message, $status, $body, $headers, $method, $url);

        $this->retryAfter = self::parseRetryAfter($headers);
    }

    /**
     * Retry-After is either a delay in seconds or an HTTP date; normalise to seconds.
     *
     * @param array<string, string> $headers
     */
    private static function parseRetryAfter(array $headers): ?float
    {
        $raw = $headers['retry-after'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_numeric($raw)) {
            return (float) $raw;
        }

        $at = strtotime($raw);

        return $at === false ? null : max(0.0, (float) ($at - time()));
    }
}
