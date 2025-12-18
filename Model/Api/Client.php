<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Math\Random;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use WeltPixel\ShopifyCheckout\Exception\ApiException;
use WeltPixel\ShopifyCheckout\Model\Config;

/**
 * Thin Shopify Admin REST client with retry, logging, and JSON decoding helpers.
 */
class Client
{
    private const DEFAULT_API_VERSION = '2024-10';
    private const DEFAULT_TIMEOUT = 15;
    private const DEFAULT_MAX_RETRIES = 3;
    private const DEFAULT_RETRY_DELAY_MS = 200;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var CurlFactory
     */
    private CurlFactory $curlFactory;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var Random
     */
    private Random $random;

    /**
     * @var string
     */
    private string $apiVersion;

    /**
     * @var int
     */
    private int $timeout;

    /**
     * @var int
     */
    private int $maxRetries;

    /**
     * @var int
     */
    private int $retryDelayMs;

    /**
     * @param Config $config
     * @param CurlFactory $curlFactory
     * @param LoggerInterface $logger
     * @param Random $random
     * @param string $apiVersion
     * @param int $timeout
     * @param int $maxRetries
     * @param int $retryDelayMs
     */
    public function __construct(
        Config $config,
        CurlFactory $curlFactory,
        LoggerInterface $logger,
        Random $random,
        string $apiVersion = self::DEFAULT_API_VERSION,
        int $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        int $retryDelayMs = self::DEFAULT_RETRY_DELAY_MS
    ) {
        $this->config = $config;
        $this->curlFactory = $curlFactory;
        $this->logger = $logger;
        $this->random = $random;
        $this->apiVersion = $apiVersion;
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
        $this->retryDelayMs = $retryDelayMs;
    }

    /**
     * Performs a Shopify Admin REST API call.
     *
     * @param string $method HTTP verb (GET, POST, DELETE supported currently)
     * @param string $endpoint Endpoint relative to admin API root.
     * @param array<string,mixed> $payload Query parameters for GET or JSON body for POST.
     * @param array<string,string> $customHeaders Additional headers.
     * @param string|null $correlationId Optional correlation identifier for logs.
     * @param string|null $environment Environment override (sandbox/production)
     * @param string|null $scopeCode Specific scope code (store or website) when credentials vary per scope.
     * @param string $scopeType Scope type, defaults to store level.
     * @return array{status:int, body:array<mixed>|array<int,mixed>, raw_body:string, headers:array<int,string>}
     * @throws ApiException
     */
    public function callAdmin(
        string $method,
        string $endpoint,
        array $payload = [],
        array $customHeaders = [],
        ?string $correlationId = null,
        ?string $environment = null,
        ?string $scopeCode = null,
        string $scopeType = ScopeInterface::SCOPE_STORE
    ): array {
        $method = strtoupper($method);
        $storeDomain = $this->config->getStoreDomain($scopeCode, $scopeType, $environment);
        $accessToken = $this->config->getAdminAccessToken($scopeCode, $scopeType, $environment);
        $correlationId = $correlationId ?: $this->generateCorrelationId();

        if (!$storeDomain) {
            throw new ApiException(__('Shopify store domain is not configured.'));
        }

        if (!$accessToken) {
            throw new ApiException(__('Shopify admin access token is not configured.'));
        }

        $url = $this->buildAdminUrl($storeDomain, $endpoint);
        $this->logRequest('admin', $method, $url, $correlationId);

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            /** @var Curl $curl */
            $curl = $this->curlFactory->create();
            $curl->setHeaders($this->buildHeaders($accessToken, $customHeaders));
            $curl->setTimeout($this->timeout);

            try {
                $response = $this->performRequest($curl, $method, $url, $payload);
            } catch (\Throwable $exception) {
                if ($attempt === $this->maxRetries) {
                    $this->logFailure($method, $url, $correlationId, $exception->getMessage());
                    throw new ApiException(__('Unable to complete Shopify API request.'), $exception);
                }

                $this->logger->warning(
                    'Shopify request exception: {message}',
                    [
                        'message' => $exception->getMessage(),
                        'attempt' => $attempt,
                        'method' => $method,
                        'endpoint' => $endpoint,
                        'correlation_id' => $correlationId,
                    ]
                );
                $this->sleepBeforeRetry($attempt);
                continue;
            }

            if ($this->shouldRetry($response['status'])) {
                if ($attempt === $this->maxRetries) {
                    $this->logFailure($method, $url, $correlationId, $response['raw_body'], $response['status']);
                    throw $this->buildApiException($method, $endpoint, $response);
                }

                $this->logger->warning(
                    'Shopify request returned retryable status {status}. Retrying...',
                    [
                        'status' => $response['status'],
                        'attempt' => $attempt,
                        'method' => $method,
                        'endpoint' => $endpoint,
                        'correlation_id' => $correlationId,
                    ]
                );
                $this->sleepBeforeRetry($attempt, $response['headers']);
                continue;
            }

            if ($response['status'] >= 400) {
                $this->logFailure($method, $url, $correlationId, $response['raw_body'], $response['status']);
                throw $this->buildApiException($method, $endpoint, $response);
            }

            $this->logSuccess($method, $url, $correlationId, $response['status']);
            return $response;
        }

        throw new ApiException(__('Shopify API request failed after %1 attempts.', $this->maxRetries));
    }


    /**
     * @param Curl $curl
     * @param string $method
     * @param string $url
     * @param array<string,mixed> $payload
     * @return array{status:int, body:array<mixed>|array<int,mixed>, raw_body:string, headers:array<int,string>}
     * @throws LocalizedException
     */
    /**
     * Execute the HTTP request and return decoded response data.
     *
     * @param Curl $curl
     * @param string $method
     * @param string $url
     * @param array<string,mixed> $payload
     * @return array{status:int, body:array<mixed>|array<int,mixed>, raw_body:string, headers:array<int,string>}
     * @throws LocalizedException
     */
    private function performRequest(Curl $curl, string $method, string $url, array $payload): array
    {
        switch ($method) {
            case 'GET':
                if (!empty($payload)) {
                    $url = $this->appendQuery($url, $payload);
                }
                $curl->get($url);
                break;
            case 'POST':
                $body = !empty($payload) ? $this->encodePayload($payload) : '{}';
                $curl->post($url, $body);
                break;
            case 'PUT':
                $body = !empty($payload) ? $this->encodePayload($payload) : '{}';
                $curl->addHeader('X-HTTP-Method-Override', 'PUT');
                $curl->post($url, $body);
                break;
            case 'DELETE':
                if (!empty($payload)) {
                    $url = $this->appendQuery($url, $payload);
                }
                $curl->setOption(CURLOPT_CUSTOMREQUEST, 'DELETE');
                $curl->setOption(CURLOPT_URL, $url);
                $curl->setOption(CURLOPT_RETURNTRANSFER, 1);
                $curl->get($url);
                break;
            default:
                throw new LocalizedException(__('Unsupported HTTP method: %1', $method));
        }

        $status = (int) $curl->getStatus();
        $rawBody = (string) $curl->getBody();

        if ($rawBody === '' && $status >= 200 && $status < 300) {
            $decodedBody = [];
        } else {
            $decodedBody = $this->decodeResponse($rawBody);
        }

        return [
            'status' => $status,
            'body' => $decodedBody,
            'raw_body' => $rawBody,
            'headers' => $curl->getHeaders(),
        ];
    }

    /**
     * @param string $accessToken
     * @param array<string,string> $customHeaders
     * @return array<string,string>
     */
    /**
     * Build request headers including authentication and content type.
     *
     * @param string $accessToken
     * @param array<string,string> $customHeaders
     * @return array<string,string>
     */
    private function buildHeaders(string $accessToken, array $customHeaders = []): array
    {
        return array_merge(
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Shopify-Access-Token' => $accessToken,
                'User-Agent' => $this->buildUserAgent(),
            ],
            $customHeaders
        );
    }

    /**
     * @param string $accessToken
     * @param array<string,string> $customHeaders
     * @return array<string,string>
     */

    /**
     * Compose the full Shopify Admin REST URL for an endpoint.
     */
    private function buildAdminUrl(string $storeDomain, string $endpoint): string
    {
        $trimmedEndpoint = ltrim($endpoint, '/');

        return sprintf('https://%s/admin/api/%s/%s', $storeDomain, $this->apiVersion, $trimmedEndpoint);
    }

    /**
     * @param int $attempt
     * @param array<int,string> $headers
     * @return void
     */
    /**
     * Sleep with exponential backoff, inspecting headers for retry-after hints.
     *
     * @param int $attempt
     * @param array<int,string> $headers
     * @return void
     */
    private function sleepBeforeRetry(int $attempt, array $headers = []): void
    {
        $delayMs = $this->retryDelayMs * $attempt;

        foreach ($headers as $header) {
            if (stripos($header, 'Retry-After:') === 0) {
                $parts = explode(':', $header, 2);
                if (isset($parts[1])) {
                    $retryAfterSeconds = (int) trim($parts[1]);
                    if ($retryAfterSeconds > 0) {
                        $delayMs = max($delayMs, $retryAfterSeconds * 1000);
                    }
                }
            }
        }

        usleep($delayMs * 1000);
    }

    /**
     * @param array<string,mixed> $payload
     */
    /**
     * JSON encode payload for POST/PUT operations.
     *
     * @param array<string,mixed> $payload
     * @return string
     */
    private function encodePayload(array $payload): string
    {
        $json = json_encode($payload);

        if ($json === false) {
            throw new LocalizedException(__('Unable to encode Shopify request payload to JSON.'));
        }

        return $json;
    }

    /**
     * @return array<mixed>|array<int,mixed>
     */
    /**
     * Decode JSON response bodies to associative arrays.
     *
     * @param string $rawBody
     * @return array<mixed>
     */
    private function decodeResponse(string $rawBody): array
    {
        if ($rawBody === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new LocalizedException(__('Unable to decode Shopify API response: %1', json_last_error_msg()));
        }

        return $decoded ?? [];
    }

    /**
     * @param string $method
     * @param string $endpoint
     * @param array{status:int, body:array<mixed>|array<int,mixed>, raw_body:string} $response
     */
    /**
     * Construct an ApiException with diagnostic details.
     *
     * @param string $method
     * @param string $endpoint
     * @param array{status:int, body:array<mixed>|array<int,mixed>, raw_body:string, headers:array<int,string>} $response
     * @return ApiException
     */
    private function buildApiException(string $method, string $endpoint, array $response): ApiException
    {
        $message = __(
            'Shopify API request failed (%1 %2). HTTP %3: %4',
            $method,
            $endpoint,
            $response['status'],
            $this->summarizeErrorBody($response['body'])
        );

        return new ApiException($message);
    }

    /**
     * Generate a consistent User-Agent header for Shopify calls.
     */
    private function buildUserAgent(): string
    {
        return 'WeltPixel_ShopifyCheckout/1.0.0 Magento';
    }

    /**
     * @param array<mixed>|array<int,mixed> $body
     */
    /**
     * Produce a concise error message from Shopify response body.
     *
     * @param array<mixed> $body
     * @return string
     */
    private function summarizeErrorBody(array $body): string
    {
        if (isset($body['errors'])) {
            if (is_array($body['errors'])) {
                return substr(json_encode($body['errors'], JSON_UNESCAPED_SLASHES), 0, 500) ?: 'errors present';
            }

            return (string) $body['errors'];
        }

        if (!empty($body)) {
            return substr(json_encode($body, JSON_UNESCAPED_SLASHES), 0, 500) ?: 'unknown error';
        }

        return 'unknown error';
    }

    /**
     * @param array<string,mixed> $payload
     */
    /**
     * Append query parameters to a URL safely.
     *
     * @param string $url
     * @param array<string,mixed> $payload
     * @return string
     */
    private function appendQuery(string $url, array $payload): string
    {
        $query = http_build_query($payload);

        if ($query === '') {
            return $url;
        }

        return $url . (strpos($url, '?') !== false ? '&' : '?') . $query;
    }

    /**
     * Determine whether a response status warrants a retry.
     */
    private function shouldRetry(int $status): bool
    {
        return $status === 429 || ($status >= 500 && $status < 600);
    }

    /**
     * Generate a random correlation identifier for logging.
     */
    private function generateCorrelationId(): string
    {
        return $this->random->getUniqueHash();
    }

    /**
     * Log outbound Shopify request metadata.
     */
    private function logRequest(string $apiType, string $method, string $url, string $correlationId): void
    {
        $this->logger->debug(
            '[Shopify:{api}] {method} {url}',
            [
                'api' => $apiType,
                'method' => $method,
                'url' => $this->sanitizeUrlForLog($url),
                'correlation_id' => $correlationId,
            ]
        );
    }

    /**
     * Log successful Shopify request completion.
     */
    private function logSuccess(string $method, string $url, string $correlationId, int $status): void
    {
        $this->logger->debug(
            '[Shopify] {method} {url} completed with {status}',
            [
                'method' => $method,
                'url' => $this->sanitizeUrlForLog($url),
                'status' => $status,
                'correlation_id' => $correlationId,
            ]
        );
    }

    /**
     * Log Shopify request failures with limited message payload.
     */
    private function logFailure(
        string $method,
        string $url,
        string $correlationId,
        string $message,
        ?int $status = null
    ): void {
        $context = [
            'method' => $method,
            'url' => $this->sanitizeUrlForLog($url),
            'correlation_id' => $correlationId,
            'message_digest' => hash('sha256', $message),
            'message_length' => strlen($message),
        ];

        if ($status !== null) {
            $context['status'] = $status;
        }

        $this->logger->error('[Shopify] {method} {url} failed', $context);
    }

    /**
     * Remove sensitive query information from URLs logged to disk.
     */
    private function sanitizeUrlForLog(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $sanitized = $parts['scheme'] ?? '';
        if ($sanitized !== '') {
            $sanitized .= '://';
        }

        if (!empty($parts['host'])) {
            $sanitized .= $parts['host'];
        }

        if (!empty($parts['port'])) {
            $sanitized .= ':' . $parts['port'];
        }

        if (!empty($parts['path'])) {
            $sanitized .= $parts['path'];
        }

        if (!empty($parts['fragment'])) {
            $sanitized .= '#' . $parts['fragment'];
        }

        return $sanitized !== '' ? $sanitized : $url;
    }
}
