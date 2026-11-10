<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Storyblok\RichtextRender\Resolver;
use Storyblok\RichtextRender\Schema;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;
use Tlab\StoryblokTransfers\Tests\Fixture\NestedFixtureTransfer;
use Tlab\StoryblokTransfers\Tests\Fixture\RichtextFixtureTransfer;
use Tlab\StoryblokTransfers\Transfers\RichtextTransfer;

/**
 * Pins the reason the node tree is kept as plain arrays: it has to stay
 * walkable by a renderer nobody here wrote.
 *
 * storyblok/richtext-resolver's default schema has no blok node, so it drops
 * embedded components silently. getBloks() is what lets a consumer put them
 * back, which is the whole shape of this design.
 */
final class RichtextResolverInteropTest extends TestCase
{
    private RichtextTransfer $richtext;

    protected function setUp(): void
    {
        $hydrator = new StoryblokHydrator('Tlab\\StoryblokTransfers\\Tests\\Fixture');

        $transfer = $hydrator->hydrate(RichtextFixtureTransfer::class, [
            'body' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Before']]],
                    ['type' => 'blok', 'attrs' => ['id' => 'blok-1', 'body' => [
                        ['component' => 'nested_fixture', 'headline' => 'Click me'],
                    ]]],
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After']]],
                ],
            ],
        ]);

        self::assertInstanceOf(RichtextFixtureTransfer::class, $transfer);
        $body = $transfer->getBody();
        self::assertInstanceOf(RichtextTransfer::class, $body);

        $this->richtext = $body;
    }

    public function testTheStockResolverWalksTheTreeAndDropsEmbeddedBloks(): void
    {
        $html = (new Resolver())->render($this->richtext->toDocument());

        self::assertSame('<p>Before</p><p>After</p>', $html);
    }

    public function testABlokNodeResolverRendersEmbeddedComponentsThroughGetBloks(): void
    {
        $richtext = $this->richtext;
        $schema = new Schema();

        // Passing nodes REPLACES the default schema rather than extending it,
        // so the defaults have to be merged back in - otherwise paragraphs and
        // text stop rendering too.
        $resolver = new Resolver([
            'marks' => $schema->getMarks(),
            'nodes' => $schema->getNodes() + [
                'blok' => static function (array $node) use ($richtext): array {
                    $id = $node['attrs']['id'] ?? '';
                    $html = '';

                    foreach ($richtext->getBloks()[$id] ?? [] as $blok) {
                        if ($blok instanceof NestedFixtureTransfer) {
                            $html .= '<button>' . $blok->getHeadline() . '</button>';
                        }
                    }

                    return ['html' => $html];
                },
            ],
        ]);

        self::assertSame(
            '<p>Before</p><button>Click me</button><p>After</p>',
            $resolver->render($richtext->toDocument())
        );
    }
}
