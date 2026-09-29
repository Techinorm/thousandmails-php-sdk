<?php

declare(strict_types=1);

namespace ThousandMails\Errors;

/**
 * A request rejected locally, before any bytes went out — a missing sender, an
 * oversized attachment, a batch over the limit. Mirrors the server's own checks
 * so the failure arrives in microseconds instead of after a round trip.
 */
class InvalidInputError extends ThousandMailsError
{
    /** The offending field, when one field is to blame. */
    public ?string $field = null;

    /** @var list<string>|null Placeholders the body referenced. */
    public ?array $requiredFields = null;

    /** @var list<string>|null Placeholders left without a value. */
    public ?array $missingFields = null;

    /** Position in the batch, set when a batch entry is at fault. */
    public ?int $index = null;

    /**
     * Everything the failure carried, including the keys promoted above.
     *
     * @var array<string, mixed>
     */
    public array $details;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message, array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $previous);

        $this->details = $details;

        $field = $details['field'] ?? null;
        if (\is_string($field)) {
            $this->field = $field;
        }
        if (isset($details['requiredFields']) && \is_array($details['requiredFields'])) {
            $this->requiredFields = array_values($details['requiredFields']);
        }
        if (isset($details['missingFields']) && \is_array($details['missingFields'])) {
            $this->missingFields = array_values($details['missingFields']);
        }
        if (isset($details['index']) && \is_int($details['index'])) {
            $this->index = $details['index'];
        }
    }

    /** Any other detail the failure carried (`value`, `filename`, `max`, …). */
    public function detail(string $key): mixed
    {
        return $this->details[$key] ?? null;
    }

    /**
     * Re-label the message and record the batch position. Used by Validate::batch
     * so a rejected entry says which one it was.
     */
    public function withIndex(int $index): self
    {
        $clone = new self("messages[{$index}]: " . $this->getMessage(), $this->details + ['index' => $index], $this->getPrevious());
        $clone->index = $index;

        return $clone;
    }
}
