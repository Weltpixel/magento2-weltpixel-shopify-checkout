<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Plugin\Checkout\Helper;

use Magento\Checkout\Helper\Data as CheckoutHelper;
use Magento\Store\Model\StoreManagerInterface;
use WeltPixel\ShopifyCheckout\Model\Config;

class DataPlugin
{
    /**
     * Shopify checkout configuration model.
     *
     * @var Config
     */
    private Config $config;

    /**
     * Store manager used to determine current scope.
     *
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(Config $config, StoreManagerInterface $storeManager)
    {
        $this->config = $config;
        $this->storeManager = $storeManager;
    }

    /**
     * Replace Magento checkout URL with the Shopify redirect endpoint when configured.
     *
     * @param CheckoutHelper $subject
     * @param string $result
     * @return string
     */
    public function afterGetCheckoutUrl(CheckoutHelper $subject, string $result): string
    {
        $storeId = (string) $this->storeManager->getStore()->getId();

        if (!$this->config->isEnabled($storeId)) {
            return $result;
        }

        if ($this->config->getCheckoutBehavior($storeId) !== Config::CHECKOUT_BEHAVIOR_REPLACE) {
            return $result;
        }

        return $subject->getUrl('shopifycheckout/session/redirect');
    }
}
