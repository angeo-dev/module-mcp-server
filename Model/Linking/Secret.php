<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Linking;

/**
 * Random secrets for link codes and grants, and their stored form.
 *
 * Only a SHA-256 of each secret is stored. A database leak therefore hands out
 * no working link code and no working grant.
 *
 * A grant travels as "g<id>.<secret>": the id finds the row in one indexed
 * lookup, the secret proves possession and is compared in constant time.
 *
 * @since 2.4.0
 */
class Secret
{
    public static function generate(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function formatGrant(int $grantId, string $secret): string
    {
        return 'g' . $grantId . '.' . $secret;
    }

    /**
     * @return array{id: int, secret: string}|null
     */
    public static function parseGrant(string $grant): ?array
    {
        if (preg_match('/^g([1-9][0-9]{0,9})\.([A-Za-z0-9\-_]{43})$/', $grant, $m) !== 1) {
            return null;
        }
        return ['id' => (int) $m[1], 'secret' => $m[2]];
    }
}
