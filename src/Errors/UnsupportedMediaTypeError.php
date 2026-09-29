<?php

declare(strict_types=1);

namespace ThousandMails\Errors;

/** 415 — an attachment endpoint was called without multipart/form-data. */
class UnsupportedMediaTypeError extends APIError
{
}
