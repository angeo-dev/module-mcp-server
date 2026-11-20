<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Test\Unit\Model\Log;

use Angeo\McpServer\Model\Log\AgentCallLogger;
use Angeo\McpServer\Model\Protocol\JsonRpcRequest;
use Angeo\McpServer\Model\Protocol\JsonRpcResponse;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\File\WriteInterface as FileWrite;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AgentCallLoggerTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    private function logger(int $currentSize, bool $expectRotate): AgentCallLogger
    {
        $file = $this->createMock(FileWrite::class);
        $file->method('write')->willReturnCallback(function (string $line): int {
            $this->written[] = $line;
            return strlen($line);
        });

        $dir = $this->createMock(WriteInterface::class);
        $dir->method('isFile')->willReturn($currentSize > 0);
        $dir->method('stat')->willReturn(['size' => $currentSize]);
        $dir->expects($expectRotate ? self::once() : self::never())
            ->method('renameFile')
            ->with('log/angeo_mcp_agent.jsonl', 'log/angeo_mcp_agent.jsonl.1');
        $dir->method('openFile')->willReturn($file);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($dir);

        return new AgentCallLogger($filesystem, $this->createMock(LoggerInterface::class));
    }

    private function call(AgentCallLogger $logger): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $request = JsonRpcRequest::fromJson('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"search_products"}}');
        $logger->log($store, $request, JsonRpcResponse::result(1, []), 'Claude-User', 0.01);
    }

    public function testSmallFileIsAppendedWithoutRotation(): void
    {
        $this->call($this->logger(1024, false));
        self::assertCount(1, $this->written);
        self::assertStringContainsString('"tool":"search_products"', $this->written[0]);
    }

    public function testFileAtTheLimitIsRotatedFirst(): void
    {
        $this->call($this->logger(10 * 1024 * 1024, true));
        self::assertCount(1, $this->written);
    }

    public function testLoggingIsOffByDefault(): void
    {
        $config = (string) file_get_contents(dirname(__DIR__, 4) . '/etc/config.xml');
        self::assertStringContainsString('<log_calls>0</log_calls>', $config);
    }
}
