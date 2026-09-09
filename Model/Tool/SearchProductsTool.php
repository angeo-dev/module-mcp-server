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
use Angeo\McpServer\Model\Catalog\AnchorCategoryResolver;
use Angeo\McpServer\Model\Catalog\CategoryProductCounter;
use Angeo\McpServer\Model\Config;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
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

    /**
     * What a search record never carries, whatever the product. Declared on
     * every response and named in the tool description; get_product is where
     * these live.
     */
    private const OMITTED_FIELDS = ['attributes', 'description', 'variants', 'options', 'categories'];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly AnchorCategoryResolver $anchorCategoryResolver,
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
            . 'range, pagination and sorting. Use this when no sku is known yet. '
            . 'Each result is an abridged record: sku, name, type, current price, stock status, '
            . 'and canonical URL and image where the product has them. Prices are in the store\'s '
            . 'display currency. '
            . 'Results carry no attributes, no full description and no variants — the response '
            . 'lists what it leaves out in omitted_fields. Call get_product with a sku before '
            . 'stating a colour, size, material, capacity or any other characteristic of a '
            . 'product; a field absent from a result is absent from the result, not from the '
            . 'product. Put a stated category, price floor or ceiling in the matching argument '
            . 'rather than in query.';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query'       => ['type' => 'string', 'description' => 'Keyword(s) to match in product names'],
                'category_id' => [
                    'type'        => 'integer',
                    'description' => 'Restrict to a category, including its subcategories where the '
                        . 'store rolls them up (see list_categories).',
                ],
                'price_min'   => [
                    'type'        => 'number',
                    'minimum'     => 0,
                    'description' => 'Lowest acceptable price, in the store\'s display currency.',
                ],
                'price_max'   => [
                    'type'        => 'number',
                    'minimum'     => 0,
                    'description' => 'Price ceiling the shopper stated, in the store\'s display currency.',
                ],
                'page'        => [
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'default'     => 1,
                    'description' => 'Result page to return; omit for the first. Compare with total_count '
                        . 'to see whether more results exist.',
                ],
                'page_size'   => [
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => 50,
                    'description' => 'Results per page; the store\'s configured default applies when omitted '
                        . 'and its configured maximum caps this value.',
                ],
                'sort'        => [
                    'type'        => 'string',
                    'enum'        => ['relevance', 'price_asc', 'price_desc', 'newest'],
                    'default'     => 'relevance',
                    'description' => 'Result order; relevance unless the shopper asked otherwise.',
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
            // Shared with list_categories, so a category count and the search
            // it predicts cannot drift apart.
            ->addFilter(ProductInterface::VISIBILITY, CategoryProductCounter::VISIBILITY_IDS, 'in')
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
            // An anchor category is expanded to its subtree first. The core
            // category_id filter matches direct assignments only, and Magento
            // assigns products to leaf categories, so filtering on a parent id
            // as given returns an empty set for a category whose storefront
            // page is full of products.
            $categoryIds = $this->anchorCategoryResolver->resolve(
                (int) $arguments['category_id'],
                $store
            );
            $this->searchCriteriaBuilder->addFilter(
                'category_id',
                implode(',', $categoryIds),
                'in'
            );
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
            // A search result says what it found; without this it does not say
            // what it never carries. A reader that cannot tell "this tool omits
            // attributes" from "this product has no attributes" will fill the
            // gap from somewhere else — a filename, a sibling product, its own
            // prior knowledge — and present the result as the store's data.
            'omitted_fields' => self::OMITTED_FIELDS,
        ];
    }

    /** @param Product|ProductInterface $product */
    private function summarize(ProductInterface $product, StoreInterface $store): array
    {
        $stockStatus = $this->stockRegistry->getStockStatus(
            (int) $product->getId(),
            (int) $store->getWebsiteId()
        );

        $summary = [
            'sku'      => (string) $product->getSku(),
            'name'     => (string) $product->getName(),
            'type'     => (string) $product->getTypeId(),
            'price'    => $product instanceof Product
                ? round((float) $product->getFinalPrice(), 4)
                : (float) $product->getPrice(),
            'in_stock' => (bool) $stockStatus->getStockStatus(),
        ];

        // Optional fields are omitted rather than sent as null or "". An empty
        // string reads as "the merchant left this blank", which is a claim
        // about the product; leaving the key out claims nothing.
        $url = $product instanceof Product ? trim((string) $product->getProductUrl()) : '';
        if ($url !== '') {
            $summary['url'] = $url;
        }

        $image = $product instanceof Product ? (string) $product->getImage() : '';
        if ($image !== '' && $image !== 'no_selection') {
            $summary['image'] = rtrim(
                (string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA),
                '/'
            ) . '/catalog/product' . $image;
        }

        $shortDescription = $product instanceof Product
            ? trim(mb_substr(strip_tags((string) $product->getShortDescription()), 0, 300))
            : '';
        if ($shortDescription !== '') {
            $summary['short_description'] = $shortDescription;
        }

        return $summary;
    }
}
