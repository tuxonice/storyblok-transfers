<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Schema\ComponentNameFormatter;

final class ComponentNameFormatterTest extends TestCase
{
    private ComponentNameFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ComponentNameFormatter();
    }

    public function testCapitalisesASingleWord(): void
    {
        self::assertSame('Hero', $this->formatter->toTransferName('hero'));
    }

    public function testTreatsUnderscoreAsAWordSeparator(): void
    {
        self::assertSame('ProductCore', $this->formatter->toTransferName('product_core'));
    }

    public function testTreatsHyphenAsAWordSeparator(): void
    {
        self::assertSame('ProductDetailPage', $this->formatter->toTransferName('product-detail-page'));
    }

    public function testTreatsDotAsAWordSeparator(): void
    {
        self::assertSame('PageHero', $this->formatter->toTransferName('page.hero'));
    }

    public function testLeavesAnAlreadyPascalCasedNameIntact(): void
    {
        self::assertSame('Hero', $this->formatter->toTransferName('Hero'));
    }
}
