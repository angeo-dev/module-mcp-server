<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Linking;

/**
 * The parameters a connector sends the shopper's browser to /mcp/link with.
 *
 *   redirect_uri          exact match against the configured list
 *   state                 opaque, echoed back (the connector's CSRF binding)
 *   code_challenge        PKCE S256
 *   code_challenge_method must be "S256"
 *
 * Validation errors are shown on the store's own page. They never redirect:
 * redirecting to an unverified URI is how open redirectors are made.
 *
 * No Magento dependencies: unit-tested in isolation.
 *
 * @since 2.4.0
 */
class LinkRequest
{
    private function __construct(
        public readonly string $redirectUri,
        public readonly string $state,
        public readonly string $codeChallenge
    ) {
    }

    /**
     * @param array<string, mixed> $params request parameters
     * @param string[]             $allowedRedirectUris
     * @throws \InvalidArgumentException with a message safe to show the shopper
     */
    public static function fromParams(array $params, array $allowedRedirectUris): self
    {
        $redirect = is_string($params['redirect_uri'] ?? null) ? $params['redirect_uri'] : '';
        if ($redirect === '' || !in_array($redirect, $allowedRedirectUris, true)) {
            throw new \InvalidArgumentException(
                'This connection link did not come from a connector this store trusts.'
            );
        }

        $state = is_string($params['state'] ?? null) ? $params['state'] : '';
        if (preg_match('/^[A-Za-z0-9\-._~]{16,200}$/', $state) !== 1) {
            throw new \InvalidArgumentException('This connection link is incomplete. Start again from your AI assistant.');
        }

        $method = is_string($params['code_challenge_method'] ?? null) ? $params['code_challenge_method'] : '';
        $challenge = is_string($params['code_challenge'] ?? null) ? $params['code_challenge'] : '';
        if ($method !== 'S256' || !Pkce::isValidChallenge($challenge)) {
            throw new \InvalidArgumentException('This connection link is incomplete. Start again from your AI assistant.');
        }

        return new self($redirect, $state, $challenge);
    }

    /** @return array<string, string> the fields the approval form carries forward */
    public function toFormFields(): array
    {
        return [
            'redirect_uri'          => $this->redirectUri,
            'state'                 => $this->state,
            'code_challenge'        => $this->codeChallenge,
            'code_challenge_method' => 'S256',
        ];
    }

    public function successRedirect(string $code): string
    {
        return $this->append(['code' => $code, 'state' => $this->state]);
    }

    public function deniedRedirect(): string
    {
        return $this->append(['error' => 'access_denied', 'state' => $this->state]);
    }

    /** @param array<string, string> $query */
    private function append(array $query): string
    {
        $separator = str_contains($this->redirectUri, '?') ? '&' : '?';
        return $this->redirectUri . $separator . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
