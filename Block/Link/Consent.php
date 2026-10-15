<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Block\Link;

use Magento\Framework\View\Element\Template;

/**
 * Approval page for account linking. Data is set by the controller; the
 * template escapes every value.
 *
 * @since 2.4.0
 */
class Consent extends Template
{
    /** @var string */
    protected $_template = 'Angeo_McpServer::link/consent.phtml';

    public function getErrorMessage(): string
    {
        return (string) $this->getData('error_message');
    }

    /** @return array<string, string> */
    public function getLinkFields(): array
    {
        $fields = $this->getData('link_fields');
        return is_array($fields) ? $fields : [];
    }

    public function getApproveUrl(): string
    {
        return $this->getUrl('angeo_mcp/link/approve');
    }

    /**
     * What the connector may do, in the shopper's words. Kept in sync with the
     * tools that read CallerContextInterface (e.g. angeo/module-mcp-reorder).
     *
     * @return string[]
     */
    public function getPermissions(): array
    {
        return [
            (string) __('See your orders on this store'),
            (string) __('See your prices'),
            (string) __('Add products to your cart and change it'),
        ];
    }

    /** @return string[] */
    public function getLimits(): array
    {
        return [
            (string) __('It cannot place orders or pay. You check out yourself.'),
            (string) __('It cannot see your password, addresses or payment details.'),
            (string) __('You can disconnect it at any time under My Account → AI assistants.'),
        ];
    }
}
