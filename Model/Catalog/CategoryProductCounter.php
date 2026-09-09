<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Catalog;

use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * How many products a shopper would find in a category — one definition,
 * applied to every category alike.
 *
 * Magento's own rollup, Category\Collection::loadProductCount(), answers this
 * two different ways in one pass. Anchor categories are counted from the
 * category-product index with a visibility filter. Non-anchor categories are
 * counted as raw rows in catalog_category_product, with no visibility filter
 * at all — so their number includes every size and colour variant that is not
 * individually visible anywhere.
 *
 * For a person reading the admin grid, the difference is a curiosity. For an
 * agent it is a broken comparison: two numbers under one key name, one meaning
 * "products you can browse" and the other "assignment rows", with nothing in
 * the payload to tell them apart. A category listing 48 next to a sibling
 * listing 12 reads as four times the choice, and may be less.
 *
 * This counts every category from the store's category-product index, which
 * already holds the anchor rollup, under the visibility set that
 * search_products filters on. A count therefore predicts what a search in that
 * category returns, which is the next call the agent makes.
 *
 * @since 2.2.1
 */
class CategoryProductCounter
{
    /**
     * The same visibility set search_products filters on. Shared so a count
     * and the search it predicts cannot drift apart.
     */
    public const VISIBILITY_IDS = [
        Visibility::VISIBILITY_BOTH,
        Visibility::VISIBILITY_IN_SEARCH,
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly TableMaintainer $tableMaintainer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Counts keyed by category id. Ids with no visible products are absent
     * from the result rather than present as zero; the caller decides what an
     * absent count means.
     *
     * Returns null — not an empty array — when the index cannot be read at
     * all, most often a store whose category-product indexer has never run.
     * An empty array is a real answer meaning no category in the set has a
     * visible product; null means ask someone else. Collapsing the two would
     * make a whole level of empty categories look like a broken index and
     * send the caller back to a rollup with different semantics.
     *
     * @param int[] $categoryIds
     * @return array<int, int>|null
     */
    public function countFor(array $categoryIds, int $storeId): ?array
    {
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        if ($categoryIds === []) {
            return [];
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $table      = $this->tableMaintainer->getMainTable($storeId);

            $select = $connection->select()
                ->from(
                    ['cat_index' => $table],
                    [
                        'category_id' => 'cat_index.category_id',
                        'count'       => new \Zend_Db_Expr('COUNT(DISTINCT cat_index.product_id)'),
                    ]
                )
                ->where('cat_index.category_id IN (?)', $categoryIds)
                ->where('cat_index.visibility IN (?)', self::VISIBILITY_IDS)
                ->group('cat_index.category_id');

            return array_map('intval', $connection->fetchPairs($select));
        } catch (\Throwable $e) {
            // A missing or unbuilt index is a store state, not a request
            // error. Reporting zero products for a full catalog is worse than
            // letting the caller fall back to Magento's own rollup.
            $this->logger->warning(
                'Angeo_McpServer: category product count unavailable, falling back. '
                . $e->getMessage()
            );
            return null;
        }
    }
}
