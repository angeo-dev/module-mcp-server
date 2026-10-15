<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Test\Unit\Model\Linking;

use Angeo\McpServer\Model\Linking\LinkRequest;
use Angeo\McpServer\Model\Linking\Pkce;
use Angeo\McpServer\Model\Linking\Secret;
use PHPUnit\Framework\TestCase;

class LinkingPrimitivesTest extends TestCase
{
    private const REDIRECT = 'https://mcp.angeo.dev/authorize/linked';

    // ── PKCE ────────────────────────────────────────────────────────────────

    public function testPkceMatchesRfc7636AppendixB(): void
    {
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        self::assertSame($challenge, Pkce::challengeFor($verifier));
        self::assertTrue(Pkce::verify($verifier, $challenge));
    }

    public function testPkceRejectsWrongOrMalformedVerifier(): void
    {
        $challenge = Pkce::challengeFor(str_repeat('a', 43));

        self::assertFalse(Pkce::verify(str_repeat('b', 43), $challenge));
        self::assertFalse(Pkce::verify('short', Pkce::challengeFor('short')));
        self::assertFalse(Pkce::verify(str_repeat('a', 43), 'not-a-challenge'));
    }

    // ── Secrets ─────────────────────────────────────────────────────────────

    public function testSecretsAreUrlSafeAndUnique(): void
    {
        $a = Secret::generate();
        self::assertMatchesRegularExpression('/^[A-Za-z0-9\-_]{43}$/', $a);
        self::assertNotSame($a, Secret::generate());
        self::assertSame(64, strlen(Secret::hash($a)));
    }

    public function testGrantRoundTrip(): void
    {
        $secret = Secret::generate();
        $grant = Secret::formatGrant(42, $secret);

        self::assertSame(['id' => 42, 'secret' => $secret], Secret::parseGrant($grant));
    }

    /**
     * @dataProvider badGrants
     */
    public function testMalformedGrantsAreRejected(string $grant): void
    {
        self::assertNull(Secret::parseGrant($grant));
    }

    /** @return array<string, array{string}> */
    public static function badGrants(): array
    {
        $secret = str_repeat('A', 43);
        return [
            'empty'          => [''],
            'no prefix'      => ['42.' . $secret],
            'zero id'        => ['g0.' . $secret],
            'short secret'   => ['g42.abc'],
            'sql in id'      => ['g42 OR 1=1.' . $secret],
            'trailing junk'  => ['g42.' . $secret . 'x'],
        ];
    }

    // ── Link request ────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private static function params(array $override = []): array
    {
        return $override + [
            'redirect_uri'          => self::REDIRECT,
            'state'                 => 'flow_0123456789abcdef',
            'code_challenge'        => Pkce::challengeFor(str_repeat('v', 43)),
            'code_challenge_method' => 'S256',
        ];
    }

    public function testValidRequestRoundTripsThroughTheForm(): void
    {
        $link = LinkRequest::fromParams(self::params(), [self::REDIRECT]);

        self::assertSame(self::params(), $link->toFormFields());
    }

    public function testRedirectUriMustMatchExactly(): void
    {
        foreach ([
            self::REDIRECT . '/',
            self::REDIRECT . '?x=1',
            'https://MCP.angeo.dev/authorize/linked',
            'https://evil.example/authorize/linked',
        ] as $uri) {
            try {
                LinkRequest::fromParams(self::params(['redirect_uri' => $uri]), [self::REDIRECT]);
                self::fail('accepted ' . $uri);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('trusts', $e->getMessage());
            }
        }
    }

    public function testEmptyAllowListAcceptsNothing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LinkRequest::fromParams(self::params(), []);
    }

    public function testPlainPkceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LinkRequest::fromParams(self::params(['code_challenge_method' => 'plain']), [self::REDIRECT]);
    }

    public function testShortOrUnsafeStateIsRefused(): void
    {
        foreach (['short', 'has space in it 12345', str_repeat('x', 201), "line\nbreak1234567890"] as $state) {
            try {
                LinkRequest::fromParams(self::params(['state' => $state]), [self::REDIRECT]);
                self::fail('accepted state ' . json_encode($state));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRedirectsCarryCodeAndState(): void
    {
        $link = LinkRequest::fromParams(self::params(), [self::REDIRECT]);

        self::assertSame(
            self::REDIRECT . '?code=abc&state=flow_0123456789abcdef',
            $link->successRedirect('abc')
        );
        self::assertSame(
            self::REDIRECT . '?error=access_denied&state=flow_0123456789abcdef',
            $link->deniedRedirect()
        );
    }

    public function testRedirectKeepsAnExistingQuery(): void
    {
        $uri = self::REDIRECT . '?tenant=acme';
        $link = LinkRequest::fromParams(self::params(['redirect_uri' => $uri]), [$uri]);

        self::assertStringStartsWith($uri . '&code=', $link->successRedirect('abc'));
    }
}
