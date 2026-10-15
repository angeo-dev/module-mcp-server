<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Linking;

use Magento\Framework\App\ResourceConnection;

/**
 * Persistence for account linking: one-time link codes and long-lived grants.
 *
 * Plain tables behind ResourceConnection, like the rest of the Angeo suite's
 * small stores: no repository, no models, nothing a plugin could swap to widen
 * access by accident.
 *
 * @since 2.4.0
 */
class LinkStore
{
    public const CODE_TABLE = 'angeo_mcp_link_code';
    public const GRANT_TABLE = 'angeo_mcp_customer_grant';
    public const TOKEN_TABLE = 'angeo_mcp_access_token';

    /** Prefix that tells an MCP-only token from a Magento customer token. */
    public const TOKEN_PREFIX = 'amc_';

    /** A link code is redeemed seconds after it is issued; two minutes is generous. */
    public const CODE_TTL_SECONDS = 120;

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return string the plain code (shown once, in the redirect to the connector)
     */
    public function createCode(int $customerId, int $websiteId, string $redirectUri, string $challenge): string
    {
        $code = Secret::generate();
        $this->resource->getConnection()->insert($this->resource->getTableName(self::CODE_TABLE), [
            'code_hash'      => Secret::hash($code),
            'customer_id'    => $customerId,
            'website_id'     => $websiteId,
            'redirect_uri'   => $redirectUri,
            'code_challenge' => $challenge,
            'expires_at'     => gmdate('Y-m-d H:i:s', time() + self::CODE_TTL_SECONDS),
            'used'           => 0,
        ]);
        return $code;
    }

    /**
     * Redeem a code exactly once. The UPDATE … WHERE used = 0 is the lock:
     * of two concurrent redemptions only one changes a row.
     *
     * Every failure (unknown, expired, used, wrong redirect, wrong verifier)
     * returns null, so a caller cannot tell them apart.
     *
     * @return array{customer_id: int, website_id: int}|null
     */
    public function redeemCode(string $code, string $redirectUri, string $verifier): ?array
    {
        if ($code === '' || strlen($code) > 100) {
            return null;
        }
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::CODE_TABLE);
        $hash = Secret::hash($code);

        $row = $connection->fetchRow(
            $connection->select()->from($table)->where('code_hash = ?', $hash)
        );
        if (!is_array($row)
            || (int) $row['used'] === 1
            || strtotime((string) $row['expires_at'] . ' UTC') < time()
            || !hash_equals((string) $row['redirect_uri'], $redirectUri)
        ) {
            return null;
        }

        // Burn the code before checking the verifier: a wrong guess must not
        // leave it redeemable for a second guess.
        $claimed = $connection->update($table, ['used' => 1], ['code_hash = ?' => $hash, 'used = ?' => 0]);
        if ($claimed !== 1 || !Pkce::verify($verifier, (string) $row['code_challenge'])) {
            return null;
        }

        return ['customer_id' => (int) $row['customer_id'], 'website_id' => (int) $row['website_id']];
    }

    /**
     * @return string the grant as handed to the connector, "g<id>.<secret>"
     */
    public function createGrant(int $customerId, int $websiteId, int $consumerId, string $clientLabel): string
    {
        $secret = Secret::generate();
        $hash = Secret::hash($secret);
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::GRANT_TABLE);
        $connection->insert($table, [
            'customer_id'  => $customerId,
            'website_id'   => $websiteId,
            'consumer_id'  => $consumerId,
            'client_label' => mb_substr($clientLabel, 0, 120),
            'secret_hash'  => $hash,
        ]);

        // AdapterInterface has no lastInsertId(); the 256-bit secret hash is
        // unique in practice and finds the new row just as well.
        $grantId = (int) $connection->fetchOne(
            $connection->select()->from($table, ['grant_id'])->where('secret_hash = ?', $hash)
        );
        if ($grantId <= 0) {
            throw new \RuntimeException('Grant could not be stored.');
        }
        return Secret::formatGrant($grantId, $secret);
    }

    /**
     * An active grant held by this integration consumer, or null.
     *
     * @return array{grant_id: int, customer_id: int, website_id: int}|null
     */
    public function findActiveGrant(string $grant, int $consumerId): ?array
    {
        $parsed = Secret::parseGrant($grant);
        if ($parsed === null) {
            return null;
        }
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::GRANT_TABLE))
                ->where('grant_id = ?', $parsed['id'])
        );
        if (!is_array($row)
            || $row['revoked_at'] !== null
            || (int) $row['consumer_id'] !== $consumerId
            || !hash_equals((string) $row['secret_hash'], Secret::hash($parsed['secret']))
        ) {
            return null;
        }
        return [
            'grant_id'    => (int) $row['grant_id'],
            'customer_id' => (int) $row['customer_id'],
            'website_id'  => (int) $row['website_id'],
        ];
    }

    public function touchGrant(int $grantId): void
    {
        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::GRANT_TABLE),
            ['last_used_at' => gmdate('Y-m-d H:i:s')],
            ['grant_id = ?' => $grantId]
        );
    }

    /**
     * Revoke a grant. Bound to the customer, so a grant id from the form
     * cannot reach anyone else's connection.
     */
    public function revoke(int $grantId, int $customerId): bool
    {
        return $this->resource->getConnection()->update(
            $this->resource->getTableName(self::GRANT_TABLE),
            ['revoked_at' => gmdate('Y-m-d H:i:s')],
            ['grant_id = ?' => $grantId, 'customer_id = ?' => $customerId, 'revoked_at IS NULL']
        ) === 1;
    }

    /** Revoke by the connector itself, e.g. when the shopper disconnects in their AI client. */
    public function revokeByConsumer(string $grant, int $consumerId): bool
    {
        $active = $this->findActiveGrant($grant, $consumerId);
        return $active !== null && $this->revoke($active['grant_id'], $active['customer_id']);
    }

    /**
     * @return list<array{grant_id: int, client_label: string, created_at: string, last_used_at: ?string}>
     */
    public function activeForCustomer(int $customerId): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resource->getTableName(self::GRANT_TABLE),
                    ['grant_id', 'client_label', 'created_at', 'last_used_at']
                )
                ->where('customer_id = ?', $customerId)
                ->where('revoked_at IS NULL')
                ->order('created_at DESC')
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'grant_id'     => (int) $row['grant_id'],
                'client_label' => (string) $row['client_label'],
                'created_at'   => (string) $row['created_at'],
                'last_used_at' => $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
            ];
        }
        return $out;
    }

    /**
     * Issue an MCP-only customer token for a grant.
     *
     * Deliberately NOT a Magento customer token: those work against every
     * customer REST/GraphQL endpoint (addresses, orders, email). The MCP
     * specification forbids a server to accept tokens that were not issued
     * for it, and least privilege says a connector should hold nothing more
     * than it needs. This token works at /mcp only, and only next to the
     * Integration token of the consumer that holds the grant.
     */
    public function issueAccessToken(int $grantId, int $ttlSeconds): string
    {
        $token = self::TOKEN_PREFIX . Secret::generate();
        $this->resource->getConnection()->insert($this->resource->getTableName(self::TOKEN_TABLE), [
            'token_hash' => Secret::hash($token),
            'grant_id'   => $grantId,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + max(60, $ttlSeconds)),
        ]);
        return $token;
    }

    /**
     * The grant behind a valid MCP token presented by this consumer, or null.
     * A revoked grant invalidates its tokens at once.
     *
     * @return array{grant_id: int, customer_id: int, website_id: int}|null
     */
    public function resolveAccessToken(string $token, int $consumerId): ?array
    {
        if (preg_match('/^amc_[A-Za-z0-9\-_]{43}$/', $token) !== 1) {
            return null;
        }
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from(['t' => $this->resource->getTableName(self::TOKEN_TABLE)], ['expires_at'])
                ->join(
                    ['g' => $this->resource->getTableName(self::GRANT_TABLE)],
                    'g.grant_id = t.grant_id',
                    ['grant_id', 'customer_id', 'website_id', 'consumer_id', 'revoked_at']
                )
                ->where('t.token_hash = ?', Secret::hash($token))
        );
        if (!is_array($row)
            || $row['revoked_at'] !== null
            || (int) $row['consumer_id'] !== $consumerId
            || strtotime((string) $row['expires_at'] . ' UTC') <= time()
        ) {
            return null;
        }
        return [
            'grant_id'    => (int) $row['grant_id'],
            'customer_id' => (int) $row['customer_id'],
            'website_id'  => (int) $row['website_id'],
        ];
    }

    /** Housekeeping: expired or used codes, and expired MCP tokens. */
    public function purgeCodes(): int
    {
        $connection = $this->resource->getConnection();
        $cutoff = gmdate('Y-m-d H:i:s', time() - 60);
        return $connection->delete(
            $this->resource->getTableName(self::CODE_TABLE),
            ['expires_at < ? OR used = 1' => $cutoff]
        ) + $connection->delete(
            $this->resource->getTableName(self::TOKEN_TABLE),
            ['expires_at < ?' => $cutoff]
        );
    }
}
