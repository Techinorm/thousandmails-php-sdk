<?php

declare(strict_types=1);

namespace ThousandMails\Errors;

/**
 * 403 — the key is inactive, its owner is gone, the sender is pinned to a
 * different IP, or every recipient is suppressed. When suppression is the cause
 * the response carries the offending addresses, exposed here as `suppressed`.
 */
class PermissionError extends APIError
{
    /** @var list<string>|null */
    public ?array $suppressed = null;

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

        $suppressed = $this->fromBody('suppressed');
        if (\is_array($suppressed)) {
            $this->suppressed = array_values($suppressed);
        }
    }
}
