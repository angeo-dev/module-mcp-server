<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Security;

/**
 * Pure-PHP atomic fixed-window rate limiter (same approach battle-tested in
 * the api.angeo.dev scanner): one small counter file per key, flock()-guarded
 * read-modify-write, window keyed into the file content so expiry needs no
 * cron. No Redis/DB dependency — works on any Magento hosting.
 *
 * Not distributed-safe across multiple app servers with local /var — for
 * multi-node deployments point the storage dir at shared media or swap in a
 * Redis-backed implementation via di preference.
 *
 * @since 1.0.0
 */
class RateLimiter
{
    public function __construct(
        private readonly string $storageDir,
        private readonly int $limit,
        private readonly int $windowSeconds = 60
    ) {
    }

    /**
     * @return bool true if the request is allowed, false if over the limit
     */
    public function allow(string $key): bool
    {
        if ($this->limit <= 0) {
            return true; // 0 = unlimited (explicit operator choice)
        }

        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0770, true) && !is_dir($this->storageDir)) {
            return true; // fail-open: a broken FS must not take the store down
        }

        // Key is attacker-influenced (IP / token) — never trust it as a path.
        $file = $this->storageDir . '/rl_' . hash('sha256', $key) . '.cnt';

        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return true; // fail-open
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return true; // fail-open
            }

            $window = intdiv(time(), $this->windowSeconds);
            $raw = stream_get_contents($handle) ?: '';
            [$storedWindow, $count] = array_pad(array_map('intval', explode(':', $raw, 2)), 2, 0);

            $count = ($storedWindow === $window) ? $count + 1 : 1;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $window . ':' . $count);
            fflush($handle);

            return $count <= $this->limit;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
