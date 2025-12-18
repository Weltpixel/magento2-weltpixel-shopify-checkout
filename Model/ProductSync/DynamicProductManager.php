<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Model\ProductSync;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Psr\Log\LoggerInterface;
use WeltPixel\ShopifyCheckout\Exception\ApiException;
use WeltPixel\ShopifyCheckout\Model\Api\Client;
use WeltPixel\ShopifyCheckout\Model\ProductSync\DynamicProduct;
use WeltPixel\ShopifyCheckout\Model\ProductSync\DynamicProductFactory;
use WeltPixel\ShopifyCheckout\Model\ResourceModel\ProductSync\DynamicProduct as DynamicProductResource;

/**
 * Creates and maintains Shopify products for Magento SKUs when dynamic sync is enabled.
 */
class DynamicProductManager
{
    private const TAG_MAGENTO_DYNAMIC = 'magento-dynamic-product';
    private const TAG_WELTPIXEL = 'weltpixel-shopifycheckout';
    private const MAX_IMAGE_BYTES = 5242880; // 5 MB safety cap

    private Client $client;

    private DynamicProductFactory $dynamicProductFactory;

    private DynamicProductResource $dynamicProductResource;

    private ProductRepositoryInterface $productRepository;

    private ReadInterface $mediaDirectory;

    private File $fileDriver;

    private LoggerInterface $logger;

    /**
     * Cache of prepared products to avoid duplicate API calls within the same request.
     *
     * @var array<string,array<string,string>>
     */
    private array $prepared = [];

    /**
     * Cache of fully loaded Magento products (keyed by store ID and SKU).
     *
     * @var array<string,Product|null>
     */
    private array $productCache = [];

    /**
     * Cache of image payloads keyed by absolute media path.
     *
     * @var array<string,array{attachment:string,filename:string,hash:string}>
     */
    private array $imageCache = [];

    public function __construct(
        Client $client,
        DynamicProductFactory $dynamicProductFactory,
        DynamicProductResource $dynamicProductResource,
        ProductRepositoryInterface $productRepository,
        Filesystem $filesystem,
        File $fileDriver,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->dynamicProductFactory = $dynamicProductFactory;
        $this->dynamicProductResource = $dynamicProductResource;
        $this->productRepository = $productRepository;
        $this->mediaDirectory = $filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $this->fileDriver = $fileDriver;
        $this->logger = $logger;
    }

    /**
     * Ensure Shopify products exist for each visible Magento quote item.
     *
     * @param Quote $quote
     * @param string $environment
     * @return array<int,array<string,string>>
     * @throws ApiException
     */
    public function prepareLineItemProducts(Quote $quote, string $environment): array
    {
        $environment = strtolower($environment);
        $storeId = (int) $quote->getStoreId();
        $lineItems = [];

        foreach ($quote->getAllVisibleItems() as $item) {
            try {
                $productData = $this->ensureProductForItem($item, $environment, $storeId);
            } catch (ApiException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $this->logger->error(
                    'Failed ensuring Shopify product for SKU {sku}: {message}',
                    [
                        'sku' => $item->getSku(),
                        'message' => $exception->getMessage(),
                        'exception' => $exception,
                    ]
                );
                throw new ApiException(__('Unable to prepare Shopify product for SKU %1.', $item->getSku()), $exception);
            }

            if ($productData) {
                $lineItems[(int) $item->getItemId()] = $productData;
            }
        }

        return $lineItems;
    }

    /**
     * Ensure a specific quote item has a Shopify product mapping.
     *
     * @param QuoteItem $item
     * @param string $environment
     * @param int $storeId
     * @return array<string,string>|null
     * @throws ApiException
     */
    private function ensureProductForItem(QuoteItem $item, string $environment, int $storeId): ?array
    {
        $sku = $this->resolveSku($item);
        if ($sku === '') {
            return null;
        }

        $cacheKey = $environment . '|' . $sku;
        if (isset($this->prepared[$cacheKey])) {
            return $this->prepared[$cacheKey];
        }

        $dynamicProduct = $this->dynamicProductFactory->create();
        $this->dynamicProductResource->loadBySkuAndEnvironment($dynamicProduct, $sku, $environment);

        $descriptor = $this->buildDescriptor($item, $sku);
        $hash = $descriptor['hash'];

        if ($dynamicProduct->getId()) {
            if ($dynamicProduct->getSyncHash() !== $hash) {
                $this->updateRemoteProduct($dynamicProduct, $descriptor, $environment, $storeId);
                $dynamicProduct->setSyncHash($hash);
                $this->dynamicProductResource->save($dynamicProduct);
            }
        } else {
            $dynamicProduct->setSku($sku);
            $dynamicProduct->setEnvironment($environment);
            $dynamicProduct->setSyncHash($hash);
            $dynamicProduct->setIsArchived(false);

            $this->createRemoteProduct($dynamicProduct, $descriptor, $environment, $storeId);
            $this->dynamicProductResource->save($dynamicProduct);
        }

        $this->prepared[$cacheKey] = [
            'product_id' => $dynamicProduct->getShopifyProductId(),
            'variant_id' => $dynamicProduct->getShopifyVariantId(),
            'sku' => $sku,
        ];

        return $this->prepared[$cacheKey];
    }

    /**
     * Create a new Shopify product/variant for the given descriptor.
     *
     * @param DynamicProduct $entity
     * @param array<string,mixed> $descriptor
     * @param string $environment
     * @param int $storeId
     * @return void
     * @throws ApiException
     */
    private function createRemoteProduct(
        DynamicProduct $entity,
        array $descriptor,
        string $environment,
        int $storeId
    ): void {
        $productPayload = $descriptor['product'];
        $productPayload['variants'] = [$descriptor['variant']];

        if (!empty($descriptor['images'])) {
            $productPayload['images'] = $descriptor['images'];
        }

        $response = $this->client->callAdmin(
            'POST',
            'products.json',
            ['product' => $productPayload],
            [],
            null,
            $environment,
            (string) $storeId
        );

        $product = $response['body']['product'] ?? null;

        if (!$product || !isset($product['id'], $product['variants'][0]['id'])) {
            $this->logger->error(
                'Unexpected Shopify response when creating product',
                ['response' => $response['body'] ?? []]
            );
            throw new ApiException(__('Shopify response missing product data.'));
        }

        $entity->setShopifyProductId((string) $product['id']);
        $entity->setShopifyVariantId((string) $product['variants'][0]['id']);

        $imageId = null;
        if (isset($product['image']['id'])) {
            $imageId = (string) $product['image']['id'];
        } elseif (!empty($product['images'][0]['id'])) {
            $imageId = (string) $product['images'][0]['id'];
        }

        if (!$imageId && !empty($descriptor['images'])) {
            $imageId = $this->syncProductImage($entity, $descriptor, $environment, $storeId);
        }

        if ($imageId) {
            $entity->setShopifyImageId($imageId);
            $this->assignVariantImage($entity, $imageId, $environment, $storeId);
        }
    }

    /**
     * Update an existing Shopify product when its descriptor changed.
     *
     * @param DynamicProduct $entity
     * @param array<string,mixed> $descriptor
     * @param string $environment
     * @param int $storeId
     * @return void
     * @throws ApiException
     */
    private function updateRemoteProduct(
        DynamicProduct $entity,
        array $descriptor,
        string $environment,
        int $storeId
    ): void {
        $productPayload = $descriptor['product'] + ['id' => $entity->getShopifyProductId()];

        $this->client->callAdmin(
            'PUT',
            'products/' . $entity->getShopifyProductId() . '.json',
            ['product' => $productPayload],
            [],
            null,
            $environment,
            (string) $storeId
        );

        $imageId = null;
        if (!empty($descriptor['images'])) {
            $imageId = $this->syncProductImage($entity, $descriptor, $environment, $storeId);
        }

        $variantPayload = $descriptor['variant'] + ['id' => $entity->getShopifyVariantId()];
        if ($imageId) {
            $variantPayload['image_id'] = $imageId;
        }

        $this->client->callAdmin(
            'PUT',
            'variants/' . $entity->getShopifyVariantId() . '.json',
            ['variant' => $variantPayload],
            [],
            null,
            $environment,
            (string) $storeId
        );

        if ($imageId) {
            $this->assignVariantImage($entity, $imageId, $environment, $storeId);
        }

    }

    /**
     * Build the payload descriptor for a Magento quote item.
     *
     * @param QuoteItem $item
     * @param string $sku
     * @return array<string,mixed>
     */
    private function buildDescriptor(QuoteItem $item, string $sku): array
    {
        $parentProduct = $item->getProduct() instanceof Product ? $item->getProduct() : null;
        $product = $this->resolveProduct($item);

        $title = $item->getName() ?: ($product ? (string) $product->getName() : $sku);
        $quantity = max(1, (int) $item->getQty());
        $rowTotal = (float) $item->getRowTotal();
        $taxAmount = (float) $item->getTaxAmount();

        $unitPrice = $quantity > 0 ? $rowTotal / $quantity : 0.0;
        $taxable = $taxAmount > 0.0001;
        $requiresShipping = $this->requiresShipping($item);

        $tags = [
            self::TAG_MAGENTO_DYNAMIC,
            self::TAG_WELTPIXEL,
        ];

        if ($product && $product->getSku() && $product->getSku() !== $sku) {
            $tags[] = 'parent-sku-' . $product->getSku();
        }

        $bodyHtml = '';
        if ($product) {
            $bodyHtml = (string) $product->getDescription();
            if ($bodyHtml === '') {
                $bodyHtml = (string) $product->getShortDescription();
            }
        }

        $vendor = 'Magento';
        if ($product) {
            $manufacturer = $this->getAttributeTextSafe($product, 'manufacturer');
            $brand = $this->getAttributeTextSafe($product, 'brand');
            $vendorAttr = $this->getAttributeTextSafe($product, 'vendor');

            if ($manufacturer !== null) {
                $vendor = $manufacturer;
            } elseif ($brand !== null) {
                $vendor = $brand;
            } elseif ($vendorAttr !== null) {
                $vendor = $vendorAttr;
            }
        }

        $descriptor = [
            'product' => [
                'title' => $title,
                'body_html' => $bodyHtml,
                'vendor' => $vendor,
                'product_type' => 'Magento Dynamic Product',
                'status' => 'draft',
                'tags' => implode(',', $tags),
            ],
            'variant' => [
                'sku' => $sku,
                'price' => $this->formatAmount($unitPrice),
                'taxable' => $taxable,
                'requires_shipping' => $requiresShipping,
            ],
            'images' => [],
        ];

        $storeId = (int) ($item->getStoreId() ?: ($item->getQuote() ? $item->getQuote()->getStoreId() : 0));
        $primaryImageHash = '';

        if ($product || $parentProduct) {
            $imageAttachment = $this->resolveImageAttachment($product, $parentProduct, $storeId, $title);
            if ($imageAttachment) {
                $primaryImageHash = $imageAttachment['hash'];
                $descriptor['images'][] = $imageAttachment['payload'];
            }
        }

        $hashSource = [
            $descriptor['product']['title'],
            $descriptor['product']['body_html'],
            $descriptor['product']['vendor'],
            $descriptor['product']['product_type'],
            $descriptor['product']['status'],
            $descriptor['product']['tags'],
            $descriptor['variant']['sku'],
            $descriptor['variant']['price'],
            $descriptor['variant']['taxable'] ? '1' : '0',
            $descriptor['variant']['requires_shipping'] ? '1' : '0',
            $primaryImageHash,
        ];

        $descriptor['hash'] = hash('sha256', implode('|', $hashSource));

        return $descriptor;
    }

    /**
     * Resolve the SKU to use when syncing the product (prefers simple product SKU).
     *
     * @param QuoteItem $item
     * @return string
     */
    private function resolveSku(QuoteItem $item): string
    {
        $sku = (string) $item->getSku();

        $simpleProductOption = $item->getOptionByCode('simple_product');
        if ($simpleProductOption && $simpleProductOption->getProduct()
            && $simpleProductOption->getProduct()->getSku()
        ) {
            $sku = (string) $simpleProductOption->getProduct()->getSku();
        }

        return trim($sku);
    }

    /**
     * Resolve the Magento product instance associated with the quote item.
     *
     * @param QuoteItem $item
     * @return Product|null
     */
    private function resolveProduct(QuoteItem $item): ?Product
    {
        $product = $item->getProduct();

        $simpleProductOption = $item->getOptionByCode('simple_product');
        if ($simpleProductOption && $simpleProductOption->getProduct()) {
            $product = $simpleProductOption->getProduct();
        }

        if ($product instanceof Product && $product->getId()) {
            return $product;
        }

        try {
            return $this->productRepository->get($item->getSku());
        } catch (NoSuchEntityException $exception) {
            return null;
        }
    }

    /**
     * Determine whether the item requires shipping.
     *
     * @param QuoteItem $item
     * @return bool
     */
    private function requiresShipping(QuoteItem $item): bool
    {
        try {
            $product = $this->resolveProduct($item);
            if ($product) {
                return !$product->getIsVirtual();
            }
        } catch (\Throwable $exception) {
            // ignore; fallback below
        }

        return true;
    }

    /**
     * Resolve the most appropriate Magento product image and return Shopify attachment payload + hash.
     *
     * @param Product|null $product
     * @param Product|null $fallbackProduct
     * @param int $storeId
     * @param string $title
     * @return array{payload:array<string,string>,hash:string}|null
     */
    private function resolveImageAttachment(
        ?Product $product,
        ?Product $fallbackProduct,
        int $storeId,
        string $title
    ): ?array {
        $candidates = [];
        if ($product) {
            $candidates[] = $product;
        }

        if ($fallbackProduct && (!$product || (int) $fallbackProduct->getId() !== (int) $product->getId())) {
            $candidates[] = $fallbackProduct;
        }

        foreach ($candidates as $candidate) {
            $imagePath = $this->extractPrimaryImagePath($candidate);

            if (!$imagePath) {
                $sku = (string) $candidate->getSku();
                if ($sku !== '') {
                    $reloaded = $this->loadProductBySku($sku, $storeId);
                    if ($reloaded) {
                        $imagePath = $this->extractPrimaryImagePath($reloaded);
                    }
                }
            }

            if (!$imagePath) {
                continue;
            }

            $absolutePath = $this->getAbsoluteImagePath($imagePath);

            if (!$absolutePath || !$this->fileDriver->isExists($absolutePath)) {
                continue;
            }

            $cacheKey = $absolutePath;

            if (isset($this->imageCache[$cacheKey])) {
                $cached = $this->imageCache[$cacheKey];

                return [
                    'payload' => [
                        'attachment' => $cached['attachment'],
                        'filename' => $cached['filename'],
                        'alt' => $title,
                    ],
                    'hash' => $cached['hash'],
                ];
            }

            $fileSize = 0;
            try {
                $stat = $this->fileDriver->stat($absolutePath);
                if (is_array($stat) && isset($stat['size'])) {
                    $fileSize = (int) $stat['size'];
                }
            } catch (\Throwable $exception) {
                $this->logger->warning(
                    'Unable to stat Magento product image for Shopify sync',
                    [
                        'path' => $absolutePath,
                        'exception' => $exception,
                    ]
                );
            }

            if ($fileSize > self::MAX_IMAGE_BYTES) {
                $this->logger->warning(
                    'Magento product image exceeds Shopify sync size cap; skipping attachment.',
                    [
                        'path' => $absolutePath,
                        'size_bytes' => $fileSize,
                        'limit_bytes' => self::MAX_IMAGE_BYTES,
                    ]
                );
                continue;
            }

            try {
                $contents = $this->fileDriver->fileGetContents($absolutePath);
            } catch (\Throwable $exception) {
                $this->logger->warning(
                    'Unable to read Magento product image for Shopify sync',
                    [
                        'path' => $absolutePath,
                        'exception' => $exception,
                    ]
                );
                continue;
            }

            if ($contents === false || $contents === '') {
                continue;
            }

            $encoded = base64_encode($contents);
            $filename = $this->buildImageFilename($imagePath);
            $hash = sha1($contents);

            $this->imageCache[$cacheKey] = [
                'attachment' => $encoded,
                'filename' => $filename,
                'hash' => $hash,
            ];

            $payload = [
                'attachment' => $encoded,
                'filename' => $filename,
                'alt' => $title,
            ];

            return [
                'payload' => $payload,
                'hash' => $hash,
            ];
        }

        return null;
    }

    /**
     * Attempt to extract the most suitable image path from a Magento product.
     *
     * @param Product $product
     * @return string|null
     */
    private function extractPrimaryImagePath(Product $product): ?string
    {
        foreach (['small_image', 'thumbnail', 'image'] as $attributeCode) {
            $value = (string) $product->getData($attributeCode);
            if ($value && $value !== 'no_selection') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Build absolute filesystem path for a catalog product image.
     *
     * @param string $imagePath
     * @return string|null
     */
    private function getAbsoluteImagePath(string $imagePath): ?string
    {
        $normalized = ltrim($imagePath, '/');

        if ($normalized === '') {
            return null;
        }

        if (strpos($normalized, 'catalog/product') !== 0) {
            $normalized = 'catalog/product/' . $normalized;
        }

        return $this->mediaDirectory->getAbsolutePath($normalized);
    }

    /**
     * Derive a filename for Shopify uploads from Magento image path.
     *
     * @param string $imagePath
     * @return string
     */
    private function buildImageFilename(string $imagePath): string
    {
        $basename = basename($imagePath);

        if ($basename === '' || $basename === '.' || $basename === '..') {
            return 'magento-product-' . md5($imagePath) . '.jpg';
        }

        return $basename;
    }

    /**
     * Format a monetary amount for Shopify payloads.
     *
     * @param float $amount
     * @return string
     */
    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Safely read text value for a product EAV attribute without triggering core errors.
     *
     * @param Product $product
     * @param string $attributeCode
     * @return string|null
     */
    private function getAttributeTextSafe(Product $product, string $attributeCode): ?string
    {
        try {
            $attribute = $product->getResource()->getAttribute($attributeCode);
            if (!$attribute) {
                return null;
            }

            $value = $attribute->getFrontend()->getValue($product);
        } catch (\Throwable $exception) {
            return null;
        }

        if (is_array($value)) {
            $value = implode(', ', array_filter(array_map('trim', $value)));
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed !== '' ? $trimmed : null;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * Ensure Shopify product images reflect the descriptor data and return the active image ID.
     *
     * @param DynamicProduct $entity
     * @param array<string,mixed> $descriptor
     * @param string $environment
     * @param int $storeId
     * @return string|null
     * @throws ApiException
     */
    private function syncProductImage(
        DynamicProduct $entity,
        array $descriptor,
        string $environment,
        int $storeId
    ): ?string {
        $images = $descriptor['images'] ?? [];
        $imagePayload = $images[0] ?? null;

        if (!is_array($imagePayload) || empty($imagePayload['attachment'])) {
            return null;
        }

        $basePayload = [
            'attachment' => $imagePayload['attachment'],
        ];

        if (!empty($imagePayload['filename'])) {
            $basePayload['filename'] = $imagePayload['filename'];
        }

        if (!empty($imagePayload['alt'])) {
            $basePayload['alt'] = $imagePayload['alt'];
        }

        $imageId = $entity->getShopifyImageId();
        if ($imageId) {
            try {
                $updatePayload = $basePayload;
                $updatePayload['id'] = $imageId;

                $response = $this->client->callAdmin(
                    'PUT',
                    sprintf('products/%s/images/%s.json', $entity->getShopifyProductId(), $imageId),
                    ['image' => $updatePayload],
                    [],
                    null,
                    $environment,
                    (string) $storeId
                );

                $image = $response['body']['image'] ?? null;
                if ($image && isset($image['id'])) {
                    $entity->setShopifyImageId((string) $image['id']);
                    return (string) $image['id'];
                }
            } catch (ApiException $exception) {
                $this->logger->warning(
                    'Unable to update Shopify product image, attempting re-upload',
                    [
                        'product_id' => $entity->getShopifyProductId(),
                        'image_id' => $imageId,
                        'filename' => $basePayload['filename'] ?? null,
                        'exception' => $exception,
                    ]
                );
            }
        }

        $response = $this->client->callAdmin(
            'POST',
            sprintf('products/%s/images.json', $entity->getShopifyProductId()),
            ['image' => $basePayload],
            [],
            null,
            $environment,
            (string) $storeId
        );

        $image = $response['body']['image'] ?? null;

        if (!$image || !isset($image['id'])) {
            throw new ApiException(__('Shopify response missing product image data.'));
        }

        $entity->setShopifyImageId((string) $image['id']);

        return (string) $image['id'];
    }

    /**
     * Assign an uploaded Shopify product image to the mapped variant.
     *
     * @param DynamicProduct $entity
     * @param string $imageId
     * @param string $environment
     * @param int $storeId
     * @return void
     */
    private function assignVariantImage(
        DynamicProduct $entity,
        string $imageId,
        string $environment,
        int $storeId
    ): void {
        if ($imageId === '' || !$entity->getShopifyVariantId()) {
            return;
        }

        $payload = [
            'variant' => [
                'id' => $entity->getShopifyVariantId(),
                'image_id' => $imageId,
            ],
        ];

        try {
            $this->client->callAdmin(
                'PUT',
                'variants/' . $entity->getShopifyVariantId() . '.json',
                $payload,
                [],
                null,
                $environment,
                (string) $storeId
            );
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'Unable to assign Shopify image to variant',
                [
                    'variant_id' => $entity->getShopifyVariantId(),
                    'image_id' => $imageId,
                    'exception' => $exception,
                ]
            );
        }
    }

    /**
     * Load a Magento product by SKU with caching to ensure media attributes are available.
     *
     * @param string $sku
     * @param int $storeId
     * @return Product|null
     */
    private function loadProductBySku(string $sku, int $storeId): ?Product
    {
        $cacheKey = $storeId . '|' . strtolower($sku);

        if (array_key_exists($cacheKey, $this->productCache)) {
            return $this->productCache[$cacheKey];
        }

        try {
            $product = $this->productRepository->get($sku, false, $storeId);
        } catch (NoSuchEntityException $exception) {
            $this->productCache[$cacheKey] = null;
            return null;
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'Unable to load Magento product for image sync',
                ['sku' => $sku, 'store_id' => $storeId, 'exception' => $exception]
            );
            $this->productCache[$cacheKey] = null;
            return null;
        }

        $this->productCache[$cacheKey] = $product;

        return $product;
    }
}
