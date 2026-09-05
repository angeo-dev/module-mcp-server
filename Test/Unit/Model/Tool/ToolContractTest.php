<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Test\Unit\Model\Tool;

use Angeo\McpServer\Api\ToolAnnotationsInterface;
use Angeo\McpServer\Api\ToolInterface;
use Angeo\McpServer\Model\Tool\GetProductTool;
use Angeo\McpServer\Model\Tool\GetStoreInfoTool;
use Angeo\McpServer\Model\Tool\ListCategoriesTool;
use Angeo\McpServer\Model\Tool\SearchProductsTool;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The tool contract is a product surface, not an implementation detail: a
 * model picks a tool from the name, title, description and input schema this
 * server publishes, and Connector Directory reviewers read the same strings.
 *
 * These assertions are deliberately mechanical. The expensive mistake is not
 * a bad handler — handler bugs surface in testing — it is a description that
 * ships, passes every functional test, and fails review months later.
 *
 * Tools are built without their constructors: none of the contract methods
 * touches injected state, and this keeps the check free of Magento wiring so
 * it always runs.
 */
class ToolContractTest extends TestCase
{
    private const TOOL_CLASSES = [
        SearchProductsTool::class,
        GetProductTool::class,
        ListCategoriesTool::class,
        GetStoreInfoTool::class,
    ];

    /**
     * Phrases that steer the model away from other tools, or advertise.
     *
     * Directory review rejects a description that tells Claude to prefer this
     * connector over unrelated tools, or that promotes a product. Guidance
     * about THIS server's own tools ("call search_products first") is fine and
     * is not matched here — only comparisons with the outside world are.
     */
    private const BANNED_PHRASES = [
        'use this instead',
        'instead of a web search',
        'rather than a web search',
        'web search cannot',
        'web results',
        'from a web page',
        'utm_',
        'http://',
        'https://',
    ];

    /** @return iterable<string, array{ToolInterface}> */
    public static function toolProvider(): iterable
    {
        foreach (self::TOOL_CLASSES as $class) {
            /** @var ToolInterface $tool */
            $tool = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            yield $tool->getName() => [$tool];
        }
    }

    /** @dataProvider toolProvider */
    public function testNameIsPortable(ToolInterface $tool): void
    {
        $name = $tool->getName();

        // Directory review caps names at 64 characters, below the 128 the
        // current spec allows. The stricter limit is the one that ships.
        self::assertLessThanOrEqual(64, strlen($name), 'Tool name exceeds the 64-character review limit');
        self::assertMatchesRegularExpression(
            '/^[a-zA-Z0-9._-]+$/',
            $name,
            'Tool name must be ASCII letters, digits, underscores, hyphens or dots'
        );
    }

    /** @dataProvider toolProvider */
    public function testDescriptionCarriesNoSteeringOrPromotion(ToolInterface $tool): void
    {
        $description = strtolower($tool->getDescription());

        foreach (self::BANNED_PHRASES as $phrase) {
            self::assertStringNotContainsString(
                $phrase,
                $description,
                sprintf('%s description contains "%s"', $tool->getName(), $phrase)
            );
        }
    }

    /** @dataProvider toolProvider */
    public function testDescriptionIsSubstantial(ToolInterface $tool): void
    {
        $description = trim($tool->getDescription());

        // Short enough to be a placeholder is short enough to route badly.
        self::assertGreaterThan(60, strlen($description), 'Description is too thin to distinguish the tool');
        self::assertLessThan(1000, strlen($description), 'Description is long enough to crowd the catalog');
    }

    /** @dataProvider toolProvider */
    public function testEveryInputFieldIsDescribed(ToolInterface $tool): void
    {
        $schema = $tool->getInputSchema();

        self::assertSame('object', $schema['type'] ?? null, 'Input schema root must be an object');
        self::assertFalse(
            $schema['additionalProperties'] ?? true,
            'Input schema must reject unknown properties'
        );

        $properties = $schema['properties'] ?? [];
        if ($properties instanceof \stdClass) {
            $properties = (array) $properties;
        }

        foreach ($properties as $field => $spec) {
            // A type says what shape a value has, never what it means. Whether
            // partial names match, or what a default is, only a description
            // can say — and the model fills arguments from these strings.
            $described = ($spec['description'] ?? '') !== ''
                || ($spec['enum'] ?? []) !== []
                || isset($spec['minimum'], $spec['maximum']);

            self::assertTrue(
                $described,
                sprintf('%s.%s has no description or constraints', $tool->getName(), $field)
            );
        }
    }

    /** @dataProvider toolProvider */
    public function testAnnotationsAreDeclared(ToolInterface $tool): void
    {
        self::assertInstanceOf(
            ToolAnnotationsInterface::class,
            $tool,
            'Every submitted tool needs its applicable safety hint'
        );

        $annotations = $tool->getAnnotations();
        self::assertArrayHasKey('readOnlyHint', $annotations);

        // destructiveHint and idempotentHint are meaningless while readOnlyHint
        // is true, and a reviewer reads a contradictory set as carelessness.
        if ($annotations['readOnlyHint'] === true) {
            self::assertArrayNotHasKey('destructiveHint', $annotations);
            self::assertArrayNotHasKey('idempotentHint', $annotations);
        }
    }

    public function testToolNamesAreUnique(): void
    {
        $names = [];
        foreach (self::toolProvider() as [$tool]) {
            $names[] = $tool->getName();
        }

        self::assertSame($names, array_unique($names), 'Tool names must be unique within the server');
    }
}
