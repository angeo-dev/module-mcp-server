<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Test\Unit\Model\Protocol;

use Angeo\McpServer\Model\Protocol\ErrorCodes;
use Angeo\McpServer\Model\Protocol\JsonRpcRequest;
use Angeo\McpServer\Model\Protocol\ProtocolException;
use PHPUnit\Framework\TestCase;

class JsonRpcRequestTest extends TestCase
{
    public function testParsesRequest(): void
    {
        $r = JsonRpcRequest::fromJson('{"jsonrpc":"2.0","id":7,"method":"tools/list","params":{}}');
        self::assertSame(7, $r->getId());
        self::assertSame('tools/list', $r->getMethod());
        self::assertFalse($r->isNotification());
    }

    public function testParsesNotification(): void
    {
        $r = JsonRpcRequest::fromJson('{"jsonrpc":"2.0","method":"notifications/initialized"}');
        self::assertTrue($r->isNotification());
        self::assertNull($r->getId());
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionCode(ErrorCodes::PARSE_ERROR);
        JsonRpcRequest::fromJson('{nope');
    }

    public function testRejectsBatch(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionCode(ErrorCodes::INVALID_REQUEST);
        JsonRpcRequest::fromJson('[{"jsonrpc":"2.0","id":1,"method":"ping"}]');
    }

    public function testRejectsWrongVersion(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionCode(ErrorCodes::INVALID_REQUEST);
        JsonRpcRequest::fromJson('{"jsonrpc":"1.0","id":1,"method":"ping"}');
    }

    public function testRejectsNullId(): void
    {
        $this->expectException(ProtocolException::class);
        JsonRpcRequest::fromJson('{"jsonrpc":"2.0","id":null,"method":"ping"}');
    }

    public function testRejectsMissingMethod(): void
    {
        $this->expectException(ProtocolException::class);
        JsonRpcRequest::fromJson('{"jsonrpc":"2.0","id":1}');
    }
}
