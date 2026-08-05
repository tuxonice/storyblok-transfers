<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Schema\FieldTypeMapper;
use Tlab\StoryblokTransfers\Transfers\AssetTransfer;
use Tlab\StoryblokTransfers\Transfers\BlokTransfer;
use Tlab\StoryblokTransfers\Transfers\LinkTransfer;

final class FieldTypeMapperTest extends TestCase
{
    private FieldTypeMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new FieldTypeMapper();
    }

    public function testMapsTextToNullableString(): void
    {
        self::assertSame(
            ['name' => 'headline', 'type' => 'string', 'nullable' => true],
            $this->mapper->map('headline', ['type' => 'text'])
        );
    }

    public function testMapsNumberToNullableFloat(): void
    {
        self::assertSame(
            ['name' => 'price', 'type' => 'float', 'nullable' => true],
            $this->mapper->map('price', ['type' => 'number'])
        );
    }

    public function testMapsBooleanToNullableBool(): void
    {
        self::assertSame(
            ['name' => 'isActive', 'type' => 'bool', 'nullable' => true],
            $this->mapper->map('is_active', ['type' => 'boolean'])
        );
    }

    public function testMapsRichtextToNullableArray(): void
    {
        self::assertSame(
            ['name' => 'body', 'type' => 'array', 'nullable' => true],
            $this->mapper->map('body', ['type' => 'richtext'])
        );
    }

    public function testMapsUnknownTypeToNullableArrayFallback(): void
    {
        self::assertSame(
            ['name' => 'someWidget', 'type' => 'array', 'nullable' => true],
            $this->mapper->map('some_widget', ['type' => 'custom_plugin_xyz'])
        );
    }

    public function testMapsStoryRelationToNullableStringUuid(): void
    {
        self::assertSame(
            ['name' => 'relatedProduct', 'type' => 'string', 'nullable' => true],
            $this->mapper->map('related_product', ['type' => 'story'])
        );
    }

    public function testMapsOptionsToStringArrayWithSingular(): void
    {
        self::assertSame(
            ['name' => 'tags', 'type' => 'string[]', 'singular' => 'tag'],
            $this->mapper->map('tags', ['type' => 'options'])
        );
    }

    public function testDerivesSingularFromIesPlural(): void
    {
        $mapped = $this->mapper->map('categories', ['type' => 'options']);

        self::assertIsArray($mapped);
        self::assertSame('category', $mapped['singular']);
    }

    public function testSuffixesSingularWhenNameIsNotPlural(): void
    {
        $mapped = $this->mapper->map('gallery', ['type' => 'multiasset']);

        self::assertIsArray($mapped);
        self::assertSame('galleryItem', $mapped['singular']);
    }

    /**
     * Two array fields in one component must not produce the same singular,
     * or the generated class gets two methods with the same name.
     */
    public function testDistinctFieldsProduceDistinctSingulars(): void
    {
        $tags = $this->mapper->map('tags', ['type' => 'options']);
        $categories = $this->mapper->map('categories', ['type' => 'options']);

        self::assertIsArray($tags);
        self::assertIsArray($categories);
        self::assertNotSame($tags['singular'], $categories['singular']);
    }

    public function testMapsAssetToNullableAssetTransferWithExplicitNamespace(): void
    {
        self::assertSame(
            [
                'name' => 'image',
                'type' => 'AssetTransfer',
                'nullable' => true,
                'namespace' => AssetTransfer::class,
            ],
            $this->mapper->map('image', ['type' => 'asset'])
        );
    }

    public function testMapsMultiassetToAssetTransferArray(): void
    {
        self::assertSame(
            [
                'name' => 'images',
                'type' => 'AssetTransfer[]',
                'singular' => 'image',
                'namespace' => AssetTransfer::class,
            ],
            $this->mapper->map('images', ['type' => 'multiasset'])
        );
    }

    public function testMapsLinkToNullableLinkTransfer(): void
    {
        self::assertSame(
            [
                'name' => 'cta',
                'type' => 'LinkTransfer',
                'nullable' => true,
                'namespace' => LinkTransfer::class,
            ],
            $this->mapper->map('cta', ['type' => 'multilink'])
        );
    }

    public function testMapsBloksToBlokTransferArray(): void
    {
        self::assertSame(
            [
                'name' => 'bloks',
                'type' => 'BlokTransfer[]',
                'singular' => 'blok',
                'namespace' => BlokTransfer::class,
            ],
            $this->mapper->map('bloks', ['type' => 'bloks'])
        );
    }

    public function testReturnsNullForUnrepresentableFieldName(): void
    {
        self::assertNull($this->mapper->map('headline_2', ['type' => 'text']));
    }

    public function testSkipsStoryblokTabPseudoFields(): void
    {
        self::assertNull($this->mapper->map('tab-1', ['type' => 'tab']));
    }

    public function testIdentifiesTabAsAPseudoField(): void
    {
        self::assertTrue($this->mapper->isPseudoField(['type' => 'tab']));
    }

    public function testIdentifiesSectionAsAPseudoField(): void
    {
        self::assertTrue($this->mapper->isPseudoField(['type' => 'section']));
    }

    public function testDoesNotTreatContentFieldsAsPseudoFields(): void
    {
        self::assertFalse($this->mapper->isPseudoField(['type' => 'text']));
        self::assertFalse($this->mapper->isPseudoField(['type' => 'richtext']));
    }

    /**
     * Array types must never carry nullable - transfer-objects throws
     * ArrayTypeNullableException for that combination.
     */
    public function testArrayTypesAreNeverNullable(): void
    {
        foreach (['options', 'multiasset', 'bloks'] as $storyblokType) {
            $mapped = $this->mapper->map('items', ['type' => $storyblokType]);

            self::assertIsArray($mapped);
            self::assertArrayNotHasKey('nullable', $mapped, $storyblokType . ' must not be nullable');
            self::assertArrayHasKey('singular', $mapped, $storyblokType . ' must define a singular');
        }
    }

    public function testSingularIsSchemaValidForEveryArrayType(): void
    {
        foreach (['options', 'multiasset', 'bloks'] as $storyblokType) {
            $mapped = $this->mapper->map('items', ['type' => $storyblokType]);

            self::assertIsArray($mapped);
            self::assertMatchesRegularExpression(
                '/(^[a-z]|[A-Z0-9])[a-z]*$/',
                $mapped['singular'],
                $storyblokType . ' singular must match the schema pattern'
            );
        }
    }
}
