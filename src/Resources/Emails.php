<?php

declare(strict_types=1);

namespace ThousandMails\Resources;

use ThousandMails\Errors\InvalidInputError;
use ThousandMails\ThousandMails;
use ThousandMails\Utils\Attachments;
use ThousandMails\Utils\Http;
use ThousandMails\Utils\Validate;

/**
 * Email sending — the four send endpoints plus the message lookup.
 *
 *   POST /client/sendmail
 *   POST /client/send/batch
 *   POST /client/sendmail/attachment
 *   POST /client/send/attachment/batch
 *   GET  /client/messages/:id
 */
final class Emails
{
    private static bool $warnedAboutBatchIdempotency = false;

    public function __construct(private ThousandMails $client)
    {
    }

    private function http(): Http
    {
        return $this->client->http;
    }

    /**
     * Send one email — raw (`subject` + `text`/`html`) or from a saved template
     * (`templateid` + `templaterequiredfields`), never both.
     *
     * When the message carries `attachments` this routes to the multipart endpoint
     * automatically, so callers have one method to remember.
     *
     * @param array<string, mixed> $message
     * @param array{idempotencyKey?: string|false, timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function send(array $message, array $options = []): array
    {
        if (\array_key_exists('attachments', $message)) {
            return $this->sendWithAttachments($message, $options);
        }

        $body = $this->client->validateInput
            ? Validate::message($message, $this->client->validateOptions)
            : $message;

        return $this->http()->requestArray([
            'method' => 'POST',
            'path' => '/sendmail',
            'json' => $body,
            'idempotencyKey' => $this->idempotencyKey($options),
            'timeout' => $options['timeout'] ?? null,
        ]);
    }

    /**
     * Send up to 100 emails in one request. Each entry is validated and sent
     * independently — a failure on one never rejects the batch, so always read the
     * per-entry `outcome` in the response.
     *
     * The API ignores `Idempotency-Key` on batches, so a batch is never retried
     * automatically after a 5xx.
     *
     * @param list<array<string, mixed>>|array{messages: list<array<string, mixed>>} $messages
     * @param array{timeout?: int}                                                   $options
     *
     * @return array<string, mixed>
     */
    public function sendBatch(array $messages, array $options = []): array
    {
        if (isset($options['idempotencyKey']) && $options['idempotencyKey'] !== false && !self::$warnedAboutBatchIdempotency) {
            self::$warnedAboutBatchIdempotency = true;
            trigger_error(
                'ThousandMails: the API ignores Idempotency-Key on batch sends; the header will not be sent.',
                E_USER_WARNING,
            );
        }

        if ($this->client->validateInput) {
            $list = Validate::batch($messages, $this->client->validateOptions);
        } else {
            $list = Validate::listOf($messages) ?? [];
        }

        return $this->http()->requestArray([
            'method' => 'POST',
            'path' => '/send/batch',
            'json' => ['messages' => $list],
            'timeout' => $options['timeout'] ?? null,
        ]);
    }

    /**
     * Send one email with 1–5 attachments (multipart/form-data). Files may be paths
     * on disk or bytes in memory — see Utils\Attachments for the accepted forms.
     * At least one file is required; without one, use `send`.
     *
     * @param array<string, mixed>                               $message
     * @param array{idempotencyKey?: string|false, timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function sendWithAttachments(array $message, array $options = []): array
    {
        if (!\array_key_exists('attachments', $message)) {
            throw new InvalidInputError(
                'attachments is required — use send() for a message without files',
                ['field' => 'attachments'],
            );
        }

        $attachments = $message['attachments'];
        unset($message['attachments']);

        $body = $this->client->validateInput
            ? Validate::message($message, $this->client->validateOptions)
            : $message;

        $form = Attachments::singleForm($body, $attachments)['form'];

        return $this->http()->requestArray([
            'method' => 'POST',
            'path' => '/sendmail/attachment',
            'form' => $form,
            'idempotencyKey' => $this->idempotencyKey($options),
            'timeout' => $options['timeout'] ?? null,
        ]);
    }

    /**
     * Send up to 20 emails, each with its own files, in one multipart request.
     * Every message must carry at least one attachment — the API rejects the entry
     * otherwise. Files bind to their message by position.
     *
     * @param list<array<string, mixed>>|array{messages: list<array<string, mixed>>} $messages
     * @param array{timeout?: int}                                                   $options
     *
     * @return array<string, mixed>
     */
    public function sendBatchWithAttachments(array $messages, array $options = []): array
    {
        $list = Validate::listOf($messages);
        if ($list === null || $list === []) {
            throw new InvalidInputError('messages must be a non-empty array');
        }

        $bodies = [];
        $files = [];

        foreach ($list as $entry) {
            $entry = \is_array($entry) ? $entry : [];
            $files[] = $entry['attachments'] ?? null;
            unset($entry['attachments']);
            $bodies[] = $entry;
        }

        $validated = $this->client->validateInput
            ? Validate::batch($bodies, $this->client->validateOptions + ['multipart' => true])
            : $bodies;

        $form = Attachments::batchForm($validated, $files)['form'];

        return $this->http()->requestArray([
            'method' => 'POST',
            'path' => '/send/attachment/batch',
            'form' => $form,
            'timeout' => $options['timeout'] ?? null,
        ]);
    }

    /**
     * Look up a send by the id returned from a send call, or by its SMTP
     * message id (`<uuid@domain>` — encoded for you).
     *
     * @param array{timeout?: int} $options
     *
     * @return array<string, mixed>
     */
    public function get(string $id, array $options = []): array
    {
        if (trim($id) === '') {
            throw new InvalidInputError('A message id is required');
        }

        return $this->http()->requestArray([
            'method' => 'GET',
            'path' => '/messages/' . rawurlencode($id),
            'timeout' => $options['timeout'] ?? null,
        ]);
    }

    /**
     * A single send is only replayable when it carries a key, so one is minted per
     * call unless the caller supplies their own or opts out with `false`. The key
     * scopes one logical send: a transport retry inside this call re-uses it and is
     * de-duplicated server-side, while a later call gets a fresh one.
     *
     * @param array<string, mixed> $options
     */
    private function idempotencyKey(array $options): ?string
    {
        $supplied = $options['idempotencyKey'] ?? null;

        if ($supplied === false) {
            return null;
        }
        if (\is_string($supplied) && trim($supplied) !== '') {
            return trim($supplied);
        }
        if (!$this->client->autoIdempotency || $this->http()->maxRetries === 0) {
            return null;
        }

        return 'sdk-' . self::uuidV4();
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
