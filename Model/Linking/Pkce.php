<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Linking;

/**
 * PKCE S256 (RFC 7636) for the account-linking code.
 *
 * The connector that starts a link keeps the verifier; only its hash travels
 * through the shopper's browser. A link code stolen from a redirect is useless
 * without the verifier. `plain` is not supported.
 *
 * @since 2.4.0
 */
class Pkce
{
    private const VERIFIER = '/^[A-Za-z0-9\-._~]{43,128}$/';
    private const CHALLENGE = '/^[A-Za-z0-9\-_]{43}$/';

    public static function isValidChallenge(string $challenge): bool
    {
        return preg_match(self::CHALLENGE, $challenge) === 1;
    }

    public static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public static function verify(string $verifier, string $challenge): bool
    {
        if (preg_match(self::VERIFIER, $verifier) !== 1 || !self::isValidChallenge($challenge)) {
            return false;
        }
        return hash_equals($challenge, self::challengeFor($verifier));
    }
}
