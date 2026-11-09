<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Hydration\ComponentClassResolver;
use Tlab\StoryblokTransfers\Tests\Fixture\NestedFixtureTransfer;

final class ComponentClassResolverTest extends TestCase
{
    private const FIXTURE_NAMESPACE = 'Tlab\\StoryblokTransfers\\Tests\\Fixture';

    public function testResolvesAComponentNameToItsTransferClass(): void
    {
        $resolver = new ComponentClassResolver(self::FIXTURE_NAMESPACE);

        self::assertSame(NestedFixtureTransfer::class, $resolver->resolve('nested_fixture'));
    }

    public function testAppliesTheSharedNameFormatting(): void
    {
        $resolver = new ComponentClassResolver(self::FIXTURE_NAMESPACE);

        // The formatter turns separators into PascalCase, so all three spellings
        // reach the same class the generator would have written.
        self::assertSame(NestedFixtureTransfer::class, $resolver->resolve('nested-fixture'));
        self::assertSame(NestedFixtureTransfer::class, $resolver->resolve('nested.fixture'));
    }

    public function testToleratesATrailingSeparatorOnTheNamespace(): void
    {
        $resolver = new ComponentClassResolver(self::FIXTURE_NAMESPACE . '\\');

        self::assertSame(NestedFixtureTransfer::class, $resolver->resolve('nested_fixture'));
    }

    public function testReturnsNullForAComponentWithNoGeneratedClass(): void
    {
        $resolver = new ComponentClassResolver(self::FIXTURE_NAMESPACE);

        self::assertNull($resolver->resolve('no_such_component'));
    }

    public function testReturnsNullForAClassThatIsNotATransfer(): void
    {
        // NotATransferTransfer is a real class the naming convention finds,
        // but it does not extend AbstractTransfer, so this exercises the
        // is_subclass_of guard rather than the class_exists one.
        $resolver = new ComponentClassResolver(self::FIXTURE_NAMESPACE);

        self::assertNull($resolver->resolve('not_a_transfer'));
    }

    public function testReadsTheComponentKeyOutOfAContentArray(): void
    {
        $resolver = new ComponentClassResolver(self::FIXTURE_NAMESPACE);

        self::assertSame(
            NestedFixtureTransfer::class,
            $resolver->resolveFromContent(['component' => 'nested_fixture', 'headline' => 'x'])
        );
    }

    public function testReturnsNullWhenTheContentCarriesNoComponent(): void
    {
        $resolver = new ComponentClassResolver(self::FIXTURE_NAMESPACE);

        self::assertNull($resolver->resolveFromContent(['headline' => 'x']));
        self::assertNull($resolver->resolveFromContent(['component' => '']));
        self::assertNull($resolver->resolveFromContent(['component' => 42]));
    }
}
