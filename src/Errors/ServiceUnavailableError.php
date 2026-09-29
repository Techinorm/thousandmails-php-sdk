<?php

declare(strict_types=1);

namespace ThousandMails\Errors;

/**
 * 503 — too many attachment uploads are already in flight. The request was
 * refused before any bytes were buffered, so retrying it is always safe.
 */
class ServiceUnavailableError extends ServerError
{
}
