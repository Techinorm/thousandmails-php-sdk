# thousandmails/thousandmails

PHP SDK for ThousandMails — transactional email, attachments, statistics, delivery
logs and a live event stream, behind one client object.

- One thing to configure: your API key. No host, no setup step, no init call
- Zero Composer dependencies — PHP 8.1+ with `ext-curl` and `ext-json`
- Local validation, so a typo fails in microseconds instead of a round trip
- Automatic idempotency keys on single sends, so a retry can't double-send
- Typed errors, retry with backoff, and a reconnecting event stream

```bash
composer require thousandmails/thousandmails
```

## Quick start

Your API key is the only thing to configure. Generate one on the in-app **API
keys** page, and you're sending:

```php
use ThousandMails\ThousandMails;

$thousandmails = new ThousandMails(['apiKey' => getenv('THOUSANDMAILS_API_KEY')]);

$result = $thousandmails->sendMail([
    'senderemail' => 'noreply@yourdomain.com',
    'to' => 'customer@example.com',
    'subject' => 'Your receipt',
    'html' => '<p>Thanks for your order.</p>',
]);

echo $result['status'], ' ', $result['messageId'];
```

Set `THOUSANDMAILS_API_KEY` in your environment and even that argument goes away:

```php
$thousandmails = new ThousandMails();
```

Or pass the key on its own:

```php
$thousandmails = new ThousandMails('tm_live_...');
```

Everything else — the host, timeouts, retries, idempotency keys, validation — is
configured for you with working defaults. There is no setup step, no
initialisation call, and nothing to install alongside it.

## Methods

Every method is reachable two ways — through its resource and as a flat alias on
the client. `$thousandmails->emails->send()` and `$thousandmails->sendMail()` run the
same code; use whichever reads better.

### Sending — `$thousandmails->emails`

| Method                                          | Flat alias                     | Does                                                                          |
| ----------------------------------------------- | ------------------------------ | ----------------------------------------------------------------------------- |
| `send($message, $options?)`                     | `sendMail()`                   | Sends one email. Routes to the multipart path when the message carries files. |
| `sendBatch($messages, $options?)`               | `sendBatch()`                  | Up to 100 emails in one request, each with its own outcome.                   |
| `sendWithAttachments($message, $options?)`      | `sendMailWithAttachments()`    | One email with 1–5 files.                                                     |
| `sendBatchWithAttachments($messages, $options?)`| `sendBatchWithAttachments()`   | Up to 20 emails, each carrying its own files.                                 |
| `get($id, $options?)`                           | `getMessage()`                 | Looks up a send by its record id or its SMTP message id.                      |

### Statistics — `$thousandmails->stats`

| Method                           | Flat alias             | Does                                                          |
| -------------------------------- | ---------------------- | ------------------------------------------------------------- |
| `summary($params?, $options?)`   | `getStatsSummary()`    | Totals for a window, the preceding window, and derived rates. |
| `timeseries($params?, $options?)`| `getStatsTimeseries()` | The same metrics bucketed by day, week or month.              |
| `bySender($params?, $options?)`  | `getStatsBySender()`   | Totals grouped by sender address.                             |
| `byTag($params?, $options?)`     | `getStatsByTag()`      | Send outcomes grouped by tag.                                 |

### Realtime — `$thousandmails->realtime`

| Method                          | Flat alias               | Does                                                     |
| ------------------------------- | ------------------------ | -------------------------------------------------------- |
| `stats($params?, $options?)`    | `getRealtimeStats()`     | Every metric summed over the last N minutes (1–120).     |
| `perMinute($params?, $options?)`| `getRealtimePerMinute()` | The last N per-minute buckets, oldest first.             |
| `perSecond($params?, $options?)`| `getRealtimePerSecond()` | Per-second buckets over the last N seconds, zero-filled. |
| `activity($params?, $options?)` | `getRealtimeActivity()`  | The most recent events, newest first (1–100).            |
| `stream($options?)`             | `streamEvents()`         | A live event stream. Returns an `EventStream`.           |

### Logs — `$thousandmails->logs`

| Method                                   | Flat alias           | Does                                                      |
| ---------------------------------------- | -------------------- | --------------------------------------------------------- |
| `list($query?, $options?)`               | `getLogs()`          | One page of events, newest first.                         |
| `iterate($query?, $options?)`            | `iterateLogs()`      | Generator over every matching event, paged for you.       |
| `get($eventId, $options?)`               | `getLog()`           | One event by id.                                          |
| `export($query?, $options?)`             | `exportLogs()`       | The filtered log as CSV text.                             |
| `exportToFile($path, $query?, $options?)`| `exportLogsToFile()` | Writes that CSV to disk and returns the row count.        |

Every method takes a final `$options` array accepting `timeout` (overriding the
client's, `0` to disable). Single sends also accept `idempotencyKey`.

## Sending

### Raw and templated

```php
// Raw
$thousandmails->sendMail([
    'senderemail' => 'noreply@yourdomain.com',
    'to' => ['ada@example.com', ['name' => 'Bob', 'address' => 'bob@example.com']],
    'cc' => 'manager@example.com',
    'bcc' => 'archive@example.com',
    'subject' => 'Welcome, {{name}}',
    'html' => '<p>Hello {{name}}</p>',
    'templaterequiredfields' => ['name' => 'Ada'],
]);

// From a saved template
$thousandmails->sendMail([
    'senderemail' => 'noreply@yourdomain.com',
    'to' => 'ada@example.com',
    'templateid' => 'tpl_abc123',
    'templaterequiredfields' => ['name' => 'Ada', 'order' => '4417'],
]);
```

Send _either_ `templateid` _or_ `subject`/`text`/`html` — never both.
`{{placeholders}}` work in raw bodies as well as templates, and every one of them
needs a value.

Recipients accept a string, a comma-separated string, an array, or
`['name' => …, 'address' => …]` entries. Everyone on `to`/`cc` sees each other —
use `sendBatch` for individually addressed mail. `bcc` is stripped from the
message and travels in the envelope only, hidden from every other recipient.

`senderemail` also answers to `from`, `templateid` to `templateId`, and
`templaterequiredfields` to `templateRequiredFields`.

### Attachments

`sendMail` routes to the multipart path automatically when the message has
`attachments`:

```php
$thousandmails->sendMail([
    'senderemail' => 'billing@yourdomain.com',
    'to' => 'customer@example.com',
    'subject' => 'Invoice 4417',
    'text' => 'Attached.',
    'attachments' => [
        './invoices/4417.pdf',                                        // a path
        ['path' => '/tmp/tmp-2f8a.pdf', 'filename' => 'terms.pdf'],   // a path, renamed
        ['filename' => 'lines.csv', 'content' => "sku,qty\nA1,2"],    // text
        ['filename' => 'logo.png', 'content' => $pngBytes],           // bytes
        ['filename' => 'scan.png', 'content' => $b64, 'encoding' => 'base64'],
    ],
]);
```

Limits, checked locally before anything uploads: 1–5 files per message, 5 MB per
file, 20 MB per request, unique filenames, and only
`csv, xls, xlsx, doc, docx, pdf, jpg, jpeg, png`. Files are also inspected on
arrival — a renamed `.exe` is rejected however it is labelled.

### Batches

```php
$batch = $thousandmails->sendBatch([
    ['senderemail' => 'noreply@yourdomain.com', 'to' => 'a@example.com', 'subject' => 'Hi', 'text' => 'One'],
    ['senderemail' => 'noreply@yourdomain.com', 'to' => 'b@example.com', 'subject' => 'Hi', 'text' => 'Two'],
]);

foreach ($batch['results'] as $entry) {
    if ($entry['outcome'] === 'error') {
        error_log("{$entry['index']}: {$entry['error']['message']}");
    }
}
```

A batch **always returns** — each entry succeeds or fails on its own, so check
`outcome` per entry rather than relying on the call not throwing. Up to 100
messages, or 20 for the attachment variant, where every message must carry at
least one file.

## Idempotency

Single sends carry an idempotency key by default, generated per call. If the SDK
retries after a timeout or a server error, the key is recognised and the original
result comes back instead of a second send.

```php
// Your own key, stable across requests — de-duplicates at your level too
$thousandmails->sendMail($message, ['idempotencyKey' => "receipt-{$orderId}"]);

// Opt out
$thousandmails->sendMail($message, ['idempotencyKey' => false]);
```

Keys do not apply to batches, so batch sends are never retried automatically.

## Retries

`maxRetries` (default 2) applies with exponential backoff and full jitter:

| Situation                                 | Retried?                                    |
| ----------------------------------------- | ------------------------------------------- |
| Rate limited                              | yes — the request was refused untouched     |
| Upload capacity reached                   | yes — same reason, honours `Retry-After`    |
| Server error, timeout, dropped connection | only with an idempotency key                |
| Batch sends                               | never                                       |
| Any other rejection                       | never                                       |

A `Retry-After` longer than the SDK is willing to wait (8s) is not slept
through. The window would still be shut when the retry landed, so the call fails
immediately instead and `$error->retryAfter` carries the server's real figure for
you to back off against.

`timeout` applies **per attempt**, not per call: a retried request gets a fresh
window, so a call can take up to `(maxRetries + 1) × timeout` in the worst case.

`$thousandmails->rateLimit()` holds `['limit' => …, 'remaining' => …, 'reset' => …]`
from the last response so you can pace yourself before being throttled. The
budget is **per API key** — 1000 requests per 15 minutes.

## Reading

```php
$summary = $thousandmails->getStatsSummary(['from' => '2026-07-01', 'to' => '2026-07-31']);
echo $summary['totals']['delivered'], $summary['rates']['openRate'];

$thousandmails->getStatsTimeseries(['from' => '2026-07-01', 'interval' => 'week']);
$thousandmails->getStatsBySender();
$thousandmails->getRealtimeStats(['minutes' => 15]);

// One page, or every event
$page = $thousandmails->getLogs(['event' => 'bounced', 'pageSize' => 100]);
foreach ($thousandmails->iterateLogs(['event' => 'bounced']) as $event) {
    echo $event['recipient'], ' ', $event['dsn'] ?? '';
}

$thousandmails->exportLogsToFile('./bounces.csv', ['event' => 'bounced']);
```

Dates are `YYYY-MM-DD` (UTC) or a `DateTimeInterface`. An unparsable date is
silently ignored upstream and would quietly widen your window, so the SDK rejects
it instead.

### Live event stream

```php
$stream = $thousandmails->streamEvents();

$stream->on('email', fn (array $event) => print("{$event['type']} {$event['recipient']}\n"));
$stream->on('error', fn (Throwable $error) => error_log('stream: ' . $error->getMessage()));

$stream->listen();   // blocks until the stream ends or a handler calls close()
```

…or iterate, which reads better when each event drives work:

```php
foreach ($thousandmails->streamEvents()->events() as $event) {
    if ($event['type'] === 'bounced') {
        handleBounce($event);
    }
}
```

`EventStream` dispatches `ready`, `email`, `heartbeat`, `frame`, `open`, `close`,
`error` and `end`. It reconnects with backoff after a dropped connection and
gives up on an authentication failure, which will not resolve itself. Pass
`['reconnect' => false]` to disable. Always `close()` (or `break` out of the
loop) — the connection is held open by design and blocks the script otherwise.

`events()` runs the read loop inside a `Fiber`. On the rare build that cannot
suspend inside an extension callback it raises a `ThousandMailsError` pointing you
at `listen()`, which works everywhere.

> **A reconnect does not replay what it missed.** The SDK sends `Last-Event-ID`
> when the server labels frames with an `id:`, but the stream endpoint does not
> label them today, so a reconnect resumes from the live edge and events that
> occurred during the gap are not delivered. Where you cannot afford to miss
> one, treat the stream as a low-latency notification and reconcile against
> `$client->logs` — the log is the record of what happened, the stream is not.

## Errors

```php
use ThousandMails\Errors\{InvalidInputError, ValidationError, PermissionError, RateLimitError};

try {
    $thousandmails->sendMail($message);
} catch (InvalidInputError $error) {
    // Rejected locally — nothing was sent
} catch (ValidationError $error) {
    print_r($error->missingFields);
} catch (PermissionError $error) {
    print_r($error->suppressed);
} catch (RateLimitError $error) {
    echo 'retry after ', $error->retryAfter, ' seconds';
}
```

Every error extends `ThousandMails\Errors\ThousandMailsError`, which extends
`RuntimeException`. Anything that reached the service and came back a failure also
carries `status`, `body`, `headers`, `method` and `url`.

| Class                              | `status` | Meaning                                        |
| ---------------------------------- | -------- | ---------------------------------------------- |
| `InvalidInputError`                | —        | Rejected locally, before anything was sent     |
| `BadRequestError`                  | 400      | Unknown template, sender, message or event id  |
| `AuthenticationError`              | 401      | Missing, malformed or unknown key              |
| `APIError`                         | 402      | Your plan does not cover it — see `body`       |
| `PermissionError`                  | 403      | Inactive key, IP-pinned sender, all suppressed |
| `PayloadTooLargeError`             | 413      | An attachment or field went over the limit     |
| `UnsupportedMediaTypeError`        | 415      | An upload was not encoded as multipart         |
| `ValidationError`                  | 422      | Understood and rejected                        |
| `RateLimitError`                   | 429      | Throttled — see `retryAfter`                   |
| `ServiceUnavailableError`          | 503      | Upload capacity reached — safe to retry        |
| `ServerError`                      | 5xx      | Service or transport failure                   |
| `TimeoutError` / `ConnectionError` | —        | The request never completed                    |
| `ConfigError`                      | —        | Bad or missing client configuration            |
| `AbortError`                       | —        | You stopped the work yourself                  |

### Plan limits

Your key inherits its account's plan, so the same subscription that governs the
dashboard governs this SDK. Two refusals come from there. Both are `402`, both
arrive as `APIError` with the response body intact, and neither is retried —
the answer will not change until the plan does or the billing period rolls over.

- **`Not included in your plan`** — the call needs an entitlement the plan does
  not carry. The stats, realtime and log methods all need `apiAccess` at
  `full`; a `limited` plan may send and read back its own send records, and
  gets this on everything else. The body names the `feature`, what you hold
  (`current`) and what is `required`.
- **`Monthly send limit reached`** — the period's send allowance is spent. The
  body carries `limit`, `used`, `requested`, `remaining` and `resetsAt`. A batch
  is weighed whole, so an over-cap batch sends nothing rather than part of
  itself.

A `503` carrying `Entitlement check unavailable` is a different thing: the
service could not read your plan, not a statement about it. That one is
transient and the retry policy handles it for you.

Full detail in the
[client API reference](../../../backend/client/docs/api-reference.md#plan-entitlements).

## Swapping the HTTP layer

The client talks to a `ThousandMails\Utils\Transport`, defaulting to
`CurlTransport`. Implement the interface to route requests through your own
stack — a PSR-18 client, a logging decorator, or a stub in tests:

```php
$thousandmails = new ThousandMails([
    'apiKey' => '…',
    'transport' => new MyTransport(),
]);
```

## License

Proprietary. Copyright © 2026 Techinorm Solutions Pvt Ltd. All rights reserved.

You may install and use this SDK, **unmodified**, inside your own applications
to access the ThousandMails service, and redistribute it unmodified as a dependency
of those applications.

You may not modify or adapt the source, create derivative works from it, reverse
engineer it, strip its notices, or republish it as a separate package. Automated
build steps that don't change behaviour are fine.

The software is provided "as is", without warranty of any kind. See
[LICENSE](LICENSE) for the full terms.
