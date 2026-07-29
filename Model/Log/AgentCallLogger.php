<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Log;

use Angeo\McpServer\Model\Protocol\JsonRpcRequest;
use Angeo\McpServer\Model\Protocol\JsonRpcResponse;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Appends one JSON line per tools/call to var/log/angeo_mcp_agent.jsonl.
 *
 * This is deliberately a flat JSONL file, not a DB table:
 *  - zero schema/upgrade burden in 1.0;
 *  - trivially shippable (logrotate-friendly, tail-able, grep-able);
 *  - it is the raw feed for the future admin bot-traffic panel and the
 *    hosted agent-analytics service — the format is the product seed.
 *
 * Logged fields NEVER include tool result payloads (they may echo catalog
 * data at scale) or Authorization headers. Only: timestamp, store, method,
 * tool name, truncated user agent, duration, outcome flag.
 *
 * @since 1.0.0
 */
class AgentCallLogger
{
    private const LOG_FILE = 'log/angeo_mcp_agent.jsonl';
    private const UA_MAX_LENGTH = 250;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger
    ) {
    }

    public function log(
        StoreInterface $store,
        JsonRpcRequest $request,
        ?JsonRpcResponse $response,
        string $userAgent,
        float $durationSeconds
    ): void {
        // Log only tool calls and initializations — pings and list calls are noise.
        if (!in_array($request->getMethod(), ['tools/call', 'initialize'], true)) {
            return;
        }

        $record = [
            'ts'       => gmdate('c'),
            'store'    => (string) $store->getCode(),
            'method'   => $request->getMethod(),
            'tool'     => $request->getMethod() === 'tools/call'
                ? (string) ($request->getParams()['name'] ?? '')
                : null,
            'ua'       => mb_substr($userAgent, 0, self::UA_MAX_LENGTH),
            'ms'       => (int) round($durationSeconds * 1000),
            'ok'       => $response !== null && !$response->isError(),
        ];

        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return;
        }

        try {
            $dir = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
            $stream = $dir->openFile(self::LOG_FILE, 'a');
            try {
                $stream->lock();
                $stream->write($json . "\n");
                $stream->unlock();
            } finally {
                $stream->close();
            }
        } catch (\Throwable $e) {
            // Logging must never break the endpoint.
            $this->logger->debug('[Angeo_McpServer] agent log write failed: ' . $e->getMessage());
        }
    }
}
