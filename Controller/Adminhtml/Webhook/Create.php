<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Controller\Adminhtml\Webhook;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\Result\Json;
use WeltPixel\ShopifyCheckout\Model\Webhook\Creator as WebhookCreator;

/**
 * Admin AJAX controller used to create Shopify webhook subscriptions.
 */
class Create extends Action
{
    /**
     * @var WebhookCreator
     */
    private WebhookCreator $webhookCreator;

    /**
     * @param Context $context
     * @param WebhookCreator $webhookCreator
     */
    public function __construct(
        Context $context,
        WebhookCreator $webhookCreator
    ) {
        parent::__construct($context);
        $this->webhookCreator = $webhookCreator;
    }

    /**
     * Create default Shopify webhooks and return status as JSON.
     *
     * @return Json
     */
    public function execute(): Json
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        try {
            $environment = $this->getRequest()->getParam('environment');
            $entries = $this->webhookCreator->ensureDefaultWebhooks($environment ? (string) $environment : null);
            $messages = [];
            foreach ($entries as $entry) {
                if ($entry['status'] === 'exists') {
                    $messages[] = __('Webhook for %1 already exists (ID: %2).', $entry['topic'], $entry['id']);
                } else {
                    $messages[] = __('Webhook for %1 created (ID: %2).', $entry['topic'], $entry['id']);
                }
            }

            return $result->setData([
                'success' => true,
                'message' => implode("\n", array_map('strval', $messages)),
            ]);
        } catch (\Exception $exception) {
            return $result->setHttpResponseCode(500)->setData([
                'success' => false,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('WeltPixel_ShopifyCheckout::config');
    }
}
