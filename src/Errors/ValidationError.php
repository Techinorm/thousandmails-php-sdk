<?php

declare(strict_types=1);

namespace ThousandMails\Errors;

/**
 * 422 — the request was understood and rejected. Placeholder failures carry
 * `requiredFields`/`missingFields`; attachment failures carry `field`.
 */
class ValidationError extends APIError
{
    /** @var list<string>|null */
    public ?array $requiredFields = null;

    /** @var list<string>|null */
    public ?array $missingFields = null;

    public ?string $field = null;

    /** @var list<string>|null */
    public ?array $allowed = null;

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

        $required = $this->fromBody('requiredFields');
        if (\is_array($required)) {
            $this->requiredFields = array_values($required);
        }

        $missing = $this->fromBody('missingFields');
        if (\is_array($missing)) {
            $this->missingFields = array_values($missing);
        }

        $field = $this->fromBody('field');
        if (\is_string($field)) {
            $this->field = $field;
        }

        $allowed = $this->fromBody('allowed');
        if (\is_array($allowed)) {
            $this->allowed = array_values($allowed);
        }
    }
}
