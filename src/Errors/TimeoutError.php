<?php

declare(strict_types=1);

namespace ThousandMails\Errors;

/** The request exceeded the configured timeout and was aborted. */
class TimeoutError extends ConnectionError
{
}
