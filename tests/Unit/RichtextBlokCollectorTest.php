<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Hydration\RichtextBlokCollector;

final class RichtextBlokCollectorTest extends TestCase
{
    private RichtextBlokCollector $collector;

    /**
     * @var callable(array<string, mixed>): mixed
     */
    private $hydrate;

    protected function setUp(): void
    {
        $this->collector = new RichtextBlokCollector();
        // Stands in for hydration: names the component it was handed, so the
        // assertions are about which components reached it, and in what order.
        $this->hydrate = static fn (array $component): string => 'hydrated:'
            . (is_string($component['component'] ?? null) ? $component['component'] : '?');
    }

    public function testCollectsABlokAtTheTopLevel(): void
    {
        $bloks = $this->collector->collect([
            ['type' => 'paragraph'],
            ['type' => 'blok', 'attrs' => ['id' => 'blok-1', 'body' => [
                ['component' => 'button'],
            ]]],
        ], $this->hydrate);

        self::assertSame(['blok-1' => ['hydrated:button']], $bloks);
    }

    public function testCollectsEveryComponentOfOneBlokNodeInPayloadOrder(): void
    {
        $bloks = $this->collector->collect([
            ['type' => 'blok', 'attrs' => ['id' => 'blok-1', 'body' => [
                ['component' => 'first'],
                ['component' => 'second'],
            ]]],
        ], $this->hydrate);

        self::assertSame(['blok-1' => ['hydrated:first', 'hydrated:second']], $bloks);
    }

    public function testFindsABlokNestedDeepInTheTree(): void
    {
        $bloks = $this->collector->collect([
            ['type' => 'bullet_list', 'content' => [
                ['type' => 'list_item', 'content' => [
                    ['type' => 'blok', 'attrs' => ['id' => 'deep', 'body' => [
                        ['component' => 'button'],
                    ]]],
                ]],
            ]],
        ], $this->hydrate);

        self::assertSame(['deep' => ['hydrated:button']], $bloks);
    }

    public function testFindsABlokInsideATableCell(): void
    {
        $bloks = $this->collector->collect([
            ['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [
                    ['type' => 'tableCell', 'content' => [
                        ['type' => 'blok', 'attrs' => ['id' => 'in-cell', 'body' => [
                            ['component' => 'button'],
                        ]]],
                    ]],
                ]],
            ]],
        ], $this->hydrate);

        self::assertSame(['in-cell' => ['hydrated:button']], $bloks);
    }

    public function testIgnoresAnEmptyBody(): void
    {
        $bloks = $this->collector->collect([
            ['type' => 'blok', 'attrs' => ['id' => 'blok-1', 'body' => []]],
        ], $this->hydrate);

        self::assertSame([], $bloks);
    }

    public function testIgnoresANullBody(): void
    {
        $bloks = $this->collector->collect([
            ['type' => 'blok', 'attrs' => ['id' => 'blok-1', 'body' => null]],
        ], $this->hydrate);

        self::assertSame([], $bloks);
    }

    public function testMergesDuplicateIdsInDocumentOrder(): void
    {
        $bloks = $this->collector->collect([
            ['type' => 'blok', 'attrs' => ['id' => 'same', 'body' => [['component' => 'first']]]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'blok', 'attrs' => ['id' => 'same', 'body' => [['component' => 'second']]]],
            ]],
        ], $this->hydrate);

        self::assertSame(['same' => ['hydrated:first', 'hydrated:second']], $bloks);
    }

    public function testSkipsABlokNodeWithNoId(): void
    {
        $bloks = $this->collector->collect([
            ['type' => 'blok', 'attrs' => ['body' => [['component' => 'button']]]],
            ['type' => 'blok', 'attrs' => ['id' => '', 'body' => [['component' => 'button']]]],
            ['type' => 'blok', 'attrs' => ['id' => 42, 'body' => [['component' => 'button']]]],
        ], $this->hydrate);

        self::assertSame([], $bloks);
    }

    public function testSkipsMalformedNodes(): void
    {
        $bloks = $this->collector->collect([
            'not a node',
            ['no type key' => true],
            ['type' => 'blok'],
            ['type' => 'blok', 'attrs' => 'not an array'],
            ['type' => 'blok', 'attrs' => ['id' => 'blok-1', 'body' => ['not a component']]],
        ], $this->hydrate);

        self::assertSame([], $bloks);
    }

    public function testANumericIdCanStillBeLookedUpByTheStringARendererHolds(): void
    {
        // PHP stores the array key "123" as the integer 123. A renderer holds
        // the id as the string from attrs.id, and the lookup has to work anyway.
        $node = ['type' => 'blok', 'attrs' => ['id' => '123', 'body' => [['component' => 'button']]]];

        $bloks = $this->collector->collect([$node], $this->hydrate);

        self::assertSame(['hydrated:button'], $bloks[$node['attrs']['id']] ?? null);
    }

    public function testReturnsNothingForADocumentWithNoBloks(): void
    {
        $bloks = $this->collector->collect([
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]],
        ], $this->hydrate);

        self::assertSame([], $bloks);
    }

    /**
     * A component embedded in a blok may itself hold a richtext field with more
     * bloks. Those belong to that component's own RichtextTransfer, hydrated
     * when the component is, so the collector must not reach into attrs.body.
     */
    public function testDoesNotDescendIntoTheComponentsItCollects(): void
    {
        $bloks = $this->collector->collect([
            ['type' => 'blok', 'attrs' => ['id' => 'outer', 'body' => [
                [
                    'component' => 'wrapper',
                    'body' => ['type' => 'doc', 'content' => [
                        ['type' => 'blok', 'attrs' => ['id' => 'inner', 'body' => [
                            ['component' => 'button'],
                        ]]],
                    ]],
                ],
            ]]],
        ], $this->hydrate);

        self::assertSame(['outer' => ['hydrated:wrapper']], $bloks);
    }
}
