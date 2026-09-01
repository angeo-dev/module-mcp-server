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
        return 'Get the full product card for one SKU from this store: description, attributes, '
            . 'live price and stock, canonical URL, and — for configurable products — the list of '
            . 'purchasable variants with their option values. USE THIS rather than quoting a price '
            . 'or availability from memory or from a web page: only this call reflects what the '
            . 'shopper will actually be charged.';
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
            'url'         => (string) $product->getProductUrl(),
            'image'       => $product->getImage() && $product->getImage() !== 'no_selection'
                ? rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/')
                    . '/catalog/product' . $product->getImage()
                : null,
            'description' => mb_substr(strip_tags((string) $product->getDescription()), 0, 4000),
        ];

        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            $card['variants'] = $this->buildVariants($product, $store);
        }

        return $card;
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
