<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Cron;

use Angeo\McpServer\Model\Linking\LinkStore;

/**
 * Deletes used and expired account-link codes. Grants are kept until the
 * customer or the connector revokes them, or the customer is deleted.
 *
 * @since 2.4.0
 */
class PurgeLinkCodes
{
    public function __construct(
        private readonly LinkStore $linkStore
    ) {
    }

    public function execute(): void
    {
        $this->linkStore->purgeCodes();
    }
}
