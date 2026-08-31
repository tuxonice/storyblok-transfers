<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Transfers\BlokTransfer;
use Tlab\StoryblokTransfers\Transfers\RichtextTransfer;

final class RichtextTransferTest extends TestCase
{
    public function testDefaultsToAnEmptyDocument(): void
    {
        $richtext = new RichtextTransfer();

        self::assertNull($richtext->getType());
        self::assertSame([], $richtext->getAttrs());
        self::assertSame([], $richtext->getContent());
        self::assertSame([], $richtext->getBloks());
    }

    public function testHydratesFromADocumentPayload(): void
    {
        $richtext = RichtextTransfer::fromArray([
            'type' => 'doc',
            'attrs' => ['backgroundColor' => null],
            'content' => [['type' => 'paragraph']],
        ]);

        self::assertSame('doc', $richtext->getType());
        self::assertSame(['backgroundColor' => null], $richtext->getAttrs());
        self::assertSame([['type' => 'paragraph']], $richtext->getContent());
    }

    public function testToDocumentReproducesADocumentWithAttrs(): void
    {
        $document = [
            'type' => 'doc',
            'attrs' => ['backgroundColor' => null],
            'content' => [['type' => 'paragraph']],
        ];

        self::assertSame($document, RichtextTransfer::fromArray($document)->toDocument());
    }

    public function testToDocumentOmitsAttrsWhenTheDocumentHadNone(): void
    {
        $document = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];

        self::assertSame($document, RichtextTransfer::fromArray($document)->toDocument());
    }

    public function testToDocumentOmitsTypeWhenTheDocumentHadNone(): void
    {
        $document = ['content' => [['type' => 'paragraph']]];

        self::assertSame($document, RichtextTransfer::fromArray($document)->toDocument());
    }

    /**
     * The resolver reads $data['content'] unguarded, so the key is emitted even
     * when the payload never carried one. This is the half of toDocument()'s
     * contract that is not a faithful round trip.
     */
    public function testToDocumentAlwaysEmitsContent(): void
    {
        $document = RichtextTransfer::fromArray(['type' => 'doc'])->toDocument();

        self::assertSame(['type' => 'doc', 'content' => []], $document);
    }

    public function testToDocumentDropsAnExplicitlyEmptyAttrs(): void
    {
        $document = RichtextTransfer::fromArray([
            'type' => 'doc',
            'attrs' => [],
            'content' => [],
        ])->toDocument();

        self::assertSame(['type' => 'doc', 'content' => []], $document);
    }

    /**
     * $bloks is built from the tree by the hydrator, not sent by Storyblok, so
     * it must never reach a renderer. Guards the one hazard of deriving the
     * document from the property set rather than hand-listing its keys.
     */
    public function testToDocumentNeverLeaksTheDerivedBloksMap(): void
    {
        $button = (new BlokTransfer())->setComponent('button');

        $document = (new RichtextTransfer())
            ->setType('doc')
            ->setBloks(['blok-123' => [$button]])
            ->toDocument();

        self::assertSame(['type' => 'doc', 'content' => []], $document);
    }

    public function testToDocumentEmitsAFixedKeyOrder(): void
    {
        $document = RichtextTransfer::fromArray([
            'content' => [['type' => 'paragraph']],
            'type' => 'doc',
        ])->toDocument();

        self::assertSame(['type' => 'doc', 'content' => [['type' => 'paragraph']]], $document);
    }

    /**
     * Storyblok sends an explicit null for a document key the editor never
     * filled. AbstractTransfer::fromArray() hands that straight to the setter,
     * so a non-nullable parameter turns editor-controlled content into a fatal.
     */
    public function testHydratesADocumentWhoseAttrsAreNull(): void
    {
        $richtext = RichtextTransfer::fromArray([
            'type' => 'doc',
            'attrs' => null,
            'content' => [['type' => 'paragraph']],
        ]);

        self::assertSame([], $richtext->getAttrs());
        self::assertSame([['type' => 'paragraph']], $richtext->getContent());
    }

    public function testHydratesADocumentWhoseContentIsNull(): void
    {
        $richtext = RichtextTransfer::fromArray(['type' => 'doc', 'content' => null]);

        self::assertSame('doc', $richtext->getType());
        self::assertSame([], $richtext->getContent());
    }

    public function testHydratesADocumentWhoseBloksKeyIsNull(): void
    {
        $richtext = RichtextTransfer::fromArray(['type' => 'doc', 'bloks' => null]);

        self::assertSame([], $richtext->getBloks());
    }

    /**
     * declare(strict_types=1) binds at the call site, and upstream's
     * AbstractTransfer has none - so fromArray() reaches the setters in weak
     * mode, where a scalar type coerces to a plausible-looking string and flows
     * on into the renderer's per-node-type dispatch. Discard it instead, which
     * matches the spec's defensive stance towards editor-controlled content and
     * leaves toDocument() omitting the key entirely.
     */
    public function testDiscardsANumericTypeRatherThanCoercingIt(): void
    {
        $richtext = RichtextTransfer::fromArray(['type' => 123, 'content' => []]);

        self::assertNull($richtext->getType());
        self::assertSame(['content' => []], $richtext->toDocument());
    }

    public function testDiscardsABooleanTypeRatherThanCoercingIt(): void
    {
        self::assertNull(RichtextTransfer::fromArray(['type' => true])->getType());
    }

    public function testStillAcceptsAStringType(): void
    {
        self::assertSame('doc', RichtextTransfer::fromArray(['type' => 'doc'])->getType());
    }

    /**
     * Weak mode will not coerce a scalar into an array, so an ?array parameter
     * raises a TypeError rather than mangling the value. Same malformed payload,
     * same need to degrade instead of fatal.
     */
    public function testDiscardsANonArrayContentRatherThanFataling(): void
    {
        $richtext = RichtextTransfer::fromArray(['type' => 'doc', 'content' => 'foo']);

        self::assertSame('doc', $richtext->getType());
        self::assertSame([], $richtext->getContent());
    }

    public function testDiscardsNonArrayAttrsRatherThanFataling(): void
    {
        self::assertSame([], RichtextTransfer::fromArray(['attrs' => 'foo'])->getAttrs());
    }

    public function testDiscardsANonArrayBloksKeyRatherThanFataling(): void
    {
        self::assertSame([], RichtextTransfer::fromArray(['bloks' => 123])->getBloks());
    }

    public function testStillAcceptsTheDocumentKeysItIsGiven(): void
    {
        $richtext = RichtextTransfer::fromArray([
            'attrs' => ['backgroundColor' => 'red'],
            'content' => [['type' => 'paragraph']],
        ]);

        self::assertSame(['backgroundColor' => 'red'], $richtext->getAttrs());
        self::assertSame([['type' => 'paragraph']], $richtext->getContent());
    }

    /**
     * The array properties are non-nullable so that upstream's foreach over
     * every array-typed property cannot meet a null. phpunit.xml sets
     * failOnWarning, so this test fails if a nullable one is reintroduced.
     */
    public function testRecursiveToArrayEmitsNoWarningOnAnEmptyDocument(): void
    {
        $data = (new RichtextTransfer())->toArray(true);

        self::assertSame([], $data['content']);
        self::assertSame([], $data['bloks']);
    }

    public function testRecursiveToArrayKeepsTheNodeListIntact(): void
    {
        $nodes = [['type' => 'paragraph'], ['type' => 'horizontal_rule']];

        $data = (new RichtextTransfer())->setContent($nodes)->toArray(true);

        self::assertSame($nodes, $data['content']);
    }

    /**
     * The point of the whole design: a component embedded in the tree is
     * reachable by the id of the blok node that carried it, already hydrated.
     */
    public function testKeepsHydratedBloksUnderTheirNodeId(): void
    {
        $button = (new BlokTransfer())->setComponent('button');

        $richtext = (new RichtextTransfer())->setBloks(['blok-123' => [$button]]);

        self::assertSame(['blok-123' => [$button]], $richtext->getBloks());
    }

    /**
     * A documented limitation rather than a goal, pinned here so it cannot
     * change unnoticed. Upstream processArrayType() appends with $data[], so the
     * node-id keys are lost, and it unwraps a value only when that value is
     * itself a transfer - a *list* of transfers passes through with the objects
     * still in it.
     *
     * Serialising a richtext field therefore means toDocument() plus getBloks(),
     * never toArray(true). See "Consequences" in the design spec; the fix
     * belongs in tuxonice/transfer-objects and is out of scope here.
     *
     * Asserted structurally rather than on json_encode() output: the dependency
     * floats at ^1.2, so this test is the tripwire for that upstream fix landing
     * and its failure has to say so rather than reporting a string mismatch.
     */
    public function testRecursiveToArrayNeitherKeysNorUnwrapsBloks(): void
    {
        $button = (new BlokTransfer())->setComponent('button');

        $data = (new RichtextTransfer())->setBloks(['blok-123' => [$button]])->toArray(true);

        $upstreamFixed = 'tuxonice/transfer-objects appears to have fixed processArrayType(). '
            . 'If nested transfers are now keyed and unwrapped, drop this test and the guidance '
            . 'that sends consumers to toDocument() plus getBloks() instead of toArray(true).';

        self::assertSame([0], array_keys($data['bloks']), $upstreamFixed);
        self::assertInstanceOf(BlokTransfer::class, $data['bloks'][0][0], $upstreamFixed);
    }
}
