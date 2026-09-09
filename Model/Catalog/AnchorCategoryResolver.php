<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Catalog;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Expands a category id into the set of ids a shopper's own browse would cover.
 *
 * Magento assigns products to leaf categories and relies on the `is_anchor`
 * flag to roll them up: a shopper opening an anchor category sees everything
 * in its subtree, not the (usually empty) set assigned to it directly. The
 * search-criteria filter `category_id` does not do this — it resolves against
 * `catalog_category_product`, which holds direct assignments only.
 *
 * Left alone, that difference is invisible to a merchant and total for an
 * agent: the storefront category page lists two hundred products and the same
 * category over the API returns none. This class closes it.
 *
 * @since 2.2.0
 */
class AnchorCategoryResolver
{
    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository
    ) {
    }

    /**
     * The category itself, plus every active descendant when it is an anchor.
     *
     * @return int[] Non-empty; always contains the requested id.
     * @throws \InvalidArgumentException When the category does not exist in this store.
     */
    public function resolve(int $categoryId, StoreInterface $store): array
    {
        try {
            $category = $this->categoryRepository->get($categoryId, (int) $store->getId());
        } catch (NoSuchEntityException) {
            throw new \InvalidArgumentException(sprintf('Category not found: %d', $categoryId));
        }

        // A non-anchor category shows only what is assigned to it, on the
        // storefront and here alike. Nothing to expand.
        if (!$category instanceof Category || !$category->getIsAnchor()) {
            return [$categoryId];
        }

        // getAllChildren() is the requested id followed by its active
        // descendants, read from the path column — no dependency on the
        // category-product indexer having run.
        $ids = array_map('intval', (array) $category->getAllChildren(true));
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids !== [] ? $ids : [$categoryId];
    }
}
