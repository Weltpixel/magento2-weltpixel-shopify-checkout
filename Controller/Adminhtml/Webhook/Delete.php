<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Controller\Adminhtml\Webhook;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\Result\Json;
use WeltPixel\ShopifyCheckout\Model\Webhook\Creator as WebhookCreator;

/**
 * Admin AJAX controller used to remove Shopify webhook subscriptions.
 */
class Delete extends Action
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
     * Delete configured Shopify webhooks and respond with status JSON.
     *
     * @return Json
     */
    public function execute(): Json
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        try {
            $environment = $this->getRequest()->getParam('environment');
            $entries = $this->webhookCreator->deleteDefaultWebhooks($environment ? (string) $environment : null);
            $messages = [];
            foreach ($entries as $entry) {
                if ($entry['status'] === 'deleted') {
                    $messages[] = __('Webhook for %1 deleted.', $entry['topic']);
                } elseif ($entry['status'] === 'missing') {
                    $messages[] = __('No webhook found for %1.', $entry['topic']);
                } elseif ($entry['status'] === 'error') {
                    $messages[] = __('Failed to delete webhook for %1: %2', $entry['topic'], $entry['message'] ?? '');
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
