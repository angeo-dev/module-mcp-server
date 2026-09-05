<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Tool;

use Angeo\McpServer\Api\ToolAnnotationsInterface;
use Angeo\McpServer\Api\ToolInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;

/**
 * get_product — full live product card by SKU.
 *
 * For configurable products the response includes purchasable variants with
 * their option values (the exact data an agent needs to say "available in
 * red, size M, €39.90, in stock").
 *
 * @since 1.0.0
 */
class GetProductTool implements ToolInterface, ToolAnnotationsInterface
{
    use ReadOnlyAnnotationsTrait;

    private const MAX_VARIANTS = 100;
    
    /** Cap on published attributes; a card is a summary, not an EAV dump. */
    private const MAX_ATTRIBUTES = 40;

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly Configurable $configurableType
    ) {
    }

    public function getName(): string
    {
        return 'get_product';
    }

    public function getDescription(): string
    {
        return 'Get the full product card for one sku in this store: description, current price '
            . 'and stock, canonical URL, the storefront attributes the merchant filled in, and — '
            . 'for configurable products — the purchasable variants with their option values. '
            . 'Requires an exact sku, so call search_products first when only a description of '
            . 'the item is known. The price it returns is the one the shopper is charged. '
            . 'The attributes block is what this merchant maintains, not a full specification: '
            . 'an attribute missing from it is unrecorded in the catalog, which is not the same '
            . 'as the product lacking that feature. Report it as not listed rather than as '
            . 'absent, and never infer one from an image filename or a similar product.';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'sku' => ['type' => 'string', 'description' => 'Product SKU (exact match)'],
            ],
            'required'             => ['sku'],
            'additionalProperties' => false,
        ];
    }

    public function isAvailable(StoreInterface $store): bool
    {
        return true;
    }

    public function execute(array $arguments, StoreInterface $store): array
    {
        $sku = trim((string) ($arguments['sku'] ?? ''));
        if ($sku === '' || mb_strlen($sku) > 64) {
            throw new \InvalidArgumentException('sku is required and must be at most 64 characters');
        }

        try {
            /** @var Product $product */
            $product = $this->productRepository->get($sku, false, $store->getId());
        } catch (NoSuchEntityException) {
            throw new \InvalidArgumentException(sprintf('Product not found: %s', $sku));
        }

        if ((int) $product->getStatus() !== Status::STATUS_ENABLED
            || !in_array($store->getWebsiteId(), $product->getWebsiteIds() ?? [], false)
        ) {
            // Disabled / other-website products must be indistinguishable from absent.
            throw new \InvalidArgumentException(sprintf('Product not found: %s', $sku));
        }

        $stockStatus = $this->stockRegistry->getStockStatus(
            (int) $product->getId(),
            (int) $store->getWebsiteId()
        );

        $card = [
            'sku'         => (string) $product->getSku(),
            'name'        => (string) $product->getName(),
            'type'        => (string) $product->getTypeId(),
            'price'       => round((float) $product->getFinalPrice(), 4),
            'regular_price' => round((float) $product->getPrice(), 4),
            'currency'    => (string) $store->getCurrentCurrencyCode(),
            'in_stock'    => (bool) $stockStatus->getStockStatus(),
        ];

        // Optional fields are omitted rather than sent as null or "". A null
        // reads as a claim about the product; an absent key claims nothing.
        $url = trim((string) $product->getProductUrl());
        if ($url !== '') {
            $card['url'] = $url;
        }

        $image = (string) $product->getImage();
        if ($image !== '' && $image !== 'no_selection') {
            $card['image'] = rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/')
                . '/catalog/product' . $image;
        }

        $description = trim(mb_substr(strip_tags((string) $product->getDescription()), 0, 4000));
        if ($description !== '') {
            $card['description'] = $description;
        }

        $attributes = $this->collectAttributes($product);
        if ($attributes !== []) {
            $card['attributes'] = $attributes;
        }

        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            $card['variants'] = $this->buildVariants($product, $store);
        }

        return $card;
    }

    /**
     * The storefront attributes this merchant actually maintains.
     *
     * Scoped to what the merchant marked visible on the product page, which is
     * the same set a shopper sees, and to values that are actually filled in.
     * The alternative — every EAV attribute, empty ones included — would be a
     * wall of nulls that reads as a specification and is not one.
     *
     * Fields already on the card in their own right are skipped so the same
     * fact is not published twice under two names.
     *
     * @return array<string, string> attribute label => rendered value
     */
    private function collectAttributes(Product $product): array
    {
        $skip = [
            'name', 'sku', 'price', 'special_price', 'cost', 'url_key', 'status',
            'visibility', 'description', 'short_description', 'image', 'small_image',
            'thumbnail', 'media_gallery', 'tier_price', 'category_ids', 'quantity_and_stock_status',
        ];

        $attributes = [];
        foreach ($product->getAttributes() as $attribute) {
            if (count($attributes) >= self::MAX_ATTRIBUTES) {
                break;
            }

            $code = (string) $attribute->getAttributeCode();
            if (in_array($code, $skip, true) || !$attribute->getIsVisibleOnFront()) {
                continue;
            }

            // Read the stored value first. A boolean the merchant never set
            // renders as "No" through the frontend model, which would publish
            // "this product does not have the feature" when the truth is that
            // nobody recorded anything. Unset is unset, whatever the input type.
            $raw = $product->getData($code);
            if ($raw === null || $raw === '' || $raw === []) {
                continue;
            }

            try {
                $value = $attribute->getFrontend()->getValue($product);
            } catch (\Throwable) {
                // A broken source model on one attribute must not cost the
                // whole card.
                continue;
            }

            if (is_array($value)) {
                $value = implode(', ', array_filter(array_map('strval', $value)));
            }

            $value = trim(strip_tags((string) $value));
            if ($value === '') {
                continue;
            }

            $label = trim((string) $attribute->getStoreLabel()) ?: $code;
            $attributes[$label] = mb_substr($value, 0, 500);
        }

        return $attributes;
    }

    private function buildVariants(Product $product, StoreInterface $store): array
    {
        $attributes = [];
        foreach ($this->configurableType->getConfigurableAttributes($product) as $attribute) {
            $productAttribute = $attribute->getProductAttribute();
            if ($productAttribute) {
                $attributes[$productAttribute->getAttributeCode()] = $productAttribute;
            }
        }

        $variants = [];
        foreach ($this->configurableType->getUsedProducts($product) as $child) {
            if (count($variants) >= self::MAX_VARIANTS) {
                break;
            }
            if ((int) $child->getStatus() !== Status::STATUS_ENABLED) {
                continue;
            }

            $options = [];
            foreach ($attributes as $code => $productAttribute) {
                $options[$code] = (string) $productAttribute->getSource()
                    ->getOptionText($child->getData($code));
            }

            $childStock = $this->stockRegistry->getStockStatus(
                (int) $child->getId(),
                (int) $store->getWebsiteId()
            );

            $variants[] = [
                'sku'      => (string) $child->getSku(),
                'options'  => $options,
                'price'    => round((float) $child->getFinalPrice(), 4),
                'in_stock' => (bool) $childStock->getStockStatus(),
            ];
        }

        return $variants;
    }
}
