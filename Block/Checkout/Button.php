<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Block\Checkout;

use Magento\Checkout\Helper\Data as CheckoutHelper;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use WeltPixel\ShopifyCheckout\Model\Config;

/**
 * Frontend block responsible for rendering the Shopify checkout button and JS payload.
 */
class Button extends Template
{
    /** @var Config */
    private Config $config;

    /** @var CheckoutHelper */
    private CheckoutHelper $checkoutHelper;

    /** @var RequestInterface */
    private RequestInterface $request;

    /**
     * @param Context $context
     * @param Config $config
     * @param CheckoutHelper $checkoutHelper
     * @param array<int|string,mixed> $data
     */
    public function __construct(
        Context $context,
        Config $config,
        CheckoutHelper $checkoutHelper,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->checkoutHelper = $checkoutHelper;
        $this->request = $context->getRequest();
    }

    /**
     * Determine whether the button can be rendered in current context.
     */
    public function canRender(): bool
    {
        $storeId = $this->getStoreId();
        if (!$this->config->isEnabled($storeId !== null ? (string) $storeId : null)) {
            return false;
        }

        if ($this->isReplaceBehavior()) {
            return false;
        }

        $quote = $this->checkoutHelper->getQuote();

        if (!$quote || !$quote->getId() || !$quote->hasItems()) {
            return false;
        }

        if ($quote->getHasError()) {
            return false;
        }

        if ($this->isReplaceBehavior() && !$this->isCartPage()) {
            return false;
        }

        return true;
    }

    /** @return string */
    public function getButtonLabel(): string
    {
        return (string) ($this->_scopeConfig->getValue(
            'payment/weltpixel_shopifycheckout/title'
        ) ?: __('Checkout with Shopify'));
    }

    /** @return string */
    public function getCreateSessionUrl(): string
    {
        return $this->getUrl('shopifycheckout/session/create');
    }

    /** @return int|null */
    public function getStoreId(): ?int
    {
        return (int) $this->_storeManager->getStore()->getId();
    }

    /** @return bool */
    public function isCartPage(): bool
    {
        return $this->request->getFullActionName() === 'checkout_cart_index';
    }

    /** @return string */
    public function getCheckoutBehavior(): string
    {
        $storeId = $this->getStoreId();

        return $this->config->getCheckoutBehavior($storeId !== null ? (string) $storeId : null);
    }

    /** @return bool */
    public function isSeparateBehavior(): bool
    {
        return $this->getCheckoutBehavior() === Config::CHECKOUT_BEHAVIOR_SEPARATE;
    }

    /** @return bool */
    public function isReplaceBehavior(): bool
    {
        return $this->getCheckoutBehavior() === Config::CHECKOUT_BEHAVIOR_REPLACE;
    }

    /**
     * @return array<string,mixed>
     */
    public function getHandoffConfig(string $buttonSelector, ?string $feedbackSelector = null): array
    {
        $config = [
            'buttonSelector' => $buttonSelector,
            'createSessionUrl' => $this->getCreateSessionUrl(),
            'cartPage' => $this->isCartPage(),
        ];

        if ($feedbackSelector) {
            $config['feedbackSelector'] = $feedbackSelector;
        }

        return $config;
    }
}
