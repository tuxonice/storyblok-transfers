<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Schema\PropertyNameNormalizer;

final class PropertyNameNormalizerTest extends TestCase
{
    private PropertyNameNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new PropertyNameNormalizer();
    }

    public function testConvertsSnakeCaseToCamelCase(): void
    {
        self::assertSame('productName', $this->normalizer->normalize('product_name'));
    }

    public function testLeavesSingleLowercaseWordUnchanged(): void
    {
        self::assertSame('code', $this->normalizer->normalize('code'));
    }

    public function testCollapsesMultipleSegments(): void
    {
        self::assertSame('seoMetaTitle', $this->normalizer->normalize('seo_meta_title'));
    }

    public function testRejectsNameContainingDigits(): void
    {
        self::assertNull($this->normalizer->normalize('headline_2'));
    }

    public function testRejectsNameWithConsecutiveCapitals(): void
    {
        self::assertNull($this->normalizer->normalize('CTA'));
    }

    public function testRejectsEmptyName(): void
    {
        self::assertNull($this->normalizer->normalize(''));
    }

    /**
     * Whatever we emit must equal the key AbstractTransfer::fromArray() derives
     * from the raw Storyblok key, or the property never hydrates.
     */
    public function testNormalizedNameMatchesFromArrayKeyDerivation(): void
    {
        foreach (['product_name', 'code', 'seo_meta_title', 'cta_url'] as $rawKey) {
            $derived = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $rawKey))));

            self::assertSame(
                $derived,
                $this->normalizer->normalize($rawKey),
                sprintf('normalize(%s) must match fromArray key derivation', $rawKey)
            );
        }
    }
}
