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
use Angeo\McpServer\Model\Config;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Store\Api\Data\StoreInterface;

/**
 * search_products — full-text-ish catalog search over the repository layer.
 *
 * Implementation notes:
 *  - goes through ProductRepository + SearchCriteria (NOT raw SQL) so
 *    website assignment, status, and visibility are respected for free;
 *  - "query" maps to a LIKE filter on name — deliberately simple and
 *    index-independent for 1.0; an opt-in Elasticsearch/OpenSearch-backed
 *    implementation can replace this class via di preference later;
 *  - prices are final prices for the configured customer group's storefront
 *    view (default NOT LOGGED IN) — agents see what an anonymous shopper sees.
 *
 * @since 1.0.0
 */
class SearchProductsTool implements ToolInterface, ToolAnnotationsInterface
{
    use ReadOnlyAnnotationsTrait;

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly Config $config
    ) {
    }

    public function getName(): string
    {
        return 'search_products';
    }

    public function getDescription(): string
    {
        return 'Search this store\'s product catalog by keyword, with optional category, price '
            . 'range, pagination and sorting. Returns products with sku, name, current price, '
            . 'stock status and canonical URL, in the store\'s display currency. Use this when no '
            . 'sku is known yet; the sku values it returns are the argument for get_product and '
            . 'add_to_cart. Put a stated category, price floor or ceiling in the matching '
            . 'argument rather than in query.';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query'       => ['type' => 'string', 'description' => 'Keyword(s) to match in product names'],
                'category_id' => ['type' => 'integer', 'description' => 'Restrict to a category (see list_categories)'],
                'price_min'   => ['type' => 'number', 'minimum' => 0],
                'price_max'   => ['type' => 'number', 'minimum' => 0],
                'page'        => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'page_size'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                'sort'        => [
                    'type' => 'string',
                    'enum' => ['relevance', 'price_asc', 'price_desc', 'newest'],
                    'default' => 'relevance',
                ],
            ],
            'required'             => [],
            'additionalProperties' => false,
        ];
    }

    public function isAvailable(StoreInterface $store): bool
    {
        return true; // read-only tool, gated only by the module-level enable flag
    }

    public function execute(array $arguments, StoreInterface $store): array
    {
        $page     = max(1, (int) ($arguments['page'] ?? 1));
        $pageSize = min(
            $this->config->getMaxPageSize($store),
            max(1, (int) ($arguments['page_size'] ?? $this->config->getDefaultPageSize($store)))
        );

        $this->searchCriteriaBuilder
            ->addFilter(ProductInterface::STATUS, Status::STATUS_ENABLED)
            ->addFilter(
                ProductInterface::VISIBILITY,
                [Visibility::VISIBILITY_BOTH, Visibility::VISIBILITY_IN_SEARCH],
                'in'
            )
            ->addFilter('website_id', $store->getWebsiteId());

        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query !== '') {
            if (mb_strlen($query) > 200) {
                throw new \InvalidArgumentException('query must be at most 200 characters');
            }
            $this->searchCriteriaBuilder->addFilter(
                ProductInterface::NAME,
                '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%',
                'like'
            );
        }

        if (isset($arguments['category_id'])) {
            $this->searchCriteriaBuilder->addFilter('category_id', (int) $arguments['category_id']);
        }
        if (isset($arguments['price_min'])) {
            $this->searchCriteriaBuilder->addFilter(ProductInterface::PRICE, (float) $arguments['price_min'], 'gteq');
        }
        if (isset($arguments['price_max'])) {
            $this->searchCriteriaBuilder->addFilter(ProductInterface::PRICE, (float) $arguments['price_max'], 'lteq');
        }

        $sort = (string) ($arguments['sort'] ?? 'relevance');
        if ($sort === 'price_asc' || $sort === 'price_desc') {
            $this->searchCriteriaBuilder->addSortOrder(
                $this->sortOrderBuilder
                    ->setField(ProductInterface::PRICE)
                    ->setDirection($sort === 'price_asc' ? 'ASC' : 'DESC')
                    ->create()
            );
        } elseif ($sort === 'newest') {
            $this->searchCriteriaBuilder->addSortOrder(
                $this->sortOrderBuilder->setField('created_at')->setDirection('DESC')->create()
            );
        }

        $criteria = $this->searchCriteriaBuilder
            ->setCurrentPage($page)
            ->setPageSize($pageSize)
            ->create();

        $result = $this->productRepository->getList($criteria);

        $items = [];
        foreach ($result->getItems() as $product) {
            $items[] = $this->summarize($product, $store);
        }

        return [
            'items'       => $items,
            'total_count' => (int) $result->getTotalCount(),
            'page'        => $page,
            'page_size'   => $pageSize,
            'currency'    => (string) $store->getCurrentCurrencyCode(),
        ];
    }

    /** @param Product|ProductInterface $product */
    private function summarize(ProductInterface $product, StoreInterface $store): array
    {
        $stockStatus = $this->stockRegistry->getStockStatus(
            (int) $product->getId(),
            (int) $store->getWebsiteId()
        );

        return [
            'sku'               => (string) $product->getSku(),
            'name'              => (string) $product->getName(),
            'type'              => (string) $product->getTypeId(),
            'price'             => $product instanceof Product
                ? round((float) $product->getFinalPrice(), 4)
                : (float) $product->getPrice(),
            'in_stock'          => (bool) $stockStatus->getStockStatus(),
            'url'               => $product instanceof Product ? (string) $product->getProductUrl() : null,
            'image'             => $product instanceof Product && $product->getImage() && $product->getImage() !== 'no_selection'
                ? rtrim((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/')
                    . '/catalog/product' . $product->getImage()
                : null,
            'short_description' => $product instanceof Product
                ? mb_substr(strip_tags((string) $product->getShortDescription()), 0, 300)
                : null,
        ];
    }
}
