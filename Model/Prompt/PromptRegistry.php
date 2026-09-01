<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Prompt;

use Angeo\McpServer\Api\PromptInterface;
use Magento\Store\Api\Data\StoreInterface;

/**
 * DI-injected pool of conversation starters. Mirrors ToolRegistry so a
 * third-party module registers a prompt exactly the way it registers a tool.
 *
 * @since 1.3.0
 */
class PromptRegistry
{
    /** @var array<string, PromptInterface> */
    private array $prompts = [];

    /** @param array<string, PromptInterface> $prompts */
    public function __construct(array $prompts = [])
    {
        foreach ($prompts as $prompt) {
            if ($prompt instanceof PromptInterface) {
                $this->prompts[$prompt->getName()] = $prompt;
            }
        }
    }

    /** @return list<PromptInterface> */
    public function getAvailable(StoreInterface $store): array
    {
        $available = [];
        foreach ($this->prompts as $prompt) {
            if ($prompt->isAvailable($store)) {
                $available[] = $prompt;
            }
        }
        return $available;
    }

    public function get(string $name, StoreInterface $store): ?PromptInterface
    {
        $prompt = $this->prompts[$name] ?? null;
        return ($prompt !== null && $prompt->isAvailable($store)) ? $prompt : null;
    }

    public function isEmpty(): bool
    {
        return $this->prompts === [];
    }
}
