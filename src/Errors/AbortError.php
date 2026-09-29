<?php

declare(strict_types=1);

namespace ThousandMails\Errors;

/**
 * The caller stopped the work itself — an EventStream handler called `close()`,
 * or a stream consumer broke out of the loop.
 */
class AbortError extends ThousandMailsError
{
}
