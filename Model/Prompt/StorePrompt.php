<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Prompt;

use Angeo\McpServer\Api\PromptInterface;
use Angeo\McpServer\Model\Config;
use Magento\Store\Api\Data\StoreInterface;

/**
 * A prompt built from templates, with the store's own name interpolated.
 *
 * All built-in prompts are instances of this class configured through di.xml,
 * so adding one is a config change rather than a new PHP file. The store name
 * is the point: a request that names the shop is one the model has a clear
 * reason to route here, which is exactly what an unanchored request lacks.
 *
 * @since 1.3.0
 */
class StorePrompt implements PromptInterface
{
    /**
     * @param string $name        machine name
     * @param string $title       picker label; %s is the store label
     * @param string $description one line for the picker; %s is the store label
     * @param string $template    the message text; %s is the store label,
     *                            {argument} placeholders are substituted
     * @param list<array<string, mixed>> $arguments
     * @param list<string> $requiresTools tools that must exist for this prompt
     *                            to make sense (e.g. place_order for checkout)
     */
    public function __construct(
        private readonly Config $config,
        private readonly \Angeo\McpServer\Model\Tool\ToolRegistry $toolRegistry,
        private readonly string $name,
        private readonly string $title,
        private readonly string $description,
        private readonly string $template,
        private readonly array $arguments = [],
        private readonly array $requiresTools = [],
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTitle(StoreInterface $store): string
    {
        return $this->interpolate($this->title, $store);
    }

    public function getDescription(StoreInterface $store): string
    {
        return $this->interpolate($this->description, $store);
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function isAvailable(StoreInterface $store): bool
    {
        if (!$this->config->isEnabled($store)) {
            return false;
        }

        if ($this->requiresTools === []) {
            return true;
        }

        // Offering "buy something" on a store without checkout tools would
        // produce a prompt the model cannot fulfil.
        $present = [];
        foreach ($this->toolRegistry->getAvailable($store) as $tool) {
            $present[] = $tool->getName();
        }
        foreach ($this->requiresTools as $required) {
            if (!in_array($required, $present, true)) {
                return false;
            }
        }

        return true;
    }

    public function getMessages(array $arguments, StoreInterface $store): array
    {
        $text = $this->interpolate($this->template, $store);

        foreach ($this->arguments as $spec) {
            $key = (string) ($spec['name'] ?? '');
            if ($key === '') {
                continue;
            }
            $value = $arguments[$key] ?? '';
            $value = is_scalar($value) ? (string) $value : '';
            // Trim aggressively: this text goes into a conversation, and an
            // over-long argument would bury the instruction it belongs to.
            $text = str_replace('{' . $key . '}', mb_substr(trim($value), 0, 200), $text);
        }

        // Any placeholder the caller left unfilled would otherwise reach the
        // conversation as literal braces.
        $text = trim((string) preg_replace('/\s*\{[a-z_]+\}/i', '', $text));

        return [[
            'role'    => 'user',
            'content' => ['type' => 'text', 'text' => $text],
        ]];
    }

    private function interpolate(string $text, StoreInterface $store): string
    {
        $label = $this->config->getStoreLabel($store);
        return str_replace('%s', $label !== '' ? $label : 'this store', $text);
    }
}
