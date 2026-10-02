<?php

declare(strict_types=1);

namespace ThousandMails\Utils;

/**
 * Values mirrored from the server so the SDK can reject a bad request locally
 * instead of spending a round trip on it.
 *
 * Every constant here has a counterpart in src/backend/client. When the server
 * changes a limit, change it here too — the pairs are called out per entry.
 */
final class Constants
{
    /** Every client endpoint hangs off this prefix (backend/client/routes/sendmail.routes.js). */
    public const CLIENT_PREFIX = '/client';

    public const DEFAULT_TIMEOUT_MS = 30000;
    public const DEFAULT_MAX_RETRIES = 2;

    /** The host used when nothing overrides it. */
    public const FALLBACK_BASE_URL = 'https://service.thousandmails.com/mailerapi';

    // --- send limits ---------------------------------------------------------

    /** Sendpipeline.MAX_BATCH */
    public const MAX_BATCH = 100;
    /** middleware/Attachment.MAX_ATTACHMENT_BATCH */
    public const MAX_ATTACHMENT_BATCH = 20;
    /** middleware/Attachment.MAX_ATTACHMENTS_PER_MESSAGE */
    public const MAX_ATTACHMENTS_PER_MESSAGE = 5;
    /** middleware/Attachment.MAX_FILE_BYTES */
    public const MAX_FILE_BYTES = 5 * 1024 * 1024;
    /** middleware/Attachment.MAX_REQUEST_BYTES */
    public const MAX_REQUEST_BYTES = 20 * 1024 * 1024;
    /** middleware/Attachment.MAX_FILES_PER_BATCH_REQUEST */
    public const MAX_FILES_PER_BATCH_REQUEST = 20;
    /** middleware/Attachment.MAX_FILENAME_LENGTH */
    public const MAX_FILENAME_LENGTH = 200;

    /**
     * middleware/Attachment.ALLOWED_TYPES — extension => Content-Type. The server
     * re-derives the type from the extension and sniffs the bytes, so the type we
     * put on the multipart part is a courtesy, not a claim it trusts.
     *
     * @var array<string, string>
     */
    public const ATTACHMENT_TYPES = [
        'csv' => 'text/csv',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    // --- analytics -----------------------------------------------------------

    /**
     * client/utils/Analytics.METRIC_FIELDS
     *
     * @var list<string>
     */
    public const METRIC_FIELDS = [
        'sent',
        'delivered',
        'deferred',
        'bounced',
        'opened',
        'clicked',
        'spam',
        'unsubscribed',
        'failed',
    ];

    /**
     * client/utils/Analytics.METRIC_BY_TYPE keys — the `type` on a log event.
     *
     * @var list<string>
     */
    public const EVENT_TYPES = [
        'queued',
        'delivered',
        'deferred',
        'bounced',
        'opened',
        'clicked',
        'spam',
        'unsubscribed',
        'failed',
    ];

    /** @var list<string> */
    public const INTERVALS = ['day', 'week', 'month'];

    /**
     * Query-param ceilings enforced by the controllers. The server clamps rather
     * than rejects, so these are used for documentation and local clamping only.
     *
     * @var array<string, array{min: int, max: int, default: int}>
     */
    public const LIMITS = [
        'realtimeMinutes' => ['min' => 1, 'max' => 120, 'default' => 60],
        'realtimeSeconds' => ['min' => 1, 'max' => 600, 'default' => 120],
        'activityLimit' => ['min' => 1, 'max' => 100, 'default' => 20],
        'logPageSize' => ['min' => 1, 'max' => 200, 'default' => 50],
    ];

    /** The row ceiling on GET /client/logs/export. */
    public const LOG_EXPORT_ROWS = 5000;

    /** Sendpipeline.EMAIL_PATTERN, character for character. */
    public const EMAIL_PATTERN = '/^[^\s@]+@(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i';

    /** Sendpipeline.PLACEHOLDER_PATTERN. */
    public const PLACEHOLDER_PATTERN = '/\{\{\s*([\w.-]+)\s*\}\}/';

    /** The SDK version, sent on the User-Agent. */
    public const VERSION = '1.0.0';

    /**
     * Where the API lives. Callers configure nothing but their API key, so this
     * has to be the real host — override it only to point at a different
     * deployment, with `new ThousandMails(['baseUrl' => ...])` or THOUSANDMAILS_BASE_URL.
     *
     * Read on each call rather than captured at load, so setting the variable
     * after the autoloader has run still takes effect.
     */
    public static function defaultBaseUrl(): string
    {
        $fromEnv = getenv('THOUSANDMAILS_BASE_URL');

        return \is_string($fromEnv) && $fromEnv !== '' ? $fromEnv : self::FALLBACK_BASE_URL;
    }

    /** @return list<string> */
    public static function allowedExtensions(): array
    {
        return array_keys(self::ATTACHMENT_TYPES);
    }
}
