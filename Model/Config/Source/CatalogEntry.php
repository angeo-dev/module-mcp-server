<?php
declare(strict_types=1);

namespace Angeo\McpServer\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/** @since 2.5.0 */
class CatalogEntry implements OptionSourceInterface
{
    /** @return list<array{value:string,label:\Magento\Framework\Phrase}> */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'auto', 'label' => __('Automatic')],
            ['value' => 'own', 'label' => __("This store's card (/mcp/server-card)")],
            ['value' => 'connector', 'label' => __("The connector's card only")],
            ['value' => 'none', 'label' => __('Do not list')],
        ];
    }
}
