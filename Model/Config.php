<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Provides access to Shopify checkout configuration values across scopes.
 */
class Config
{
    private const XML_PATH_ENABLED = 'weltpixel_shopifycheckout/general/enabled';
    private const XML_PATH_ENVIRONMENT = 'weltpixel_shopifycheckout/general/environment';
    private const XML_PATH_CHECKOUT_BEHAVIOR = 'weltpixel_shopifycheckout/general/checkout_behavior';
    private const XML_PATH_CREATE_PRODUCTS_BEFORE_CHECKOUT = 'weltpixel_shopifycheckout/general/create_products_before_checkout';

    private const XML_PATH_SANDBOX_PREFIX = 'weltpixel_shopifycheckout/sandbox_credentials/';
    private const XML_PATH_PRODUCTION_PREFIX = 'weltpixel_shopifycheckout/production_credentials/';

    public const CHECKOUT_BEHAVIOR_SEPARATE = 'separate';
    public const CHECKOUT_BEHAVIOR_REPLACE = 'replace';

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    private EncryptorInterface $encryptor;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        StoreManagerInterface $storeManager
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->storeManager = $storeManager;
    }

    /**
     * Check if Shopify checkout handoff is enabled for the given scope.
     *
     * @param string|null $scopeCode
     * @param string $scopeType
     * @return bool
     */
    public function isEnabled(?string $scopeCode = null, string $scopeType = ScopeInterface::SCOPE_STORE): bool
    {
        return $this->isFlag(self::XML_PATH_ENABLED, $scopeCode, $scopeType);
    }

    /**
     * Retrieve active Shopify environment (sandbox/production).
     *
     * @param string|null $scopeCode
     * @param string $scopeType
     * @return string
     */
    public function getEnvironment(?string $scopeCode = null, string $scopeType = ScopeInterface::SCOPE_STORE): string
    {
        $environment = $this->getValue(self::XML_PATH_ENVIRONMENT, $scopeCode, $scopeType);

        return $environment ?: 'sandbox';
    }

    /**
     * Get configured checkout behavior for the current scope.
     *
     * @param string|null $scopeCode
     * @param string $scopeType
     * @return string
     */
    public function getCheckoutBehavior(
        ?string $scopeCode = null,
        string $scopeType = ScopeInterface::SCOPE_STORE
    ): string {
        $behavior = $this->getValue(self::XML_PATH_CHECKOUT_BEHAVIOR, $scopeCode, $scopeType);

        return in_array($behavior, [self::CHECKOUT_BEHAVIOR_SEPARATE, self::CHECKOUT_BEHAVIOR_REPLACE], true)
            ? $behavior
            : self::CHECKOUT_BEHAVIOR_SEPARATE;
    }

    /**
     * Determine whether Shopify products should be created prior to checkout handoff.
     *
     * @param string|null $scopeCode
     * @param string $scopeType
     * @return bool
     */
    public function shouldCreateProductsBeforeCheckout(
        ?string $scopeCode = null,
        string $scopeType = ScopeInterface::SCOPE_STORE
    ): bool {
        return $this->isFlag(self::XML_PATH_CREATE_PRODUCTS_BEFORE_CHECKOUT, $scopeCode, $scopeType);
    }

    /**
     * Retrieve the configured Shopify store domain for the given scope/environment.
     *
     * @param string|null $scopeCode
     * @param string $scopeType
     * @param string|null $environment
     * @return string|null
     */
    public function getStoreDomain(
        ?string $scopeCode = null,
        string $scopeType = ScopeInterface::SCOPE_STORE,
        ?string $environment = null
    ): ?string
    {
        $domain = $this->getCredentialValue('store_domain', $scopeCode, $scopeType, $environment);

        if ($domain === null || $domain === '') {
            return null;
        }

        return strtolower(trim($domain));
    }

    /**
     * Retrieve the decrypted Shopify Admin access token for the given scope/environment.
     *
     * @param string|null $scopeCode
     * @param string $scopeType
     * @param string|null $environment
     * @return string|null
     */
    public function getAdminAccessToken(
        ?string $scopeCode = null,
        string $scopeType = ScopeInterface::SCOPE_STORE,
        ?string $environment = null
    ): ?string
    {
        return $this->getCredentialSecret('access_token', $scopeCode, $scopeType, $environment);
    }

    /**
     * Retrieve the webhook shared secret for validating HMAC signatures.
     *
     * @param string|null $scopeCode
     * @param string $scopeType
     * @param string|null $environment
     * @return string|null
     */
    public function getWebhookSharedSecret(
        ?string $scopeCode = null,
        string $scopeType = ScopeInterface::SCOPE_STORE,
        ?string $environment = null
    ): ?string
    {
        return $this->getCredentialSecret('webhook_secret', $scopeCode, $scopeType, $environment);
    }

    /**
     * @return array<int,string>
     */
    /**
     * Retrieve distinct webhook secrets across sandbox/production for the requested scope.
     *
     * @param string|null $scopeCode
     * @param string $scopeType
     * @return array<int,string>
     */
    public function getWebhookSharedSecrets(
        ?string $scopeCode = null,
        string $scopeType = ScopeInterface::SCOPE_STORE
    ): array {
        $secrets = [];

        $scopes = [];

        if ($scopeCode !== null) {
            $scopes[] = [$scopeType, $scopeCode];
        } else {
            $scopes[] = [$scopeType, null];
            foreach ($this->storeManager->getStores() as $store) {
                $scopes[] = [ScopeInterface::SCOPE_STORE, (string) $store->getId()];
            }
        }

        foreach ($scopes as [$scope, $code]) {
            foreach (['sandbox', 'production'] as $environment) {
                $secret = $this->getWebhookSharedSecret($code, $scope, $environment);
                if ($secret) {
                    $secrets[] = $secret;
                }
            }
        }

        return array_values(array_unique($secrets));
    }

    /**
     * @return array<int,string>
     */
    /**
     * Collect all configured Shopify store domains across stores and environments.
     *
     * @return array<int,string>
     */
    public function getAllStoreDomains(): array
    {
        $domains = [];

        $scopes = [ [ScopeInterface::SCOPE_STORE, null] ];

        foreach ($this->storeManager->getStores() as $store) {
            $scopes[] = [ScopeInterface::SCOPE_STORE, (string) $store->getId()];
        }

        foreach ($scopes as [$scope, $code]) {
            foreach (['sandbox', 'production'] as $environment) {
                $domain = $this->getStoreDomain($code, $scope, $environment);
                if ($domain) {
                    $domains[] = strtolower($domain);
                }
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * Resolve a credential value for the requested environment.
     *
     * @param string $field
     * @param string|null $scopeCode
     * @param string $scopeType
     * @param string|null $environment
     * @return string|null
     */
    private function getCredentialValue(
        string $field,
        ?string $scopeCode,
        string $scopeType,
        ?string $environment = null
    ): ?string
    {
        $path = $this->resolveCredentialPath($field, $scopeCode, $scopeType, $environment);

        if (!$path) {
            return null;
        }

        $value = $this->getValue($path, $scopeCode, $scopeType);
        if (($value === null || $value === '') && $scopeType !== \Magento\Framework\App\Config\ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
            $value = $this->getValue($path, null, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        }

        return $value;
    }

    /**
     * Retrieve and decrypt secret credentials for the requested environment.
     *
     * @param string $field
     * @param string|null $scopeCode
     * @param string $scopeType
     * @param string|null $environment
     * @return string|null
     */
    private function getCredentialSecret(
        string $field,
        ?string $scopeCode,
        string $scopeType,
        ?string $environment = null
    ): ?string
    {
        $path = $this->resolveCredentialPath($field, $scopeCode, $scopeType, $environment);

        if (!$path) {
            return null;
        }

        $value = $this->getValue($path, $scopeCode, $scopeType);
        if (($value === null || $value === '') && $scopeType !== ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
            $value = $this->getValue($path, null, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        }

        if ($value === null || $value === '') {
            return null;
        }

        return $this->encryptor->decrypt($value);
    }

    /**
     * Build the configuration path for a credential based on environment.
     *
     * @param string $field
     * @param string|null $scopeCode
     * @param string $scopeType
     * @param string|null $environmentOverride
     * @return string|null
     */
    private function resolveCredentialPath(
        string $field,
        ?string $scopeCode,
        string $scopeType,
        ?string $environmentOverride = null
    ): ?string
    {
        $environment = $environmentOverride ? strtolower($environmentOverride) : $this->getEnvironment($scopeCode, $scopeType);

        switch ($environment) {
            case 'production':
                return self::XML_PATH_PRODUCTION_PREFIX . $field;
            case 'sandbox':
                return self::XML_PATH_SANDBOX_PREFIX . $field;
            default:
                return null;
        }
    }

    /**
     * Convenience wrapper around scope config getValue with casting.
     *
     * @param string $path
     * @param string|null $scopeCode
     * @param string $scopeType
     * @return string|null
     */
    private function getValue(string $path, ?string $scopeCode, string $scopeType): ?string
    {
        $value = $this->scopeConfig->getValue($path, $scopeType, $scopeCode);

        return $value !== null ? (string) $value : null;
    }

    /**
     * @param string $path
     * @param string|null $scopeCode
     * @param string $scopeType
     * @return bool
     */
    private function isFlag(string $path, ?string $scopeCode, string $scopeType): bool
    {
        return $this->scopeConfig->isSetFlag($path, $scopeType, $scopeCode);
    }
}
