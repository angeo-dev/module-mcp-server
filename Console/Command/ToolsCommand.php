<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Console\Command;

use Angeo\McpServer\Model\Protocol\JsonRpcRequest;
use Angeo\McpServer\Model\Protocol\McpServer;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento angeo:mcp:tools            — list registered tools
 * bin/magento angeo:mcp:tools NAME -a '' — call a tool with JSON arguments
 *
 * Lets operators verify the server without wiring up an agent.
 *
 * @since 1.0.0
 */
class ToolsCommand extends Command
{
    public function __construct(
        private readonly McpServer $mcpServer,
        private readonly StoreManagerInterface $storeManager,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('angeo:mcp:tools')
            ->setDescription('List MCP tools, or call one with JSON arguments')
            ->addArgument('tool', InputArgument::OPTIONAL, 'Tool name to call')
            ->addOption('arguments', 'a', InputOption::VALUE_REQUIRED, 'JSON arguments object', '{}')
            ->addOption('store', 's', InputOption::VALUE_REQUIRED, 'Store code', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);
        } catch (\Magento\Framework\Exception\LocalizedException) {
            // Area already set — fine.
        }

        $storeCode = $input->getOption('store');
        $store = $storeCode !== null
            ? $this->storeManager->getStore($storeCode)
            : $this->storeManager->getDefaultStoreView();

        $toolName = $input->getArgument('tool');
        $rpc = $toolName === null
            ? ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']
            : [
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'tools/call',
                'params'  => [
                    'name'      => $toolName,
                    'arguments' => json_decode((string) $input->getOption('arguments'), true) ?? [],
                ],
            ];

        $response = $this->mcpServer->handle(
            JsonRpcRequest::fromJson(json_encode($rpc)),
            $store
        );

        $output->writeln(json_encode(
            $response?->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return ($response !== null && $response->isError()) ? Command::FAILURE : Command::SUCCESS;
    }
}
