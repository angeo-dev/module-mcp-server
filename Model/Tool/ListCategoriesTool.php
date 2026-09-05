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
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;

/**
 * list_categories — active, menu-visible category tree for the store,
 * with URLs and product counts, up to a bounded depth.
 *
 * @since 1.0.0
 */
class ListCategoriesTool implements ToolInterface, ToolAnnotationsInterface
{
    use ReadOnlyAnnotationsTrait;

    private const MAX_DEPTH = 4;

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CategoryCollectionFactory $collectionFactory
    ) {
    }

    public function getName(): string
    {
        return 'list_categories';
    }

    public function getDescription(): string
    {
        return 'List this store\'s active category tree with names, URLs and product counts, to '
            . 'a bounded depth. Returns id values that are the category_id argument for '
            . 'search_products. Use this to see what the store stocks, or to narrow a vague '
            . 'request before searching.';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'parent_id' => ['type' => 'integer', 'description' => 'Subtree root; omit for the store root'],
                'depth'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_DEPTH, 'default' => 2],
            ],
            'required'             => [],
            'additionalProperties' => false,
        ];
    }

    public function isAvailable(StoreInterface $store): bool
    {
        return true;
    }

    public function execute(array $arguments, StoreInterface $store): array
    {
        $rootId = isset($arguments['parent_id'])
            ? (int) $arguments['parent_id']
            : (int) $store->getRootCategoryId();
        $depth = min(self::MAX_DEPTH, max(1, (int) ($arguments['depth'] ?? 2)));

        try {
            $root = $this->categoryRepository->get($rootId, $store->getId());
        } catch (NoSuchEntityException) {
            throw new \InvalidArgumentException(sprintf('Category not found: %d', $rootId));
        }

        // One collection query per level keeps this bounded and index-friendly.
        return [
            'root'       => ['id' => (int) $root->getId(), 'name' => (string) $root->getName()],
            'categories' => $this->children($root, $store, $depth),
        ];
    }

    private function children(Category $parent, StoreInterface $store, int $depth): array
    {
        if ($depth < 1) {
            return [];
        }

        $collection = $this->collectionFactory->create();
        $collection->setStoreId((int) $store->getId())
            ->addAttributeToSelect(['name', 'url_key', 'is_active', 'include_in_menu'])
            ->addAttributeToFilter('parent_id', (int) $parent->getId())
            ->addAttributeToFilter('is_active', 1)
            ->setOrder('position', 'ASC');

        $result = [];
        /** @var Category $category */
        foreach ($collection as $category) {
            $result[] = [
                'id'            => (int) $category->getId(),
                'name'          => (string) $category->getName(),
                'url'           => (string) $category->getUrl(),
                'product_count' => (int) $category->getProductCount(),
                'children'      => $this->children($category, $store, $depth - 1),
            ];
        }
        return $result;
    }
}
