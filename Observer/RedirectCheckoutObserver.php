<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Observer;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use WeltPixel\ShopifyCheckout\Model\Config;

/**
 * Redirects Magento checkout traffic to the Shopify redirect controller when replace mode is enabled.
 */
class RedirectCheckoutObserver implements ObserverInterface
{
    /**
     * Shopify checkout configuration model.
     *
     * @var Config
     */
    private Config $config;

    /**
     * URL builder used to construct redirect URLs.
     *
     * @var UrlInterface
     */
    private UrlInterface $urlBuilder;

    /**
     * Action flag controller helper.
     *
     * @var ActionFlag
     */
    private ActionFlag $actionFlag;

    /**
     * Store manager for resolving current store scope.
     *
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @param Config $config
     * @param UrlInterface $urlBuilder
     * @param ActionFlag $actionFlag
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Config $config,
        UrlInterface $urlBuilder,
        ActionFlag $actionFlag,
        StoreManagerInterface $storeManager
    ) {
        $this->config = $config;
        $this->urlBuilder = $urlBuilder;
        $this->actionFlag = $actionFlag;
        $this->storeManager = $storeManager;
    }

    /**
     * Force Magento checkout requests to the Shopify redirect controller when replacement mode is active.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $storeId = (string) $this->storeManager->getStore()->getId();

        if (!$this->config->isEnabled($storeId)
            || $this->config->getCheckoutBehavior($storeId) !== Config::CHECKOUT_BEHAVIOR_REPLACE
        ) {
            return;
        }

        $controller = $observer->getEvent()->getControllerAction();
        if (!$controller) {
            return;
        }

        $request = $controller->getRequest();
        if ($request && $request->getRouteName() === 'shopifycheckout') {
            return;
        }

        $redirectUrl = $this->urlBuilder->getUrl('shopifycheckout/session/redirect');
        $controller->getResponse()->setRedirect($redirectUrl);
        $this->actionFlag->set('', Action::FLAG_NO_DISPATCH, true);
    }
}
