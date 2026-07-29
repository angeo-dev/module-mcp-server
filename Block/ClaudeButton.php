<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Block;

use Angeo\McpServer\Model\Config;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

/**
 * "Add to Claude" button.
 *
 * Renders a one-click link that opens Claude's add-custom-connector dialog with
 * this store's connector URL and name pre-filled. The shopper still reviews and
 * approves the connection — the link only prefills the form, it grants nothing.
 *
 * Renders nothing unless a valid https connector URL is configured, so dropping
 * the widget on a store that has not been onboarded is harmless.
 *
 * @since 1.2.0
 */
class ClaudeButton extends Template
{
    /**
     * Claude's connector dialog. The two query params are prefilled values the
     * user confirms; Claude shows that they came from an external link.
     */
    private const CLAUDE_CONNECT_URL = 'https://claude.ai/customize/connectors';

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _construct(): void
    {
        parent::_construct();
        if ((string) $this->getTemplate() === '') {
            $this->setTemplate('Angeo_McpServer::claude-button.phtml');
        }
    }

    public function isAvailable(): bool
    {
        return $this->config->isConnectorConfigured();
    }

    /** The prefilled Claude URL, fully encoded. */
    public function getConnectUrl(): string
    {
        $connectorUrl = $this->config->getConnectorUrl();
        if ($connectorUrl === '') {
            return '';
        }

        return self::CLAUDE_CONNECT_URL . '?' . http_build_query([
            'modal'         => 'add-custom-connector',
            'connectorName' => $this->getConnectorName(),
            'connectorUrl'  => $connectorUrl,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Configured label, falling back to the store name. */
    public function getConnectorName(): string
    {
        $label = $this->config->getConnectorLabel();
        if ($label !== '') {
            return $label;
        }
        return (string) $this->storeManager->getStore()->getName();
    }

    /** Button text; overridable per placement via layout XML. */
    public function getButtonLabel(): string
    {
        $label = (string) $this->getData('button_label');
        return $label !== '' ? $label : (string) __('Add to Claude');
    }
}
