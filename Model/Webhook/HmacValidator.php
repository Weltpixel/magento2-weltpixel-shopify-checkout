<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model\Webhook;

use WeltPixel\ShopifyCheckout\Model\Config;

/**
 * Validates Shopify webhook HMAC signatures against configured shared secrets.
 */
class HmacValidator
{
    private const HEADER_HMAC = 'X-Shopify-Hmac-Sha256';

    /**
     * Module configuration for retrieving webhook secrets.
     *
     * @var Config
     */
    private Config $config;

    /**
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Validate the HMAC header for the given webhook payload.
     *
     * @param string $payload
     * @param array<mixed> $headers
     * @param int|null $storeId
     * @return bool
     */
    public function isValid(string $payload, array $headers, ?int $storeId = null): bool
    {
        $secrets = $this->config->getWebhookSharedSecrets(
            $storeId !== null ? (string) $storeId : null
        );

        if (!$secrets) {
            return false;
        }

        $matchedHeader = $this->extractHeader($headers, self::HEADER_HMAC);

        if ($matchedHeader === null) {
            return false;
        }

        foreach ($secrets as $secret) {
            $calculated = base64_encode(hash_hmac('sha256', $payload, $secret, true));

            if (hash_equals($calculated, $matchedHeader)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Locate a header value regardless of case sensitivity.
     *
     * @param array<mixed> $headers
     * @param string $key
     * @return string|null
     */
    private function extractHeader(array $headers, string $key): ?string
    {
        if (isset($headers[$key])) {
            $value = $headers[$key];
        } else {
            $lower = array_change_key_case($headers, CASE_LOWER);
            $value = $lower[strtolower($key)] ?? null;
        }

        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = reset($value);
        }

        return $value !== false ? trim((string) $value) : null;
    }
}
