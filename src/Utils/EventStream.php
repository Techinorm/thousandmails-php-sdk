<?php

declare(strict_types=1);

namespace ThousandMails\Utils;

use ThousandMails\Errors\APIError;
use ThousandMails\Errors\ThousandMailsError;

/**
 * Server-Sent Events client for GET /client/realtime/stream.
 *
 * The endpoint emits a `ready` frame on connect, an `email` frame per recorded
 * event, and a `: ping` comment every 25s to keep proxies from closing an idle
 * connection. Anything that isn't one of those is surfaced as a raw frame rather
 * than dropped, so a new server-side event type doesn't need an SDK release.
 *
 * Usage — either style:
 *
 *   $stream = $thousandmails->realtime->stream();
 *   $stream->on('email', fn (array $event) => print($event['type']));
 *   $stream->listen();
 *
 *   foreach ($thousandmails->realtime->stream()->events() as $event) { … }
 *
 * The Node SDK exposes these as an EventEmitter and an async iterable. PHP has no
 * event loop, so `listen()` blocks and dispatches to handlers, while `events()`
 * runs the same loop inside a Fiber and yields one event at a time. Neither queues
 * anything: an event is handed to exactly one consumer and then dropped, so a
 * stream held open for days does not grow.
 */
final class EventStream
{
    private Http $http;

    private bool $reconnect;

    private int $maxReconnects;

    private bool $closed = false;

    private bool $connected = false;

    /** @var array<string, list<callable>> */
    private array $listeners = [];

    /** Set when the loop stopped because of a failure it could not recover from. */
    private ?\Throwable $error = null;

    /**
     * The last `id:` the server labelled a frame with, replayed as Last-Event-ID
     * on reconnect. See consume().
     */
    private ?string $lastEventId = null;

    private bool $ended = false;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(Http $http, array $options = [])
    {
        $this->http = $http;
        $this->reconnect = ($options['reconnect'] ?? true) !== false;
        $this->maxReconnects = isset($options['maxReconnects']) ? (int) $options['maxReconnects'] : PHP_INT_MAX;

        foreach (['onEvent' => 'email', 'onReady' => 'ready', 'onError' => 'error'] as $option => $event) {
            if (isset($options[$option]) && \is_callable($options[$option])) {
                $this->on($event, $options[$option]);
            }
        }
    }

    /**
     * Register a handler. Event names: ready, email, heartbeat, frame, open,
     * close, error, end.
     */
    public function on(string $event, callable $listener): self
    {
        $this->listeners[$event][] = $listener;

        return $this;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** Stop listening and close the underlying connection. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->finish(null);
    }

    /**
     * Run the stream, dispatching to the handlers registered with `on()`. Blocks
     * until the connection ends and reconnection is exhausted or disabled, or
     * until a handler calls `close()`.
     */
    public function listen(): void
    {
        $this->run(function (string $name, mixed $payload): void {
            $this->emit($name, $payload);
        });
    }

    /**
     * The same loop, as a generator of `email` events.
     *
     * Handlers registered with `on()` still fire, so a caller can iterate the
     * email events while watching `error` or `heartbeat` on the side.
     *
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    public function events(): \Generator
    {
        $fiber = new \Fiber(function (): void {
            $this->run(function (string $name, mixed $payload): void {
                $this->emit($name, $payload);
                // Only email frames reach the consumer; everything else is a
                // side channel handled by listeners. Once closed we stop
                // suspending, so the fiber can unwind in a single resume.
                if ($name === 'email' && \is_array($payload) && !$this->closed) {
                    \Fiber::suspend($payload);
                }
            });
        });

        try {
            $event = $fiber->start();

            while (!$fiber->isTerminated()) {
                if ($event !== null) {
                    yield $event;
                    // A `break` in the caller's foreach never resumes the fiber,
                    // which leaves the socket open — so tear it down on the way out.
                    if ($this->closed) {
                        break;
                    }
                }
                $event = $fiber->resume();
            }
        } catch (\FiberError $error) {
            throw new ThousandMailsError(
                'This PHP build cannot suspend inside the HTTP callback, so events() is unavailable. '
                . 'Use $stream->on(\'email\', …) with $stream->listen() instead.',
                $error,
            );
        } finally {
            // A `break` in the caller's foreach leaves the fiber parked inside the
            // HTTP callback with the socket still open. Flagging the close and
            // resuming once lets it fall out of the read loop and hang up, instead
            // of waiting on a connection nobody is reading.
            $this->closed = true;
            if (isset($fiber) && $fiber->isSuspended()) {
                try {
                    $fiber->resume();
                } catch (\Throwable) {
                    // The unwind itself is best-effort; the socket closes either way.
                }
            }
            $this->close();
        }

        if ($this->error !== null) {
            throw $this->error;
        }
    }

    /**
     * The reconnect loop. `$dispatch` receives every frame; the two entry points
     * differ only in what they do with it.
     *
     * @param callable(string, mixed): void $dispatch
     */
    private function run(callable $dispatch): void
    {
        $failures = 0;

        while (!$this->closed) {
            try {
                $this->consume($dispatch);
                $this->connected = false;
                $dispatch('close', null);
            } catch (\Throwable $error) {
                $this->connected = false;

                if ($this->closed) {
                    break;
                }

                ++$failures;
                $dispatch('error', $error);

                $status = $error instanceof APIError ? $error->status : 0;
                // An auth failure will never resolve itself, so retrying it only
                // burns the rate-limit budget.
                $fatal = $status === 401 || $status === 403 || !$this->reconnect;

                if ($fatal || $failures >= $this->maxReconnects) {
                    $this->error = $error;
                    $this->finish($dispatch);

                    return;
                }
            }

            if ($this->closed || !$this->reconnect) {
                break;
            }

            usleep(Http::backoff(min($failures, 4)) * 1000);
        }

        $this->finish($dispatch);
    }

    /**
     * Open the connection and dispatch complete frames. SSE frames are separated
     * by a blank line; a chunk boundary can land anywhere, so partial frames stay
     * in the buffer until their terminator arrives.
     *
     * @param callable(string, mixed): void $dispatch
     */
    private function consume(callable $dispatch): void
    {
        $pending = '';
        $opened = false;

        // Where to resume from, per the SSE spec. The server does not label
        // frames with `id:` today, so this stays unset and a reconnect picks up
        // from the live edge — events that occurred during the gap are missed.
        // Reconcile with $client->logs when that matters. The moment the server
        // starts labelling frames, this resumes without an SDK release.
        $options = $this->lastEventId !== null
            ? ['headers' => ['Last-Event-ID' => $this->lastEventId]]
            : [];

        $this->http->stream('/realtime/stream', function (string $chunk) use (&$pending, &$opened, $dispatch): bool {
            if (!$opened) {
                $opened = true;
                $this->connected = true;
                $dispatch('open', null);
            }

            // Normalise CRLF so a proxy that rewrites line endings doesn't hide
            // the blank-line terminator.
            $pending .= str_replace("\r\n", "\n", $chunk);

            while (($split = strpos($pending, "\n\n")) !== false) {
                $frame = substr($pending, 0, $split);
                $pending = substr($pending, $split + 2);
                $this->dispatchFrame($frame, $dispatch);

                if ($this->closed) {
                    return false;
                }
            }

            return !$this->closed;
        }, $options);
    }

    /**
     * @param callable(string, mixed): void $dispatch
     */
    private function dispatchFrame(string $frame, callable $dispatch): void
    {
        // A comment frame (": ping") is the heartbeat — proof of life, nothing more.
        if (trim($frame) === '' || str_starts_with($frame, ':')) {
            $dispatch('heartbeat', null);

            return;
        }

        $name = 'message';
        $data = [];

        foreach (explode("\n", $frame) as $line) {
            if (str_starts_with($line, 'event:')) {
                $name = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $data[] = ltrim(substr($line, 5), ' ');
            } elseif (str_starts_with($line, 'id:')) {
                // The spec says a field value containing NUL is ignored rather
                // than stored, and that the id survives the frame it arrived on.
                $id = trim(substr($line, 3));
                if ($id !== '' && !str_contains($id, "\0")) {
                    $this->lastEventId = $id;
                }
            }
        }

        $raw = implode("\n", $data);
        $decoded = json_decode($raw, true);
        // Leave it as text — a frame the SDK doesn't model is still delivered.
        $payload = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;

        $dispatch('frame', ['event' => $name, 'data' => $payload]);
        $dispatch($name, $payload);
    }

    /**
     * Called from both close() and the end of run(), so it has to be idempotent —
     * a consumer must not see two 'end' events for one stream.
     *
     * @param (callable(string, mixed): void)|null $dispatch
     */
    private function finish(?callable $dispatch): void
    {
        $this->closed = true;
        if ($this->ended) {
            return;
        }
        $this->ended = true;

        if ($dispatch !== null) {
            $dispatch('end', $this->error);
        } else {
            $this->emit('end', $this->error);
        }
    }

    private function emit(string $event, mixed $payload): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($payload);
        }
    }
}
