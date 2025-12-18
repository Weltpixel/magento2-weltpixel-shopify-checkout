<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model\Checkout;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;

/**
 * Responsible for translating Magento quote data into Shopify draft order payloads.
 */
class PayloadBuilder
{
    public const CONTEXT_DYNAMIC_PRODUCTS = 'dynamic_products';

    /**
     * Build full draft order payload structure expected by Shopify.
     *
     * @param Quote $quote
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     * @throws LocalizedException
     */
    public function buildDraftOrderInput(Quote $quote, array $context = []): array
    {
        if (!$quote->hasItems()) {
            throw new LocalizedException(__('Cannot build draft order from empty quote.'));
        }

        $lineItems = $this->buildLineItems($quote, $context);

        $payload = [
            'line_items' => $lineItems,
            'use_customer_default_address' => false,
        ];

        if ($email = $this->resolveEmail($quote)) {
            $payload['customer'] = ['email' => $email];
        }

        if ($quote->getBillingAddress()) {
            $payload['billing_address'] = $this->convertAddress($quote->getBillingAddress());
        }

        if ($quote->getShippingAddress()) {
            $payload['shipping_address'] = $this->convertAddress($quote->getShippingAddress());
        }

        $noteAttributes = [
            [
                'name' => 'magento_quote_id',
                'value' => (string) $quote->getId(),
            ],
            [
                'name' => 'magento_store_id',
                'value' => (string) $quote->getStoreId(),
            ],
        ];

        if ($quote->getCustomerNote()) {
            $payload['note'] = $quote->getCustomerNote();
            $noteAttributes[] = [
                'name' => 'magento_note',
                'value' => (string) $quote->getCustomerNote(),
            ];
        }

        $payload['currency'] = $quote->getQuoteCurrencyCode();
        $payload['note_attributes'] = $noteAttributes;

        return ['draft_order' => $payload];
    }

    /**
     * @param Quote $quote
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    private function buildLineItems(Quote $quote, array $context = []): array
    {
        $items = [];

        $totalItemTax = 0.0;
        $dynamicProducts = [];
        $dynamicProductsBySku = [];

        if (!empty($context[self::CONTEXT_DYNAMIC_PRODUCTS]) && is_array($context[self::CONTEXT_DYNAMIC_PRODUCTS])) {
            $dynamicProducts = $context[self::CONTEXT_DYNAMIC_PRODUCTS];
            foreach ($dynamicProducts as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (isset($entry['sku'])) {
                    $skuKey = (string) $entry['sku'];
                    if ($skuKey !== '') {
                        $dynamicProductsBySku[$skuKey] = $entry;
                    }
                }
            }
        }

        foreach ($quote->getAllVisibleItems() as $item) {
            $quantity = max(1, (int) $item->getQty());
            $rowTotal = (float) $item->getRowTotal();
            $discountAmount = (float) $item->getDiscountAmount();
            $taxAmount = (float) $item->getTaxAmount();

            $unitPriceExclTax = $quantity ? $rowTotal / $quantity : 0.0;
            $perUnitDiscount = $quantity ? $discountAmount / $quantity : 0.0;

            $lineProduct = $dynamicProducts[(int) $item->getItemId()] ?? null;
            if (!$lineProduct) {
                $skuLookup = (string) $item->getSku();
                if ($skuLookup !== '' && isset($dynamicProductsBySku[$skuLookup])) {
                    $lineProduct = $dynamicProductsBySku[$skuLookup];
                }
            }

            $line = [
                'title' => $item->getName(),
                'quantity' => $quantity,
                'price' => $this->formatAmount($unitPriceExclTax),
                'sku' => (string) $item->getSku(),
                'taxable' => $taxAmount > 0.0001,
                'requires_shipping' => $this->itemRequiresShipping($item),
                'tax_lines' => $this->buildTaxLines($taxAmount, (float) $item->getTaxPercent()),
            ];

            if (is_array($lineProduct) && isset($lineProduct['variant_id'], $lineProduct['product_id'])) {
                $line['variant_id'] = (string) $lineProduct['variant_id'];
                $line['product_id'] = (string) $lineProduct['product_id'];
                $line['properties'] = [
                    [
                        'name' => '_magento_origin',
                        'value' => 'dynamic_product',
                    ],
                    [
                        'name' => '_magento_item_id',
                        'value' => (string) $item->getItemId(),
                    ],
                    [
                        'name' => '_magento_sku',
                        'value' => (string) $item->getSku(),
                    ],
                ];
            }

            if ($discountAmount > 0.0001) {
                $line['applied_discount'] = [
                    'title' => (string) __('Discount'),
                    'value_type' => 'fixed_amount',
                    'value' => $this->formatAmount(min($unitPriceExclTax, $perUnitDiscount)),
                    'amount' => $this->formatAmount(min($discountAmount, $rowTotal)),
                ];
            }

            $items[] = $line;

            if ($taxAmount > 0.0001) {
                $totalItemTax += $taxAmount;
            }
        }

        if ($taxLine = $this->buildTaxLine($quote, $totalItemTax)) {
            $items[] = $taxLine;
        }

        return $items;
    }

    /**
     * Resolve an email address to attach to the Shopify draft order payload.
     *
     * @param Quote $quote
     * @return string|null
     */
    private function resolveEmail(Quote $quote): ?string
    {
        if ($quote->getCustomerEmail()) {
            return $quote->getCustomerEmail();
        }

        if ($quote->getBillingAddress() && $quote->getBillingAddress()->getEmail()) {
            return $quote->getBillingAddress()->getEmail();
        }

        return null;
    }

    /**
     * Build line-level tax information to match Magento calculations.
     *
     * @param float $taxAmount
     * @param float $taxPercent
     * @return array<int,array<string,mixed>>
     */
    private function buildTaxLines(float $taxAmount, float $taxPercent): array
    {
        if ($taxAmount <= 0.0001) {
            return [];
        }

        return [[
            'title' => (string) __('Tax'),
            'price' => $this->formatAmount($taxAmount),
            'rate' => $taxPercent ? ($taxPercent / 100) : 0.0,
        ]];
    }

    /**
     * Convert a Magento quote address to Shopify format.
     *
     * @param \Magento\Quote\Model\Quote\Address $address
     * @return array<string,string|null>
     */
    private function convertAddress(\Magento\Quote\Model\Quote\Address $address): array
    {
        return [
            'first_name' => $address->getFirstname(),
            'last_name' => $address->getLastname(),
            'company' => $address->getCompany(),
            'address1' => $address->getStreetLine(1),
            'address2' => $address->getStreetLine(2),
            'city' => $address->getCity(),
            'province' => $address->getRegion(),
            'country_code' => $address->getCountryId(),
            'zip' => $address->getPostcode(),
            'phone' => $address->getTelephone(),
        ];
    }

    /**
     * @param float $amount
     * @return string
     */
    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * @param Quote $quote
     * @param float $totalItemTax
     * @return array<string,mixed>|null
     */
    private function buildTaxLine(Quote $quote, float $totalItemTax): ?array
    {
        $address = $quote->getShippingAddress();

        if (!$address) {
            return null;
        }

        $shippingTax = (float) $address->getShippingTaxAmount();
        $totalTax = $totalItemTax + max(0.0, $shippingTax);

        if ($totalTax <= 0.0001) {
            return null;
        }

        return [
            'title' => (string) __('Tax'),
            'quantity' => 1,
            'price' => $this->formatAmount($totalTax),
            'custom' => true,
            'taxable' => false,
            'requires_shipping' => false,
        ];
    }

    /**
     * @param \Magento\Quote\Model\Quote\Item $item
     * @return bool
     */
    private function itemRequiresShipping(\Magento\Quote\Model\Quote\Item $item): bool
    {
        try {
            if ($item->getProduct()) {
                return !$item->getProduct()->getIsVirtual();
            }
        } catch (\Throwable $exception) {
            // fall back to default behaviour if product instance is not accessible
        }

        return true;
    }
}
