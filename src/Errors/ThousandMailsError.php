<?php

declare(strict_types=1);

namespace ThousandMails\Errors;

/**
 * Every failure the SDK raises is an instance of ThousandMailsError, so a caller can
 * catch one type and branch with `instanceof` for the specific case.
 *
 * The API answers with two body shapes — `{ message }` for validation and lookup
 * failures, `{ error }` for authentication and rate limiting — so the message is
 * pulled from whichever is present rather than assuming one.
 */
class ThousandMailsError extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
