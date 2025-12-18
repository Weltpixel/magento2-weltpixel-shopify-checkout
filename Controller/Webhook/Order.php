<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Controller\Webhook;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;
use WeltPixel\ShopifyCheckout\Model\Config;
use WeltPixel\ShopifyCheckout\Model\Webhook\HmacValidator;
use WeltPixel\ShopifyCheckout\Model\Webhook\OrderProcessor;

/**
 * Shopify webhook endpoint that validates and processes order events.
 */
class Order extends Action implements CsrfAwareActionInterface
{
    /**
     * @var JsonSerializer
     */
    private JsonSerializer $jsonSerializer;

    /**
     * @var HmacValidator
     */
    private HmacValidator $hmacValidator;

    /**
     * @var OrderProcessor
     */
    private OrderProcessor $orderProcessor;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @param Context $context
     * @param JsonSerializer $jsonSerializer
     * @param HmacValidator $hmacValidator
     * @param OrderProcessor $orderProcessor
     * @param LoggerInterface $logger
     * @param Config $config
     */
    public function __construct(
        Context $context,
        JsonSerializer $jsonSerializer,
        HmacValidator $hmacValidator,
        OrderProcessor $orderProcessor,
        LoggerInterface $logger,
        Config $config
    ) {
        parent::__construct($context);
        $this->jsonSerializer = $jsonSerializer;
        $this->hmacValidator = $hmacValidator;
        $this->orderProcessor = $orderProcessor;
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * Handle incoming Shopify webhook payload and trigger order processing.
     *
     * @return ResponseInterface
     */
    public function execute(): ResponseInterface
    {
        $request = $this->getRequest();
        if (strtoupper($request->getMethod()) !== 'POST') {
            return $this->methodNotAllowedResponse();
        }

        $payload = (string) $request->getContent();
        $headers = $request->getHeaders()->toArray();

        if (!$this->hmacValidator->isValid($payload, $headers)) {
            return $this->unauthorizedResponse();
        }

        $shopDomain = $request->getHeader('X-Shopify-Shop-Domain');
        if ($shopDomain) {
            $shopDomain = strtolower(trim($shopDomain));
        }

        if (!$shopDomain || !$this->isKnownShopifyDomain($shopDomain)) {
            return $this->unauthorizedResponse();
        }

        try {
            $data = $this->jsonSerializer->unserialize($payload);
        } catch (\InvalidArgumentException $exception) {
            return $this->badRequestResponse('Invalid JSON payload.');
        }

        if (!is_array($data)) {
            return $this->badRequestResponse('Unexpected payload format.');
        }

        try {
            $this->orderProcessor->process($data);
        } catch (LocalizedException $exception) {
            $this->logger->error(
                'Shopify webhook processing failed: {message}',
                [
                    'message' => $exception->getMessage(),
                    'shopify_order_id' => $data['id'] ?? null,
                    'event_id' => $request->getHeader('X-Shopify-Event-Id'),
                ]
            );
            return $this->badRequestResponse($exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->critical(
                'Unhandled Shopify webhook error: {message}',
                [
                    'message' => $exception->getMessage(),
                    'shopify_order_id' => $data['id'] ?? null,
                    'event_id' => $request->getHeader('X-Shopify-Event-Id'),
                ]
            );
            return $this->serverErrorResponse();
        }

        /** @var HttpResponse $response */
        $response = $this->getResponse();
        $response->setHttpResponseCode(200);
        $response->setBody('ok');

        return $response;
    }

    /** @inheritDoc */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /** @inheritDoc */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Build a 401 unauthorized response.
     *
     * @return ResponseInterface
     */
    private function unauthorizedResponse(): ResponseInterface
    {
        /** @var HttpResponse $response */
        $response = $this->getResponse();
        $response->setHttpResponseCode(401);
        $response->setBody('unauthorized');

        return $response;
    }

    /**
     * Build a 400 bad-request response.
     *
     * @param string $message
     * @return ResponseInterface
     */
    private function badRequestResponse(string $message): ResponseInterface
    {
        /** @var HttpResponse $response */
        $response = $this->getResponse();
        $response->setHttpResponseCode(400);
        $response->setBody($message);

        return $response;
    }

    /**
     * Build a 500 server-error response.
     *
     * @return ResponseInterface
     */
    private function serverErrorResponse(): ResponseInterface
    {
        /** @var HttpResponse $response */
        $response = $this->getResponse();
        $response->setHttpResponseCode(500);
        $response->setBody('error');

        return $response;
    }

    /**
     * Build a 405 method-not-allowed response.
     *
     * @return ResponseInterface
     */
    private function methodNotAllowedResponse(): ResponseInterface
    {
        /** @var HttpResponse $response */
        $response = $this->getResponse();
        $response->setHttpResponseCode(405);
        $response->setBody('method_not_allowed');

        return $response;
    }

    /**
     * Ensure the Shopify shop domain matches configured domains.
     *
     * @param string $domain
     * @return bool
     */
    private function isKnownShopifyDomain(string $domain): bool
    {
        $allowed = $this->config->getAllStoreDomains();
        if (empty($allowed)) {
            return true;
        }

        return in_array($domain, $allowed, true);
    }
}
