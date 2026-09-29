<?php

declare(strict_types=1);

namespace ThousandMails\Errors;

/**
 * Builds the right APIError subclass for a response — the counterpart of
 * `fromResponse` in the Node SDK's utils/Errors.js.
 */
final class ErrorFactory
{
    /** @var array<int, class-string<APIError>> */
    private const BY_STATUS = [
        400 => BadRequestError::class,
        401 => AuthenticationError::class,
        403 => PermissionError::class,
        404 => NotFoundError::class,
        413 => PayloadTooLargeError::class,
        415 => UnsupportedMediaTypeError::class,
        422 => ValidationError::class,
        429 => RateLimitError::class,
        503 => ServiceUnavailableError::class,
    ];

    /**
     * @param array<string, string> $headers
     */
    public static function fromResponse(
        int $status,
        mixed $body,
        array $headers,
        string $method,
        string $url,
    ): APIError {
        /** @var class-string<APIError> $class */
        $class = self::BY_STATUS[$status] ?? ($status >= 500 ? ServerError::class : APIError::class);

        return new $class(self::messageOf($body, $status), $status, $body, $headers, $method, $url);
    }

    private static function messageOf(mixed $body, int $status): string
    {
        if (\is_string($body) && trim($body) !== '') {
            return substr(trim($body), 0, 500);
        }
        if (\is_array($body)) {
            if (isset($body['message']) && \is_string($body['message'])) {
                return $body['message'];
            }
            if (isset($body['error']) && \is_string($body['error'])) {
                return $body['error'];
            }
        }

        return "ThousandMails API request failed with status {$status}";
    }
}
