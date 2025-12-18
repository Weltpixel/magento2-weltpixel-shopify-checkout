<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model\Webhook;

use Magento\Framework\Exception\LocalizedException;
use Magento\Directory\Model\RegionFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use Magento\Quote\Model\Quote\Address\RateFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use WeltPixel\ShopifyCheckout\Api\QuoteSessionRepositoryInterface;
use WeltPixel\ShopifyCheckout\Model\Checkout\CheckoutSessionManager;
use WeltPixel\ShopifyCheckout\Model\Checkout\QuoteSessionStatus;
use WeltPixel\ShopifyCheckout\Model\Payment\Method\Shopify as ShopifyPaymentMethod;

/**
 * Converts Shopify order payloads into Magento orders, ensuring address, tax, and shipping parity.
 */
class OrderProcessor
{
    public const QUOTE_SKIP_ALLOWED_COUNTRY_VALIDATION_FLAG = 'weltpixel_shopify_skip_allowed_country_validation';
    private const DEFAULT_SHIPPING_METHOD = 'freeshipping_freeshipping';
    private const DEFAULT_SHIPPING_TITLE = 'Free Shipping';

    /**
     * Quote repository for loading Magento quotes.
     *
     * @var CartRepositoryInterface
     */
    private CartRepositoryInterface $cartRepository;

    /**
     * Quote management service for submitting orders.
     *
     * @var QuoteManagement
     */
    private QuoteManagement $quoteManagement;

    /**
     * Order repository used to persist the generated order.
     *
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * Session repository for Shopify metadata.
     *
     * @var QuoteSessionRepositoryInterface
     */
    private QuoteSessionRepositoryInterface $quoteSessionRepository;

    /**
     * Logger instance.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Region factory for resolving province codes.
     *
     * @var RegionFactory
     */
    private RegionFactory $regionFactory;

    /**
     * Checkout session manager for clearing Shopify sessions.
     *
     * @var CheckoutSessionManager
     */
    private CheckoutSessionManager $checkoutSessionManager;

    /**
     * Shipping rate factory for synthetic rate creation.
     *
     * @var RateFactory
     */
    private RateFactory $rateFactory;

    /**
     * @param CartRepositoryInterface $cartRepository
     * @param QuoteManagement $quoteManagement
     * @param OrderRepositoryInterface $orderRepository
     * @param QuoteSessionRepositoryInterface $quoteSessionRepository
     * @param LoggerInterface $logger
     * @param RegionFactory $regionFactory
     * @param CheckoutSessionManager $checkoutSessionManager
     * @param RateFactory $rateFactory
     */
    public function __construct(
        CartRepositoryInterface $cartRepository,
        QuoteManagement $quoteManagement,
        OrderRepositoryInterface $orderRepository,
        QuoteSessionRepositoryInterface $quoteSessionRepository,
        LoggerInterface $logger,
        RegionFactory $regionFactory,
        CheckoutSessionManager $checkoutSessionManager,
        RateFactory $rateFactory
    ) {
        $this->cartRepository = $cartRepository;
        $this->quoteManagement = $quoteManagement;
        $this->orderRepository = $orderRepository;
        $this->quoteSessionRepository = $quoteSessionRepository;
        $this->logger = $logger;
        $this->regionFactory = $regionFactory;
        $this->checkoutSessionManager = $checkoutSessionManager;
        $this->rateFactory = $rateFactory;
    }

    /**
     * Build and submit a Magento order from the received Shopify payload.
     *
     * @param array<string,mixed> $payload
     * @return void
     * @throws LocalizedException
     */
    public function process(array $payload): void
    {
        $quoteId = $this->extractQuoteId($payload);

        if (!$quoteId) {
            throw new LocalizedException(__('Unable to locate Magento quote reference in webhook payload.'));
        }

        try {
            $quote = $this->cartRepository->get($quoteId);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $exception) {
            throw new LocalizedException(__('Quote %1 no longer exists.', $quoteId));
        }

        try {
            $session = $this->quoteSessionRepository->getByQuoteId((int) $quoteId);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $exception) {
            throw new LocalizedException(__('No Shopify session metadata found for quote %1.', $quoteId));
        }
        if ($session->getStatus() === QuoteSessionStatus::STATUS_COMPLETED) {
            $this->logger->info('Shopify webhook ignored; quote already processed.', ['quote_id' => $quoteId]);
            return;
        }

        $quote->setData(self::QUOTE_SKIP_ALLOWED_COUNTRY_VALIDATION_FLAG, true);
        $this->applyShopifyAddresses($quote, $payload);
        $this->ensureShippingMethod($quote, $payload);
        $quote->collectTotals();
        $this->synchronizeTaxTotals($quote, $payload);
        $quote->getPayment()->setMethod(ShopifyPaymentMethod::PAYMENT_METHOD_CODE);
        $order = $this->quoteManagement->submit($quote);

        if (!$order || !$order->getEntityId()) {
            throw new LocalizedException(__('Unable to convert quote %1 into an order.', $quoteId));
        }

        $this->synchronizeOrderTax($order, $payload);
        $order->addCommentToStatusHistory(
            __('Shopify order %1 processed via webhook.', $payload['id'] ?? 'unknown')
        )->setIsCustomerNotified(false);

        if (($payload['financial_status'] ?? '') === 'paid') {
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING));
        }

        $this->orderRepository->save($order);

        /** We don't want to delete this, we can keep this information in the weltpixel_shopifycheckout_quote table */
//        try {
//            $this->checkoutSessionManager->clearForQuote($quote);
//        } catch (\Throwable $exception) {
//            $this->logger->warning(
//                'Unable to clear Shopify checkout session metadata after order conversion.',
//                ['quote_id' => $quote->getId(), 'exception' => $exception]
//            );
//        }
        $this->finalizeQuoteState($quote);

        $session->setStatus(QuoteSessionStatus::STATUS_COMPLETED);
        $this->quoteSessionRepository->save($session);
    }

    /**
     * Finalize the quote after successful order submission.
     *
     * @param Quote $quote
     * @return void
     */
    private function finalizeQuoteState(Quote $quote): void
    {
        try {
            $quote->setIsActive(false);
            $this->cartRepository->save($quote);
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'Unable to persist Shopify quote deactivation.',
                ['quote_id' => $quote->getId(), 'exception' => $exception]
            );
        }

        if ($quote->getCustomerId()) {
            try {
                $this->quoteManagement->createEmptyCartForCustomer((int) $quote->getCustomerId());
            } catch (\Throwable $exception) {
                $this->logger->warning(
                    'Unable to seed empty cart after Shopify order conversion.',
                    ['customer_id' => $quote->getCustomerId(), 'quote_id' => $quote->getId(), 'exception' => $exception]
                );
            }
        }
    }

    /**
     * Extract the Magento quote ID from Shopify order note attributes.
     *
     * @param array<string,mixed> $payload
     * @return int|null
     */
    private function extractQuoteId(array $payload): ?int
    {
        if (!isset($payload['note_attributes']) || !is_array($payload['note_attributes'])) {
            return null;
        }

        foreach ($payload['note_attributes'] as $attribute) {
            if (($attribute['name'] ?? '') === 'magento_quote_id') {
                return (int) ($attribute['value'] ?? 0);
            }
        }

        return null;
    }

    /**
     * Map Shopify billing/shipping addresses onto the Magento quote.
     *
     * @param Quote $quote
     * @param array<string,mixed> $payload
     * @return void
     */
    private function applyShopifyAddresses(\Magento\Quote\Model\Quote $quote, array $payload): void
    {
        $billingData = $payload['billing_address'] ?? null;
        $shippingData = $payload['shipping_address'] ?? $billingData;

        if ($shippingData) {
            $this->mapAddressToQuote($quote->getShippingAddress(), $shippingData, 'shipping', $payload);
            $quote->getShippingAddress()->setCollectShippingRates(true);
        }

        if ($billingData) {
            $this->mapAddressToQuote($quote->getBillingAddress(), $billingData, 'billing', $payload);
        }

        if (!$quote->getCustomerEmail()) {
            $email = $payload['email'] ?? $payload['contact_email'] ?? ($payload['customer']['email'] ?? null);

            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $quote->setCustomerEmail($email);
            } else {
                $fallbackEmail = sprintf(
                    'shopify-order-%s@no-reply.shopify',
                    $payload['id'] ?? $quote->getId() ?? uniqid()
                );
                $quote->setCustomerEmail($fallbackEmail);
                $this->logger->warning(
                    'Shopify webhook missing valid email; applied fallback.',
                    [
                        'shopify_order_id' => $payload['id'] ?? null,
                        'provided_email' => $email,
                        'fallback_email' => $fallbackEmail,
                    ]
                );
            }
        }
    }

    /**
     * Ensure the quote has a valid shipping method, applying Shopify data or defaults.
     *
     * @param Quote $quote
     * @param array<string,mixed> $payload
     * @return void
     */
    private function ensureShippingMethod(Quote $quote, array $payload): void
    {
        if ($quote->isVirtual()) {
            return;
        }

        $address = $quote->getShippingAddress();
        if (!$address) {
            return;
        }

        $shippingLine = $this->extractShippingLine($payload);

        if ($shippingLine) {
            $this->applyShopifyShippingRate($address, $shippingLine);
            return;
        }

        $currentMethod = $address->getShippingMethod();
        $rateExists = $currentMethod ? (bool) $address->getShippingRateByCode($currentMethod) : false;

        if ($currentMethod && $rateExists) {
            return;
        }

        $this->applyDefaultShippingRate($address);
    }

    /**
     * Extract the first Shopify shipping line from a payload.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function extractShippingLine(array $payload): ?array
    {
        if (!isset($payload['shipping_lines']) || !is_array($payload['shipping_lines'])) {
            return null;
        }

        return $payload['shipping_lines'][0] ?? null;
    }

    /**
     * Apply a Shopify-provided shipping line to a Magento quote address.
     *
     * @param \Magento\Quote\Model\Quote\Address $address
     * @param array<string,mixed> $shippingLine
     * @return void
     */
    private function applyShopifyShippingRate(
        \Magento\Quote\Model\Quote\Address $address,
        array $shippingLine
    ): void {
        $title = (string) ($shippingLine['title'] ?? __('Shipping'));
        $codeRaw = (string) ($shippingLine['code'] ?? 'shopify');
        $methodCode = $this->buildShopifyShippingMethodCode($codeRaw);
        $carrierCode = 'shopify';
        $methodOnly = $this->extractShopifyMethod($methodCode);
        $price = isset($shippingLine['price']) ? (float) $shippingLine['price'] : 0.0;
        $taxAmount = $this->sumTaxLines($shippingLine['tax_lines'] ?? []);

        $address->setShippingMethod($methodCode);
        $address->setShippingDescription(__('Shopify - %1', $title));
        $address->setShippingAmount($price);
        $address->setBaseShippingAmount($price);
        $address->setShippingTaxAmount($taxAmount);
        $address->setBaseShippingTaxAmount($taxAmount);
        $address->setShippingDiscountAmount(0.0);
        $address->setBaseShippingDiscountAmount(0.0);
        $address->setCollectShippingRates(false);

        if (!$address->getShippingRateByCode($methodCode)) {
            $rate = $this->rateFactory->create();
            $rate->setAddress($address);
            $rate->setCode($methodCode);
            $rate->setCarrier($carrierCode);
            $rate->setCarrierTitle('Shopify');
            $rate->setMethod($methodOnly);
            $rate->setMethodTitle($title);
            $rate->setPrice($price);
            $address->addShippingRate($rate);
        }
    }

    /**
     * Apply the fallback free-shipping method when Shopify does not supply one.
     *
     * @param \Magento\Quote\Model\Quote\Address $address
     * @return void
     */
    private function applyDefaultShippingRate(\Magento\Quote\Model\Quote\Address $address): void
    {
        $methodCode = self::DEFAULT_SHIPPING_METHOD;
        $title = (string) __(self::DEFAULT_SHIPPING_TITLE);

        $address->setShippingMethod($methodCode);
        $address->setShippingDescription($title);
        $address->setShippingAmount(0.0);
        $address->setBaseShippingAmount(0.0);
        $address->setShippingTaxAmount(0.0);
        $address->setBaseShippingTaxAmount(0.0);
        $address->setShippingDiscountAmount(0.0);
        $address->setBaseShippingDiscountAmount(0.0);
        $address->setCollectShippingRates(false);

        if (!$address->getShippingRateByCode($methodCode)) {
            $rate = $this->rateFactory->create();
            $rate->setAddress($address);
            $rate->setCode($methodCode);
            $rate->setCarrier('freeshipping');
            $rate->setCarrierTitle($title);
            $rate->setMethod('freeshipping');
            $rate->setMethodTitle($title);
            $rate->setPrice(0.0);
            $address->addShippingRate($rate);
        }
    }

    /**
     * Sum price values across Shopify tax line entries.
     *
     * @param array<int,array<string,mixed>> $taxLines
     * @return float
     */
    private function sumTaxLines(array $taxLines): float
    {
        $taxAmount = 0.0;
        foreach ($taxLines as $taxLine) {
            $taxAmount += isset($taxLine['price']) ? (float) $taxLine['price'] : 0.0;
        }

        return $taxAmount;
    }

    /**
     * Align quote tax totals with the values received from Shopify.
     *
     * @param Quote $quote
     * @param array<string,mixed> $payload
     * @return void
     */
    private function synchronizeTaxTotals(Quote $quote, array $payload): void
    {
        $shopifyTax = $this->extractShopifyTaxTotal($payload);
        if ($shopifyTax === null) {
            return;
        }

        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        if (!$address) {
            return;
        }

        $currentTax = (float) $address->getTaxAmount();
        if ($this->floatEquals($shopifyTax, $currentTax)) {
            return;
        }

        $delta = $shopifyTax - $currentTax;

        $address->setTaxAmount($shopifyTax);
        $address->setBaseTaxAmount($shopifyTax);
        $address->setTotalAmount('tax', $shopifyTax);
        $address->setBaseTotalAmount('tax', $shopifyTax);
        $address->setAppliedTaxes($this->buildAppliedTaxes($payload, $shopifyTax));
        $address->setItemsAppliedTaxes([]);

        if ($address->getGrandTotal() !== null) {
            $address->setGrandTotal($address->getGrandTotal() + $delta);
        }
        if ($address->getBaseGrandTotal() !== null) {
            $address->setBaseGrandTotal($address->getBaseGrandTotal() + $delta);
        }

        $billingAddress = $quote->getBillingAddress();
        if ($billingAddress && $billingAddress !== $address) {
            $billingAddress->setTaxAmount($shopifyTax);
            $billingAddress->setBaseTaxAmount($shopifyTax);
        }

        $quote->setTaxAmount($shopifyTax);
        $quote->setBaseTaxAmount($shopifyTax);
        $quote->setGrandTotal($quote->getGrandTotal() + $delta);
        $quote->setBaseGrandTotal($quote->getBaseGrandTotal() + $delta);
        $quote->setData('weltpixel_shopify_tax_adjustment', $shopifyTax);
    }

    /**
     * Align order tax totals with Shopify payload values.
     *
     * @param Order $order
     * @param array<string,mixed> $payload
     * @return void
     */
    private function synchronizeOrderTax(Order $order, array $payload): void
    {
        $shopifyTax = $this->extractShopifyTaxTotal($payload);
        if ($shopifyTax === null) {
            return;
        }

        $currentTax = (float) $order->getTaxAmount();
        if ($this->floatEquals($shopifyTax, $currentTax)) {
            return;
        }

        $delta = $shopifyTax - $currentTax;

        $order->setTaxAmount($shopifyTax);
        $order->setBaseTaxAmount($shopifyTax);
        if ($order->getGrandTotal() !== null) {
            $order->setGrandTotal($order->getGrandTotal() + $delta);
        }
        if ($order->getBaseGrandTotal() !== null) {
            $order->setBaseGrandTotal($order->getBaseGrandTotal() + $delta);
        }
        if ($order->getTotalDue() !== null) {
            $order->setTotalDue(max(0.0, $order->getTotalDue() + $delta));
        }
        if ($order->getBaseTotalDue() !== null) {
            $order->setBaseTotalDue(max(0.0, $order->getBaseTotalDue() + $delta));
        }
        $order->setData('weltpixel_shopify_tax_adjustment', $shopifyTax);

        $orderAddress = $order->getShippingAddress() ?: $order->getBillingAddress();
        if ($orderAddress) {
            $orderAddress->setTaxAmount($shopifyTax);
            $orderAddress->setBaseTaxAmount($shopifyTax);
        }
    }

    /**
     * Determine the total tax amount represented in the Shopify payload.
     *
     * @param array<string,mixed> $payload
     * @return float|null
     */
    private function extractShopifyTaxTotal(array $payload): ?float
    {
        $customTax = $this->extractCustomTaxLineTotal($payload);
        if ($customTax !== null) {
            return $customTax;
        }

        if (isset($payload['total_tax']) && (float) $payload['total_tax'] > 0.0001) {
            return (float) $payload['total_tax'];
        }

        $taxTotal = 0.0;

        if (!empty($payload['line_items']) && is_array($payload['line_items'])) {
            foreach ($payload['line_items'] as $lineItem) {
                $taxTotal += $this->sumTaxLines($lineItem['tax_lines'] ?? []);
            }
        }

        if (!empty($payload['shipping_lines']) && is_array($payload['shipping_lines'])) {
            foreach ($payload['shipping_lines'] as $shippingLine) {
                $taxTotal += $this->sumTaxLines($shippingLine['tax_lines'] ?? []);
            }
        }

        return $taxTotal > 0.0001 ? $taxTotal : null;
    }

    /**
     * Extract the value of custom Shopify tax line items we generated.
     *
     * @param array<string,mixed> $payload
     * @return float|null
     */
    private function extractCustomTaxLineTotal(array $payload): ?float
    {
        if (empty($payload['line_items']) || !is_array($payload['line_items'])) {
            return null;
        }

        $total = 0.0;

        foreach ($payload['line_items'] as $lineItem) {
            $title = isset($lineItem['title']) ? strtolower((string) $lineItem['title']) : '';
            $matchesTaxTitle = $title === 'tax' || strpos($title, 'tax') !== false;
            if (!$matchesTaxTitle) {
                continue;
            }

            $sku = isset($lineItem['sku']) ? (string) $lineItem['sku'] : '';
            $isSynthetic = $sku === ''
                && !(bool) ($lineItem['taxable'] ?? true)
                && !(bool) ($lineItem['requires_shipping'] ?? true);

            if (!$isSynthetic) {
                continue;
            }

            $price = isset($lineItem['price']) ? (float) $lineItem['price'] : 0.0;
            $quantity = isset($lineItem['quantity']) ? max(1, (int) $lineItem['quantity']) : 1;
            $total += $price * $quantity;
        }

        return $total > 0.0001 ? $total : null;
    }

    /**
     * Build applied tax breakdown array compatible with Magento tax subsystem.
     *
     * @param array<string,mixed> $payload
     * @param float $taxAmount
     * @return array<int,array<string,mixed>>
     */
    private function buildAppliedTaxes(array $payload, float $taxAmount): array
    {
        $applied = [];
        $taxLines = isset($payload['tax_lines']) && is_array($payload['tax_lines']) ? $payload['tax_lines'] : [];

        foreach ($taxLines as $taxLine) {
            $amount = isset($taxLine['price']) ? (float) $taxLine['price'] : 0.0;
            if ($amount <= 0.0001) {
                continue;
            }

            $title = (string) ($taxLine['title'] ?? 'Tax');
            $percent = isset($taxLine['rate']) ? (float) $taxLine['rate'] * 100 : null;
            $code = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $title)) ?: 'shopify_tax';

            $applied[] = [
                'id' => 'shopify_' . $code,
                'item_type' => 'order',
                'amount' => $amount,
                'base_amount' => $amount,
                'percent' => $percent,
                'rates' => [[
                    'percent' => $percent,
                    'code' => 'shopify_' . $code,
                    'title' => $title,
                ]],
            ];
        }

        if (!$applied && $taxAmount > 0.0001) {
            $applied[] = [
                'id' => 'shopify_tax',
                'item_type' => 'order',
                'amount' => $taxAmount,
                'base_amount' => $taxAmount,
                'percent' => null,
                'rates' => [[
                    'percent' => null,
                    'code' => 'shopify_tax',
                    'title' => 'Shopify Tax',
                ]],
            ];
        }

        return $applied;
    }

    /**
     * Compare float values using epsilon tolerance.
     */
    private function floatEquals(float $first, float $second, float $epsilon = 0.0001): bool
    {
        return abs($first - $second) < $epsilon;
    }

    /**
     * Normalize Shopify shipping codes for storage in Magento.
     */
    private function buildShopifyShippingMethodCode(string $codeRaw): string
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9_]+/', '_', $codeRaw));
        $normalized = trim($normalized, '_') ?: 'standard';

        return 'shopify_' . $normalized;
    }

    /**
     * Extract method component from generated Shopify carrier code.
     */
    private function extractShopifyMethod(string $methodCode): string
    {
        $parts = explode('_', $methodCode, 2);

        return $parts[1] ?? $parts[0];
    }

    /**
     * Map a Shopify order address structure onto a Magento quote address instance.
     *
     * @param \Magento\Quote\Model\Quote\Address $quoteAddress
     * @param array<string,mixed> $shopifyAddress
     * @return void
     */
    private function mapAddressToQuote(
        \Magento\Quote\Model\Quote\Address $quoteAddress,
        array $shopifyAddress,
        string $addressType,
        array $payload
    ): void
    {
        $countryId = strtoupper((string) ($shopifyAddress['country_code'] ?? ''));
        $regionCode = $shopifyAddress['province_code'] ?? '';

        $city = isset($shopifyAddress['city']) ? trim((string) $shopifyAddress['city']) : '';
        if ($city === '') {
            $city = 'Unknown';
            $this->logger->warning(
                'Shopify webhook address missing city; applying fallback.',
                [
                    'address_type' => $addressType,
                    'shopify_order_id' => $payload['id'] ?? null,
                    'country_code' => $shopifyAddress['country_code'] ?? null,
                    'province_code' => $regionCode,
                ]
            );
        }

        $postcode = isset($shopifyAddress['zip']) ? trim((string) $shopifyAddress['zip']) : '';
        if ($postcode === '') {
            $postcode = '000000';
            $this->logger->warning(
                'Shopify webhook address missing postcode; applying fallback.',
                [
                    'address_type' => $addressType,
                    'shopify_order_id' => $payload['id'] ?? null,
                    'country_code' => $shopifyAddress['country_code'] ?? null,
                    'province_code' => $regionCode,
                ]
            );
        }

        $regionName = isset($shopifyAddress['province']) && $shopifyAddress['province'] !== ''
            ? $shopifyAddress['province']
            : 'N/A';

        $quoteAddress->addData([
            'firstname' => $shopifyAddress['first_name'] ?? 'Shopify',
            'lastname' => $shopifyAddress['last_name'] ?? 'Customer',
            'company' => $shopifyAddress['company'] ?? '',
            'street' => $this->mergeStreet($shopifyAddress),
            'city' => $city,
            'postcode' => $postcode,
            'country_id' => $countryId,
            'telephone' => $shopifyAddress['phone'] ?? '0000000000',
            'region' => $regionName,
            'region_code' => $regionCode,
            'region_id' => $this->resolveRegionId($countryId, $regionCode),
        ]);

        $quoteAddress->setShouldIgnoreValidation(true);
    }

    /**
     * Merge Shopify address lines into a Magento street array.
     *
     * @param array<string,mixed> $shopifyAddress
     * @return array<int,string>
     */
    private function mergeStreet(array $shopifyAddress): array
    {
        $street = [];
        if (!empty($shopifyAddress['address1'])) {
            $street[] = $shopifyAddress['address1'];
        }
        if (!empty($shopifyAddress['address2'])) {
            $street[] = $shopifyAddress['address2'];
        }

        return $street ?: ['Shopify Checkout'];
    }

    /**
     * Resolve Magento region ID for a given Shopify country/province pair.
     *
     * @param string $countryId
     * @param string $regionCode
     * @return int|null
     */
    private function resolveRegionId(string $countryId, string $regionCode): ?int
    {
        if (!$countryId || !$regionCode) {
            return null;
        }

        $region = $this->regionFactory->create()->loadByCode($regionCode, $countryId);

        return $region && $region->getId() ? (int) $region->getId() : null;
    }
}
