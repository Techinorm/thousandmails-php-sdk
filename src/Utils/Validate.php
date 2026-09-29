<?php

declare(strict_types=1);

namespace ThousandMails\Utils;

use ThousandMails\Errors\InvalidInputError;

/**
 * Local pre-flight checks that mirror src/backend/client/services/Sendpipeline.js.
 *
 * They run in the same order the server runs them, so a message rejected here
 * would have been rejected there with the same complaint — the SDK just saves the
 * round trip. Anything the server alone can know (does this sender exist, is the
 * domain verified, is the recipient suppressed) is deliberately left to the API.
 *
 * Set `validateInput => false` on the client to skip all of it.
 */
final class Validate
{
    /** Keys the API understands. Anything else is treated as a typo. */
    private const KNOWN_FIELDS = [
        'senderemail', 'from',
        'to', 'cc', 'bcc',
        'subject', 'text', 'html',
        'templateid', 'templateId',
        'templaterequiredfields', 'templateRequiredFields',
        'attachments',
    ];

    /**
     * Validate one message and return it normalised. Attachments are checked
     * separately ({@see Attachments}) because only the multipart endpoints take them.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function message(mixed $input, array $options = []): array
    {
        $validateRecipients = $options['validateRecipients'] ?? true;

        if (!\is_array($input) || $input === []) {
            throw new InvalidInputError('A message object is required');
        }

        // `from`, `templateId` and `templateRequiredFields` are accepted as aliases so
        // the SDK reads naturally to anyone coming from another mail library; the wire
        // format stays exactly what the API documents.
        $sender = $input['senderemail'] ?? $input['from'] ?? null;
        $template = $input['templateid'] ?? $input['templateId'] ?? null;
        $fields = $input['templaterequiredfields'] ?? $input['templateRequiredFields'] ?? null;

        $subject = $input['subject'] ?? null;
        $text = $input['text'] ?? null;
        $html = $input['html'] ?? null;

        if ($sender === null || $sender === '') {
            throw new InvalidInputError('senderemail is required', ['field' => 'senderemail']);
        }
        $normalisedSender = strtolower(trim((string) $sender));
        if (!preg_match(Constants::EMAIL_PATTERN, $normalisedSender)) {
            throw new InvalidInputError('senderemail is not a valid email address', [
                'field' => 'senderemail',
                'value' => $normalisedSender,
            ]);
        }

        $to = self::normaliseRecipients($input['to'] ?? null, 'to');
        $cc = self::normaliseRecipients($input['cc'] ?? null, 'cc');
        $bcc = self::normaliseRecipients($input['bcc'] ?? null, 'bcc');

        if ($to['value'] === null) {
            throw new InvalidInputError('to is required', ['field' => 'to']);
        }

        if ($validateRecipients) {
            self::assertAddresses($to, 'to');
            self::assertAddresses($cc, 'cc');
            self::assertAddresses($bcc, 'bcc');
        }

        $hasTemplate = $template !== null && $template !== '';
        $hasRaw = ($subject !== null && $subject !== '')
            || ($text !== null && $text !== '')
            || ($html !== null && $html !== '');

        if ($hasTemplate && $hasRaw) {
            throw new InvalidInputError('Send either templateid or subject/text/html, not both');
        }

        if (!$hasTemplate) {
            if ($subject === null || $subject === '') {
                throw new InvalidInputError('subject is required', ['field' => 'subject']);
            }
            if (($text === null || $text === '') && ($html === null || $html === '')) {
                throw new InvalidInputError('text or html is required', ['field' => 'text']);
            }

            // The server resolves `{{placeholders}}` in raw bodies too, and 422s when a
            // value is missing. It cannot see inside a stored template from here, so only
            // the raw path is checked locally.
            $required = self::placeholdersIn([$subject, $text, $html]);
            if ($required !== []) {
                $provided = \is_array($fields) ? $fields : [];
                $missing = array_values(array_filter(
                    $required,
                    static fn (string $name): bool => !isset($provided[$name]) || $provided[$name] === '',
                ));
                if ($missing !== []) {
                    throw new InvalidInputError('templaterequiredfields is missing required values', [
                        'requiredFields' => $required,
                        'missingFields' => $missing,
                    ]);
                }
            }
        }

        if ($fields !== null && (!\is_array($fields) || array_is_list($fields))) {
            throw new InvalidInputError('templaterequiredfields must be an object', [
                'field' => 'templaterequiredfields',
            ]);
        }

        $unknown = array_values(array_diff(array_keys($input), self::KNOWN_FIELDS));
        if ($unknown !== []) {
            // The API ignores unknown keys, so a typo like `htlm` would silently send a
            // message with no HTML body. Refusing here is the whole point of pre-flight.
            throw new InvalidInputError(
                'Unknown field(s): ' . implode(', ', $unknown)
                . '. The API ignores unrecognised keys, so this is almost certainly a typo.',
                ['fields' => $unknown],
            );
        }

        return self::compact([
            'senderemail' => $normalisedSender,
            'to' => $to['value'],
            'cc' => $cc['value'],
            'bcc' => $bcc['value'],
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
            'templateid' => $hasTemplate ? $template : null,
            'templaterequiredfields' => $fields,
        ]);
    }

    /**
     * Validate a batch and return the normalised messages.
     *
     * @param array<string, mixed> $options
     *
     * @return list<array<string, mixed>>
     */
    public static function batch(mixed $messages, array $options = []): array
    {
        $multipart = $options['multipart'] ?? false;
        unset($options['multipart']);

        $list = self::listOf($messages);

        if ($list === null || $list === []) {
            throw new InvalidInputError('messages must be a non-empty array');
        }

        $max = $multipart ? Constants::MAX_ATTACHMENT_BATCH : Constants::MAX_BATCH;
        if (\count($list) > $max) {
            throw new InvalidInputError(
                'A ' . ($multipart ? 'multipart ' : '') . "batch may contain at most {$max} messages",
                ['received' => \count($list), 'max' => $max],
            );
        }

        $validated = [];
        foreach ($list as $index => $entry) {
            try {
                $validated[] = self::message($entry, $options);
            } catch (InvalidInputError $error) {
                throw $error->withIndex($index);
            }
        }

        return $validated;
    }

    /**
     * Unwrap `[$a, $b]` or `['messages' => [$a, $b]]` into a plain list.
     *
     * @return list<mixed>|null
     */
    public static function listOf(mixed $messages): ?array
    {
        if (\is_array($messages) && array_is_list($messages)) {
            return $messages;
        }
        if (\is_array($messages) && isset($messages['messages']) && \is_array($messages['messages'])) {
            return array_values($messages['messages']);
        }

        return null;
    }

    /**
     * Normalise a recipient field to the shape the API accepts.
     *
     * `splittable` records whether the caller handed us a bare string. Only then
     * may a comma be read as a separator — once a `['name' => …]` entry has been
     * flattened to "Doe, John <j@x.com>" the commas inside it are data.
     *
     * @return array{value: string|list<string>|null, splittable: bool}
     */
    public static function normaliseRecipients(mixed $value, string $field): array
    {
        if ($value === null || $value === '' || $value === []) {
            return ['value' => null, 'splittable' => false];
        }

        $wasList = \is_array($value) && array_is_list($value);
        $entries = $wasList ? $value : [$value];

        $cleaned = [];
        foreach ($entries as $entry) {
            if (\is_string($entry)) {
                $trimmed = trim($entry);
                if ($trimmed !== '') {
                    $cleaned[] = $trimmed;
                }

                continue;
            }
            // ['name' => …, 'address' => …] — the shape most mail libraries use.
            if (\is_array($entry) && isset($entry['address']) && $entry['address'] !== '') {
                $address = (string) $entry['address'];
                $name = $entry['name'] ?? null;
                $cleaned[] = ($name !== null && $name !== '') ? "{$name} <{$address}>" : $address;

                continue;
            }

            throw new InvalidInputError(
                "{$field} entries must be a string or ['name' => …, 'address' => …]",
                ['field' => $field],
            );
        }

        if ($cleaned === []) {
            return ['value' => null, 'splittable' => false];
        }

        // A bare string stays a string on the wire, exactly as it arrived.
        $isBareString = !$wasList && \is_string($value);

        return [
            'value' => $wasList ? $cleaned : $cleaned[0],
            'splittable' => $isBareString,
        ];
    }

    /** Strip display names: "Ada <ada@x.com>" => "ada@x.com". */
    public static function bareAddress(string $entry): string
    {
        if (preg_match('/<([^>]+)>/', $entry, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($entry);
    }

    /**
     * Placeholder names appearing in any of the given strings, in first-seen order.
     *
     * @param list<mixed> $parts
     *
     * @return list<string>
     */
    public static function placeholdersIn(array $parts): array
    {
        $found = [];
        foreach ($parts as $part) {
            if (!\is_string($part) || $part === '') {
                continue;
            }
            if (preg_match_all(Constants::PLACEHOLDER_PATTERN, $part, $matches) > 0) {
                foreach ($matches[1] as $name) {
                    $found[$name] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * @param array{value: string|list<string>|null, splittable: bool} $recipients
     */
    private static function assertAddresses(array $recipients, string $field): void
    {
        $value = $recipients['value'];
        if ($value === null) {
            return;
        }

        $parts = \is_array($value)
            ? $value
            : ($recipients['splittable'] ? explode(',', $value) : [$value]);

        foreach ($parts as $part) {
            $address = self::bareAddress((string) $part);
            if ($address === '') {
                continue;
            }
            if (!preg_match(Constants::EMAIL_PATTERN, $address)) {
                throw new InvalidInputError(
                    "\"{$address}\" in {$field} is not a valid email address",
                    ['field' => $field, 'value' => $address],
                );
            }
        }
    }

    /**
     * Drop null values so they never reach the wire.
     *
     * @param array<string, mixed> $array
     *
     * @return array<string, mixed>
     */
    public static function compact(array $array): array
    {
        return array_filter($array, static fn ($value): bool => $value !== null);
    }
}
