<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Test\Unit\Model\Security;

use Angeo\McpServer\Model\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/angeo_mcp_rl_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function testAllowsUpToLimitThenBlocks(): void
    {
        $limiter = new RateLimiter($this->dir, 3, 3600);
        self::assertTrue($limiter->allow('1.2.3.4'));
        self::assertTrue($limiter->allow('1.2.3.4'));
        self::assertTrue($limiter->allow('1.2.3.4'));
        self::assertFalse($limiter->allow('1.2.3.4'));
    }

    public function testKeysAreIndependent(): void
    {
        $limiter = new RateLimiter($this->dir, 1, 3600);
        self::assertTrue($limiter->allow('a'));
        self::assertFalse($limiter->allow('a'));
        self::assertTrue($limiter->allow('b'));
    }

    public function testZeroLimitMeansUnlimited(): void
    {
        $limiter = new RateLimiter($this->dir, 0, 3600);
        for ($i = 0; $i < 100; $i++) {
            self::assertTrue($limiter->allow('x'));
        }
    }

    public function testWindowResets(): void
    {
        $limiter = new RateLimiter($this->dir, 1, 1);
        self::assertTrue($limiter->allow('k'));
        self::assertFalse($limiter->allow('k'));
        sleep(2);
        self::assertTrue($limiter->allow('k'));
    }

    public function testHostileKeyCannotEscapeStorageDir(): void
    {
        $limiter = new RateLimiter($this->dir, 1, 3600);
        self::assertTrue($limiter->allow('../../etc/passwd'));
        self::assertSame([], glob(dirname($this->dir) . '/etc') ?: []);
    }
}
