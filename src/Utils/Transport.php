<?php

declare(strict_types=1);

namespace ThousandMails\Utils;

/**
 * The one seam between the SDK and the network.
 *
 * The Node SDK takes an injectable `fetch`; PHP has no such global, so the same
 * job is done by this interface. Http talks only to a Transport, which is what
 * lets the test suite run offline against a stub while production goes through
 * {@see CurlTransport}.
 *
 * A request is an array:
 *   method    string  "GET", "POST", …
 *   url       string  absolute
 *   headers   array<string,string>
 *   body      string|null   already encoded (JSON, or a multipart body)
 *   timeoutMs int     0 disables
 *
 * A response is an array:
 *   status    int
 *   headers   array<string,string>  names lower-cased
 *   body      string
 *
 * An implementation MUST throw {@see \ThousandMails\Errors\TimeoutError} when the
 * deadline passes and {@see \ThousandMails\Errors\ConnectionError} when the request
 * never produced a response. Http's retry policy keys off those two types.
 */
interface Transport
{
    /**
     * @param array{method: string, url: string, headers: array<string, string>, body: string|null, timeoutMs: int} $request
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function send(array $request): array;

    /**
     * A streaming GET, for the SSE endpoint.
     *
     * Body bytes are handed to $onChunk as they arrive; returning false from it
     * stops the transfer. On a non-2xx status the body is buffered and returned
     * instead, so the caller can build a typed error from it.
     *
     * @param array{method: string, url: string, headers: array<string, string>, body: string|null, timeoutMs: int} $request
     * @param callable(string): bool                                                                                $onChunk
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function stream(array $request, callable $onChunk): array;
}
