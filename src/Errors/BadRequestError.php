<?php

declare(strict_types=1);

namespace ThousandMails\Errors;

/**
 * 400 — a reference that does not resolve: unknown template, unknown sender,
 * unknown message or event id. The API uses 400 rather than 404 for these.
 */
class BadRequestError extends APIError
{
}
