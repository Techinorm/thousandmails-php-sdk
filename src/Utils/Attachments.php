<?php

declare(strict_types=1);

namespace ThousandMails\Utils;

use ThousandMails\Errors\InvalidInputError;

/**
 * Turns whatever a caller hands us into the multipart body the attachment
 * endpoints expect, applying the same ceilings the server does
 * (src/backend/client/middleware/Attachment.js) so an oversized or unsupported
 * file fails here rather than after the upload.
 *
 * Accepted forms:
 *   '/path/to/invoice.pdf'                              a path on disk
 *   ['path' => '/tmp/x.pdf', 'filename' => 'inv.pdf']   a path with a chosen name
 *   ['filename' => 'report.csv', 'content' => $bytes]   bytes in memory
 *   ['filename' => 'x.png', 'content' => $b64, 'encoding' => 'base64']
 */
final class Attachments
{
    /**
     * Read and check one attachment.
     *
     * @return array{filename: string, content: string, contentType: string}
     */
    public static function resolveOne(mixed $input, string $label): array
    {
        if (\is_string($input)) {
            $filename = basename($input);
            $content = self::readFile($input, $label);
        } elseif (\is_array($input) && isset($input['path']) && $input['path'] !== '') {
            $path = (string) $input['path'];
            $filename = isset($input['filename']) && $input['filename'] !== ''
                ? (string) $input['filename']
                : basename($path);
            $content = self::readFile($path, $label);
        } elseif (\is_array($input)) {
            $filename = (string) ($input['filename'] ?? '');
            $content = self::toBytes($input['content'] ?? null, $input['encoding'] ?? null);
            if ($content === null) {
                $shown = $filename !== '' ? $filename : '(unnamed)';

                throw new InvalidInputError(
                    "Attachment \"{$shown}\" needs `content` (a string of bytes) or `path`",
                    ['field' => $label],
                );
            }
        } else {
            throw new InvalidInputError(
                "An attachment must be a file path or an array with ['filename' => …, 'content' => …]",
                ['field' => $label],
            );
        }

        self::assertSafeFilename($filename, $label);

        $ext = self::extensionOf($filename);
        $contentType = Constants::ATTACHMENT_TYPES[$ext] ?? null;
        if ($contentType === null) {
            throw new InvalidInputError(
                "\"{$filename}\" has an unsupported file type. Allowed: " . implode(', ', Constants::allowedExtensions()),
                ['field' => $label, 'allowed' => Constants::allowedExtensions(), 'filename' => $filename],
            );
        }

        $size = \strlen($content);
        if ($size === 0) {
            throw new InvalidInputError("\"{$filename}\" is empty", ['field' => $label, 'filename' => $filename]);
        }
        if ($size > Constants::MAX_FILE_BYTES) {
            throw new InvalidInputError(
                "\"{$filename}\" is {$size} bytes; the per-file limit is " . Constants::MAX_FILE_BYTES,
                ['field' => $label, 'filename' => $filename, 'size' => $size, 'max' => Constants::MAX_FILE_BYTES],
            );
        }

        return ['filename' => $filename, 'content' => $content, 'contentType' => $contentType];
    }

    /**
     * Resolve one message's attachments, enforcing the per-message count, the
     * per-request byte ceiling and the duplicate-name rule the server applies.
     *
     * @return list<array{filename: string, content: string, contentType: string}>
     */
    public static function resolveGroup(mixed $attachments, string $label = 'attachments'): array
    {
        $list = \is_array($attachments) && array_is_list($attachments) ? $attachments : [$attachments];

        if ($list === [] || (\count($list) === 1 && ($list[0] === null || $list[0] === '' || $list[0] === []))) {
            throw new InvalidInputError(
                "{$label} is required — the attachment endpoints need at least one file "
                . '(use sendMail for a message without files)',
                ['field' => $label],
            );
        }
        if (\count($list) > Constants::MAX_ATTACHMENTS_PER_MESSAGE) {
            throw new InvalidInputError(
                'A message may carry at most ' . Constants::MAX_ATTACHMENTS_PER_MESSAGE . ' attachments',
                ['field' => $label, 'received' => \count($list)],
            );
        }

        $resolved = [];
        $seen = [];
        $total = 0;

        foreach ($list as $entry) {
            $file = self::resolveOne($entry, $label);

            $key = strtolower($file['filename']);
            if (isset($seen[$key])) {
                throw new InvalidInputError(
                    "Duplicate attachment filename \"{$file['filename']}\"",
                    ['field' => $label, 'filename' => $file['filename']],
                );
            }
            $seen[$key] = true;

            $total += \strlen($file['content']);
            if ($total > Constants::MAX_REQUEST_BYTES) {
                throw new InvalidInputError(
                    'Attachments exceed the ' . Constants::MAX_REQUEST_BYTES . ' byte per-request limit',
                    ['field' => $label, 'size' => $total, 'max' => Constants::MAX_REQUEST_BYTES],
                );
            }

            $resolved[] = $file;
        }

        return $resolved;
    }

    /**
     * Body for POST /client/sendmail/attachment.
     *
     * @param array<string, mixed> $message
     *
     * @return array{form: array{body: string, contentType: string}, files: list<array{filename: string, content: string, contentType: string}>}
     */
    public static function singleForm(array $message, mixed $attachments): array
    {
        $files = self::resolveGroup($attachments);

        $parts = [];
        foreach ($message as $key => $value) {
            $field = self::field((string) $key, $value);
            if ($field !== null) {
                $parts[] = $field;
            }
        }
        foreach ($files as $file) {
            $parts[] = ['name' => 'attachments'] + $file;
        }

        return ['form' => self::encode($parts), 'files' => $files];
    }

    /**
     * Body for POST /client/send/attachment/batch. Files bind to their message by
     * index through the field name `attachments[<index>]`, and every message in the
     * batch must carry at least one.
     *
     * @param list<array<string, mixed>> $messages
     * @param list<mixed>                $attachmentsByIndex
     *
     * @return array{form: array{body: string, contentType: string}, groups: list<list<array{filename: string, content: string, contentType: string}>>}
     */
    public static function batchForm(array $messages, array $attachmentsByIndex): array
    {
        $groups = [];
        $totalFiles = 0;

        foreach ($messages as $index => $_message) {
            $files = self::resolveGroup($attachmentsByIndex[$index] ?? null, "attachments[{$index}]");
            $totalFiles += \count($files);
            $groups[] = $files;
        }

        if ($totalFiles > Constants::MAX_FILES_PER_BATCH_REQUEST) {
            throw new InvalidInputError(
                'A multipart batch may carry at most ' . Constants::MAX_FILES_PER_BATCH_REQUEST . ' files in total',
                ['received' => $totalFiles, 'max' => Constants::MAX_FILES_PER_BATCH_REQUEST],
            );
        }

        $parts = [[
            'name' => 'messages',
            'value' => json_encode(array_values($messages), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]];
        foreach ($groups as $index => $files) {
            foreach ($files as $file) {
                $parts[] = ['name' => "attachments[{$index}]"] + $file;
            }
        }

        return ['form' => self::encode($parts), 'groups' => $groups];
    }

    public static function extensionOf(string $filename): string
    {
        $dot = strrpos($filename, '.');

        return $dot !== false && $dot > 0 ? strtolower(substr($filename, $dot + 1)) : '';
    }

    /**
     * The server refuses control characters and path separators outright, because the
     * name is echoed into a Content-Disposition header.
     */
    public static function assertSafeFilename(string $filename, string $label): void
    {
        if (trim($filename) === '') {
            throw new InvalidInputError('Each attachment must have a filename', ['field' => $label]);
        }
        if (\strlen($filename) > Constants::MAX_FILENAME_LENGTH) {
            throw new InvalidInputError(
                'Attachment filenames may be at most ' . Constants::MAX_FILENAME_LENGTH . ' characters',
                ['field' => $label, 'filename' => $filename],
            );
        }
        for ($i = 0, $length = \strlen($filename); $i < $length; ++$i) {
            $code = \ord($filename[$i]);
            if ($code < 0x20 || $code === 0x7F || $code === 0x2F || $code === 0x5C) {
                throw new InvalidInputError(
                    "\"{$filename}\" is not a valid file name: no path separators or control characters",
                    ['field' => $label, 'filename' => $filename],
                );
            }
        }
    }

    private static function toBytes(mixed $content, mixed $encoding): ?string
    {
        if (!\is_string($content)) {
            return null;
        }
        if ($encoding === 'base64') {
            $decoded = base64_decode($content, true);

            return $decoded === false ? null : $decoded;
        }

        return $content;
    }

    private static function readFile(string $target, string $label): string
    {
        if (!is_file($target) || !is_readable($target)) {
            throw new InvalidInputError(
                "Could not read attachment \"{$target}\": no such readable file",
                ['field' => $label],
            );
        }

        $content = @file_get_contents($target);
        if ($content === false) {
            throw new InvalidInputError("Could not read attachment \"{$target}\"", ['field' => $label]);
        }

        return $content;
    }

    /**
     * Multipart carries every non-file part as a string, so structured values are
     * JSON-encoded exactly the way the server's coerceMessage expects to find them.
     *
     * @return array{name: string, value: string}|null
     */
    private static function field(string $name, mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if (\is_array($value)) {
            return ['name' => $name, 'value' => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        }
        if (\is_bool($value)) {
            return ['name' => $name, 'value' => $value ? 'true' : 'false'];
        }

        return ['name' => $name, 'value' => (string) $value];
    }

    /**
     * Encode parts as multipart/form-data.
     *
     * Built by hand rather than through CURLFile so in-memory content works
     * without a temp file, and so the test suite can read back exactly what would
     * have gone over the wire.
     *
     * @param list<array<string, mixed>> $parts
     *
     * @return array{body: string, contentType: string}
     */
    private static function encode(array $parts): array
    {
        $boundary = '----ThousandMailsFormBoundary' . bin2hex(random_bytes(16));
        $body = '';

        foreach ($parts as $part) {
            $name = (string) $part['name'];
            $body .= "--{$boundary}\r\n";

            if (isset($part['filename'])) {
                $filename = self::escapeHeaderValue((string) $part['filename']);
                $body .= "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n";
                $body .= "Content-Type: {$part['contentType']}\r\n\r\n";
                $body .= $part['content'];
            } else {
                $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
                $body .= (string) $part['value'];
            }

            $body .= "\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        return ['body' => $body, 'contentType' => "multipart/form-data; boundary={$boundary}"];
    }

    /**
     * assertSafeFilename has already refused control characters and separators, so
     * a quote is the only thing left that could break out of the header.
     */
    private static function escapeHeaderValue(string $value): string
    {
        return str_replace('"', '%22', $value);
    }
}
