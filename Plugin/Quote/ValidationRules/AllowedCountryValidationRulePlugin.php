<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Plugin\Quote\ValidationRules;

use Magento\Framework\Validation\ValidationResultFactory;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ValidationRules\AllowedCountryValidationRule;
use WeltPixel\ShopifyCheckout\Model\Webhook\OrderProcessor;

class AllowedCountryValidationRulePlugin
{
    /**
     * Factory used to create empty validation results when Shopify bypass is applied.
     *
     * @var ValidationResultFactory
     */
    private ValidationResultFactory $validationResultFactory;

    /**
     * @param ValidationResultFactory $validationResultFactory
     */
    public function __construct(ValidationResultFactory $validationResultFactory)
    {
        $this->validationResultFactory = $validationResultFactory;
    }

    /**
     * @param AllowedCountryValidationRule $subject
     * @param array<int,\Magento\Framework\Validation\ValidationResult> $results
     * @param Quote $quote
     * @return array<int,\Magento\Framework\Validation\ValidationResult>
     */
    public function afterValidate(
        AllowedCountryValidationRule $subject,
        array $results,
        Quote $quote
    ): array {
        if (!$quote->getData(OrderProcessor::QUOTE_SKIP_ALLOWED_COUNTRY_VALIDATION_FLAG)) {
            return $results;
        }

        foreach ($results as $key => $result) {
            if (!$result->isValid()) {
                $results[$key] = $this->validationResultFactory->create(['errors' => []]);
            }
        }

        return $results;
    }
}
