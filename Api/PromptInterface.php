<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Api;

use Magento\Store\Api\Data\StoreInterface;

/**
 * A server-supplied conversation starter (MCP `prompts` capability).
 *
 * WHY THIS EXISTS
 * ---------------
 * A shopper who has just connected a store connector has to guess how to
 * phrase a request so the model reaches for it. "Find me a grey backpack" —
 * naming no shop — competes with the client's own general product search and
 * usually loses. That is correct behaviour on the client's part, and no amount
 * of tool description changes it.
 *
 * Prompts sidestep the guessing entirely: the store supplies the phrasing, the
 * shopper picks it from the client's UI, and the request arrives already
 * anchored to this store.
 *
 * WHAT PROMPTS ARE NOT
 * --------------------
 * They do not fire automatically. An MCP server cannot start a conversation
 * turn or inject a message — deliberately, or any connected server could speak
 * first. A prompt is an offer the shopper accepts with one click, not an
 * action the server takes.
 *
 * Nor are they visible to the model: clients surface prompts to the *user*, so
 * the model will not suggest one on its own.
 *
 * IMPLEMENTING YOUR OWN
 * ---------------------
 * Implement this interface and add it to the `prompts` array of
 * Angeo\McpServer\Model\Prompt\PromptRegistry in your module's di.xml, exactly
 * as tools are registered.
 *
 * @api
 * @since 1.3.0
 */
interface PromptInterface
{
    /** Stable machine name, e.g. "browse_catalog". */
    public function getName(): string;

    /** Short human-readable label shown in the client's prompt picker. */
    public function getTitle(StoreInterface $store): string;

    /** One line explaining what picking this will do. */
    public function getDescription(StoreInterface $store): string;

    /**
     * Declared arguments, in MCP `prompts/list` shape:
     *   [['name' => 'query', 'description' => '…', 'required' => false], …]
     *
     * @return list<array<string, mixed>>
     */
    public function getArguments(): array;

    /** Hidden from the picker when this returns false. */
    public function isAvailable(StoreInterface $store): bool;

    /**
     * The message(s) inserted into the conversation when the shopper picks
     * this prompt, in MCP `prompts/get` shape:
     *   [['role' => 'user', 'content' => ['type' => 'text', 'text' => '…']], …]
     *
     * @param array<string, mixed> $arguments
     * @return list<array<string, mixed>>
     */
    public function getMessages(array $arguments, StoreInterface $store): array;
}
