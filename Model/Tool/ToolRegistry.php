<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Tool;

use Angeo\McpServer\Api\ToolInterface;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Pool of registered tools (populated via di.xml). Filters by per-store
 * availability so read-only vs transactional gating lives in the tools
 * themselves, not in the protocol layer.
 *
 * @since 1.0.0
 */
class ToolRegistry
{
    /** @var array<string, ToolInterface> keyed by tool name */
    private array $byName = [];

    /**
     * @param ToolInterface[] $tools
     */
    public function __construct(array $tools = [])
    {
        foreach ($tools as $tool) {
            if ($tool instanceof ToolInterface) {
                // Last registration wins — allows third parties to override
                // a built-in tool by reusing its di.xml item name OR its
                // tool name.
                $this->byName[$tool->getName()] = $tool;
            }
        }

        // tools/list must be byte-identical between requests for the same
        // authorization: clients cache the catalog and hosts cache the prompt
        // that contains it. Insertion order here follows di.xml merge order,
        // which changes when a sibling module (mcp-checkout) is added or
        // removed, so the catalog is sorted by name instead.
        ksort($this->byName);
    }

    /** @return ToolInterface[] */
    public function getAvailable(StoreInterface $store): array
    {
        return array_values(array_filter(
            $this->byName,
            static fn (ToolInterface $tool): bool => $tool->isAvailable($store)
        ));
    }

    public function get(string $name, StoreInterface $store): ?ToolInterface
    {
        $tool = $this->byName[$name] ?? null;
        return ($tool !== null && $tool->isAvailable($store)) ? $tool : null;
    }
}
