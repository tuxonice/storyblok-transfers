<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Transfers;

use Tlab\TransferObjects\AbstractTransfer;

/**
 * Storyblok richtext field.
 *
 * Holds the document's own keys - type, attrs, content - plus the components
 * embedded in the tree, hydrated and keyed by the id of the blok node that
 * carries them.
 *
 * The node list stays plain arrays exactly as Storyblok sent it, so it can be
 * handed to a renderer; the hydrated components sit beside it rather than inside
 * it, reachable by the key a renderer holds when it meets a blok node. Writing
 * them into the tree would leave objects where every PHP renderer expects
 * ['component' => ...].
 *
 * The array properties are non-nullable with [] defaults on purpose:
 * AbstractTransfer::toArray(true) runs foreach() over every property whose
 * declared type is array, so a null one emits a warning. See AssetTransfer for
 * why the scalar is nullable instead.
 *
 * Every setter takes mixed and guards its own type, because richtext is
 * editor-controlled content and a malformed document must degrade rather than
 * fatal. declare(strict_types=1) binds at the call site and upstream's
 * AbstractTransfer has none, so fromArray() reaches these in weak mode, where
 * the two native signatures fail in opposite directions: ?string would quietly
 * coerce a numeric 'type' to '123', while ?array raises a TypeError, since weak
 * mode will not coerce a scalar into an array. Each setter therefore discards
 * what it cannot use and falls back to the property's default. The narrower
 * param annotations keep direct callers honest under PHPStan.
 */
final class RichtextTransfer extends AbstractTransfer
{
    /**
     * Built from the tree by the hydrator rather than sent by Storyblok, so it
     * is not part of the document a renderer receives.
     */
    private const DERIVED_PROPERTY = 'bloks';

    /**
     * storyblok/richtext-resolver reads $data['content'] unguarded, so the key
     * is emitted even when it is empty.
     */
    private const ALWAYS_EMITTED = 'content';

    private ?string $type = null;

    /**
     * @var array<string, mixed>
     */
    private array $attrs = [];

    /**
     * @var array<mixed>
     */
    private array $content = [];

    /**
     * Keyed by the blok node's attrs.id. A list may hold transfers, raw arrays
     * for components with no generated class, or both.
     *
     * @var array<string, list<mixed>>
     */
    private array $bloks = [];

    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * A discarded type leaves toDocument() omitting the key, which a renderer
     * copes with - a fake node type reaching its dispatch it does not.
     *
     * @param string|null $type
     */
    public function setType(mixed $type): self
    {
        $this->type = is_string($type) ? $type : null;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttrs(): array
    {
        return $this->attrs;
    }

    /**
     * @param array<string, mixed>|null $attrs
     */
    public function setAttrs(mixed $attrs): self
    {
        $this->attrs = is_array($attrs) ? $attrs : [];

        return $this;
    }

    /**
     * @return array<mixed>
     */
    public function getContent(): array
    {
        return $this->content;
    }

    /**
     * @param array<mixed>|null $content
     */
    public function setContent(mixed $content): self
    {
        $this->content = is_array($content) ? $content : [];

        return $this;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function getBloks(): array
    {
        return $this->bloks;
    }

    /**
     * @param array<string, list<mixed>>|null $bloks
     */
    public function setBloks(mixed $bloks): self
    {
        $this->bloks = is_array($bloks) ? $bloks : [];

        return $this;
    }

    /**
     * The document shaped for a renderer.
     *
     * Renderers take the document node rather than the bare node list -
     * storyblok/richtext-resolver reads $data['content'] - so getContent() is
     * not what they want.
     *
     * Derived from the property set rather than a hand-written key list, so a
     * document key added to this class later reaches a renderer without anyone
     * having to remember this method. The one thing it must know by name is
     * which properties are not part of the document.
     *
     * get_object_vars($this) rather than toArray(): called from inside the class
     * it sees the private properties too, in declaration order, without putting
     * a ReflectionClass construction and a getProperties() walk on the render
     * path. Constants are not object vars, so the two above stay out of it.
     *
     * Close to the payload but deliberately not a faithful round trip of it:
     *
     * - 'content' is always emitted, even for a payload that carried no such
     *   key, because the resolver reads it unguarded.
     * - every other key is dropped when empty, so an explicit "attrs": {} does
     *   not come back out. This mirrors the payloads Storyblok sends, which
     *   omit an untouched optional key rather than sending it empty.
     * - the keys come out in property-declaration order whatever order they
     *   arrived in. Immaterial to a renderer, but assertSame() on arrays is
     *   order-sensitive.
     *
     * @return array<string, mixed>
     */
    public function toDocument(): array
    {
        $document = get_object_vars($this);

        unset($document[self::DERIVED_PROPERTY]);

        foreach ($document as $key => $value) {
            if ($key !== self::ALWAYS_EMITTED && ($value === null || $value === [])) {
                unset($document[$key]);
            }
        }

        return $document;
    }
}
