<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Client;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * Transport for the Storyblok Content Delivery API.
 *
 * A sibling of StoryblokManagementClient rather than a subclass: the CDA carries
 * its token in the query string instead of an Authorization header, lives on a
 * different host, and takes a different token entirely, so there is no shared
 * mechanism to inherit.
 *
 * Because the token is in the URI, every message this class produces passes
 * through redact() first, and no exception whose own message could hold the
 * token is chained as a cause - `(string) $e` renders the whole chain, so a
 * clean getMessage() alone would not be enough. StoryblokManagementClient
 * interpolates the URI into its messages freely, which is harmless when the
 * credential is in a header; copying that here would write the delivery token
 * into logs and crash reports.
 */
final class StoryblokContentClient implements ContentClient
{
    public const DEFAULT_BASE_URI = 'https://api.storyblok.com/v2/';

    private const REDACTION = '[redacted]';

    private readonly ClientInterface $httpClient;

    /**
     * @param string $token A Content Delivery API token, preview or public.
     *                      Not the Management API token.
     * @param string $baseUri Region endpoint. The default is the EU one; the US,
     *                        AP, CA and CN spaces each have their own host.
     */
    public function __construct(
        private readonly string $token,
        ?ClientInterface $httpClient = null,
        private readonly string $baseUri = self::DEFAULT_BASE_URI,
    ) {
        $this->httpClient = $httpClient ?? new Client();
    }

    /**
     * @param array<string, string> $query
     *
     * @throws ResourceNotFoundException
     * @throws StoryblokApiException
     */
    public function get(string $path, array $query): ContentResponse
    {
        $url = $this->url($path, $query);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            // Deliberately NOT chained. Exception::__toString() renders the
            // whole cause chain, and that is what Monolog, Sentry and PHP's own
            // uncaught-exception handler emit - so a ConnectException, whose
            // message embeds the full effective URI, would leak the token there
            // however clean getMessage() was. The redacted message below already
            // carries both the URI and the upstream text, and a synthetic cause
            // would only add a stack trace pointing at this line. Do not put
            // `$e` back.
            throw new StoryblokApiException(
                sprintf('Request to %s failed: %s', $this->redact($url), $this->redact($e->getMessage()))
            );
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status === 404) {
            throw new ResourceNotFoundException(
                'The Storyblok Content Delivery API has nothing at ' . $this->redact($url)
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new StoryblokApiException(sprintf(
                'Storyblok Content Delivery API returned HTTP %d for %s: %s',
                $status,
                $this->redact($url),
                $this->redact(trim($body))
            ));
        }

        return new ContentResponse(
            $this->decode($body, $url),
            $this->intHeader($response, 'Total'),
            $this->intHeader($response, 'Per-Page'),
        );
    }

    /**
     * The query is built here rather than handed to Guzzle's `query` option so
     * that the URI in an error message is byte-for-byte the one that went out,
     * and so the sort order is this class's guarantee rather than Guzzle's.
     *
     * @param array<string, string> $query
     */
    private function url(string $path, array $query): string
    {
        $query['token'] = $this->token;

        // Sorted, so a caching decorator keyed on the URI gets the same key for
        // the same request whatever order the caller built the parameters in.
        ksort($query);

        return rtrim($this->baseUri, '/') . '/' . ltrim($path, '/')
            . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws StoryblokApiException
     */
    private function decode(string $body, string $url): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // Chained, where the transport failure above is not: a JsonException
            // carries json_last_error_msg()'s fixed parser diagnostic ("Syntax
            // error") and never echoes the input back, so it cannot hold the
            // token and is worth keeping as a cause.
            throw new StoryblokApiException(
                sprintf(
                    'Could not decode the JSON response from %s: %s',
                    $this->redact($url),
                    $this->redact($e->getMessage())
                ),
                0,
                $e
            );
        }

        if (!is_array($decoded)) {
            throw new StoryblokApiException('Could not decode the JSON response from ' . $this->redact($url));
        }

        // json_decode(..., true) collapses both "{}" and "[]" to the same PHP
        // []; array_is_list([]) is true, so an empty top level is genuinely
        // ambiguous between an empty object and an empty array and must stay
        // allowed. Anything non-empty and list-shaped is unambiguously a JSON
        // array, which does not satisfy array<string, mixed>.
        if ($decoded !== [] && array_is_list($decoded)) {
            throw new StoryblokApiException(
                'Expected a JSON object at the top level of the response from ' . $this->redact($url)
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Absent for every endpoint but the listing, and a non-numeric value is
     * treated as absent rather than cast to 0.
     */
    private function intHeader(ResponseInterface $response, string $name): ?int
    {
        $value = $response->getHeaderLine($name);

        return $value !== '' && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * The token appears in the URI, and Storyblok may echo the request back in
     * an error body, so both are scrubbed. The encoded form is replaced too,
     * because the URI carries the token percent-encoded.
     */
    private function redact(string $text): string
    {
        return str_replace(
            [$this->token, rawurlencode($this->token)],
            self::REDACTION,
            $text
        );
    }
}
