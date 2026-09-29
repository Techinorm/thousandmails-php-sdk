<?php

declare(strict_types=1);

namespace ThousandMails\Errors;

/** The API answered with a non-2xx status. */
class APIError extends ThousandMailsError
{
    public int $status;

    /** Decoded response body — an array for JSON, a string when it wasn't. */
    public mixed $body;

    /** @var array<string, string> Response headers, lower-cased names. */
    public array $headers;

    public string $method;

    public string $url;

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
        parent::__construct($message);

        $this->status = $status;
        $this->body = $body;
        $this->headers = $headers;
        $this->method = $method;
        $this->url = $url;
    }

    /** A field from the JSON body, or null when the body wasn't an object. */
    protected function fromBody(string $key): mixed
    {
        return \is_array($this->body) ? ($this->body[$key] ?? null) : null;
    }
}
