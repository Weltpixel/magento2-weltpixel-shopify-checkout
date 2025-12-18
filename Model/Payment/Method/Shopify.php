<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model\Payment\Method;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\ScopeInterface;
use WeltPixel\ShopifyCheckout\Model\Config;

/**
 * Virtual payment gateway that marks orders as processed via Shopify checkout.
 */
class Shopify extends AbstractMethod
{
    public const PAYMENT_METHOD_CODE = 'weltpixel_shopifycheckout';

    protected $_code = self::PAYMENT_METHOD_CODE;
    protected $_canCapture = false;
    protected $_canAuthorize = false;
    protected $_canCapturePartial = false;
    protected $_canRefund = false;
    protected $_canVoid = false;
    protected $_canUseForMultishipping = false;
    protected $_isGateway = false;

    /**
     * Shopify configuration helper.
     *
     * @var Config
     */
    private Config $config;

    /**
     * @param Config $config
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Payment\Model\Method\Logger $logger
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource|null $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb|null $resourceCollection
     * @param array<int,mixed> $data
     */
    public function __construct(
        Config $config,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->config = $config;
    }

    /** @inheritDoc */
    public function isActive($storeId = null): bool
    {
        return parent::isActive($storeId) && $this->config->isEnabled(
            $storeId !== null ? (string) $storeId : null,
            ScopeInterface::SCOPE_STORE
        );
    }

    /** @inheritDoc */
    public function assignData(DataObject $data): self
    {
        return $this;
    }

    /** @inheritDoc */
    public function validate(): self
    {
        return $this;
    }

    /** @inheritDoc */
    public function canUseForCountry($country): bool
    {
        return true;
    }

    /** @inheritDoc */
    public function canUseCheckout(): bool
    {
        return true;
    }

    /** @inheritDoc */
    public function canCapture(): bool
    {
        return false;
    }

    /** @inheritDoc */
    public function canAuthorize(): bool
    {
        return false;
    }

    /** @inheritDoc */
    public function isInitializeNeeded(): bool
    {
        return false;
    }

    /** @inheritDoc */
    public function initialize($paymentAction, $stateObject): void
    {
    }

    /** @inheritDoc */
    public function getTitle(): string
    {
        return (string) $this->_scopeConfig->getValue(
            sprintf('payment/%s/title', self::PAYMENT_METHOD_CODE)
        );
    }

    /** @inheritDoc */
    public function canUseForCurrency($currencyCode): bool
    {
        return true;
    }

    /** @inheritDoc */
    public function canUseInternal(): bool
    {
        return false;
    }

    /** @inheritDoc */
    public function canManageRecurringProfiles(): bool
    {
        return false;
    }

    /** @inheritDoc */
    public function canRefund(): bool
    {
        return false;
    }

    /** @inheritDoc */
    public function canRefundPartialPerInvoice(): bool
    {
        return false;
    }

    /** @inheritDoc */
    public function capture(InfoInterface $payment, $amount): self
    {
        throw new LocalizedException(__('Capture action is not supported by Shopify Checkout handoff.'));
    }

    /** @inheritDoc */
    public function authorize(InfoInterface $payment, $amount): self
    {
        throw new LocalizedException(__('Authorize action is not supported by Shopify Checkout handoff.'));
    }

    /** @inheritDoc */
    public function order(InfoInterface $payment, $amount): self
    {
        throw new LocalizedException(__('Order action is not supported by Shopify Checkout handoff.'));
    }

    /** @inheritDoc */
    public function refund(InfoInterface $payment, $amount): self
    {
        throw new LocalizedException(__('Refund action is not supported by Shopify Checkout handoff.'));
    }

    /** @inheritDoc */
    public function void(InfoInterface $payment): self
    {
        throw new LocalizedException(__('Void action is not supported by Shopify Checkout handoff.'));
    }

    /** @inheritDoc */
    public function cancel(InfoInterface $payment): self
    {
        throw new LocalizedException(__('Cancel action is not supported by Shopify Checkout handoff.'));
    }

    /** @inheritDoc */
    public function assignQuote(?Quote $quote = null): void
    {
    }
}
