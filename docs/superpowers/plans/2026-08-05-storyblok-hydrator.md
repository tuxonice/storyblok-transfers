# StoryblokHydrator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hydrate a Storyblok content array into a fully populated generated transfer graph, which `AbstractTransfer::fromArray()` cannot do on its own.

**Architecture:** Pre-convert only the payload values that must become objects, then delegate assignment to upstream's `fromArray()`, which already handles key camel-casing and setter dispatch correctly. Property types are discovered by reflection and cached per class; array element types are resolved by scanning the generated `add{Singular}()` method's parameter type, because PHP resolved that FQCN at compile time and reflection does not expose `use` statements.

**Tech Stack:** PHP 8.1+, PHPUnit 10.5, phpstan level 8, phpcs PSR-12, all run through Docker.

## Global Constraints

- Everything runs in Docker. Never invoke `php`, `composer`, `phpunit` on the host.
  - Tests: `docker compose run --rm php vendor/bin/phpunit`
  - Static analysis: `docker compose run --rm php vendor/bin/phpstan analyse --no-progress`
  - Code style: `docker compose run --rm php vendor/bin/phpcs`
- Every file starts with `<?php` then a blank line then `declare(strict_types=1);`.
- PSR-12, max line length 120 characters.
- phpstan runs at level 8: annotate every array shape, never leave a `mixed` unnarrowed where it can be narrowed.
- Namespace root is `Tlab\StoryblokTransfers\` mapped to `src/`; test root is `Tlab\StoryblokTransfers\Tests\` mapped to `tests/`.
- TDD is mandatory. Write the test, watch it fail for the right reason, then implement.
- Tests must exercise real code. Do not mock the generator, the filesystem, or the transfer classes. Integration tests generate real classes into a temp directory via the `TempDirectory` trait in `tests/TempDirectory.php`.
- Commit after each task.

---

### Task 1: Extract `ComponentNameFormatter`

The rule that turns a Storyblok component name into a transfer name is currently
duplicated in two private methods. The hydrator needs it too, so extract it
before adding a third copy.

**Files:**
- Create: `src/Schema/ComponentNameFormatter.php`
- Create: `tests/Unit/ComponentNameFormatterTest.php`
- Modify: `src/Definition/DefinitionFileWriter.php` — replace private `toPascalCase()`
- Modify: `src/StoryblokTransferGenerator.php` — replace private `transferNameOf()`

**Interfaces:**
- Consumes: nothing.
- Produces: `ComponentNameFormatter::toTransferName(string $componentName): string`.
  `product_core` → `ProductCore`. Later tasks build a class name as
  `$namespace . '\\' . $formatter->toTransferName($component) . 'Transfer'`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ComponentNameFormatterTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit --filter ComponentNameFormatterTest`
Expected: FAIL — `Error: Class "Tlab\StoryblokTransfers\Schema\ComponentNameFormatter" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Schema/ComponentNameFormatter.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Schema;

/**
 * Turns a Storyblok component name into the name of its transfer.
 *
 * Shared by the definition writer, the generator and the hydrator: all three
 * have to agree on how `product_core` becomes `ProductCore`, or the hydrator
 * looks up classes the generator never wrote.
 */
final class ComponentNameFormatter
{
    public function toTransferName(string $componentName): string
    {
        $spaced = str_replace(['_', '-', '.'], ' ', $componentName);

        return str_replace(' ', '', ucwords($spaced));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit --filter ComponentNameFormatterTest`
Expected: PASS (5 tests)

- [ ] **Step 5: Replace the copy in `DefinitionFileWriter`**

In `src/Definition/DefinitionFileWriter.php`, add the import
`use Tlab\StoryblokTransfers\Schema\ComponentNameFormatter;`, then add a
constructor and delete the private `toPascalCase()` method:

```php
    public function __construct(
        private readonly ComponentNameFormatter $nameFormatter = new ComponentNameFormatter(),
    ) {
    }
```

Change the first line of `write()` from
`$transferName = $this->toPascalCase($componentName);` to:

```php
        $transferName = $this->nameFormatter->toTransferName($componentName);
```

Then delete the whole `toPascalCase()` method, including its docblock.

- [ ] **Step 6: Replace the copy in `StoryblokTransferGenerator`**

In `src/StoryblokTransferGenerator.php`, add a `ComponentNameFormatter` property
alongside the existing ones:

```php
    private readonly ComponentNameFormatter $nameFormatter;
```

Assign it in the constructor body next to the other assignments:

```php
        $this->nameFormatter = new ComponentNameFormatter();
```

Replace the call `$componentNames[] = $this->transferNameOf($component['name']);` with:

```php
            $componentNames[] = $this->nameFormatter->toTransferName($component['name']);
```

Then delete the whole private `transferNameOf()` method, and add
`use Tlab\StoryblokTransfers\Schema\ComponentNameFormatter;` alongside
the file's other `Schema` imports.

- [ ] **Step 7: Run the full suite and static analysis**

Run: `docker compose run --rm php vendor/bin/phpunit`
Expected: PASS, 75 tests. The existing `DefinitionFileWriterTest` and
`StoryblokTransferGeneratorTest` PascalCase assertions must still pass — that is
what proves the extraction preserved behaviour.

Run: `docker compose run --rm php vendor/bin/phpstan analyse --no-progress`
Expected: `[OK] No errors`

Run: `docker compose run --rm php vendor/bin/phpcs`
Expected: no violations

- [ ] **Step 8: Commit**

```bash
git add src/Schema/ComponentNameFormatter.php tests/Unit/ComponentNameFormatterTest.php \
        src/Definition/DefinitionFileWriter.php src/StoryblokTransferGenerator.php
git commit -m "Extract ComponentNameFormatter from its two private copies"
```

---

### Task 2: `PropertyType` value object

**Files:**
- Create: `src/Hydration/PropertyType.php`
- Create: `tests/Unit/PropertyTypeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `new PropertyType(?string $transferClass = null, ?string $elementTransferClass = null)`
  - `PropertyType::$transferClass` — FQCN when the property holds one nested transfer
  - `PropertyType::$elementTransferClass` — FQCN when the property is an array of transfers
  - `PropertyType::needsConversion(): bool` — false when both are null

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/PropertyTypeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Hydration\PropertyType;
use Tlab\StoryblokTransfers\Transfers\AssetTransfer;

final class PropertyTypeTest extends TestCase
{
    public function testAPlainPropertyNeedsNoConversion(): void
    {
        self::assertFalse((new PropertyType())->needsConversion());
    }

    public function testANestedTransferNeedsConversion(): void
    {
        $type = new PropertyType(transferClass: AssetTransfer::class);

        self::assertTrue($type->needsConversion());
        self::assertSame(AssetTransfer::class, $type->transferClass);
        self::assertNull($type->elementTransferClass);
    }

    public function testATransferArrayNeedsConversion(): void
    {
        $type = new PropertyType(elementTransferClass: AssetTransfer::class);

        self::assertTrue($type->needsConversion());
        self::assertSame(AssetTransfer::class, $type->elementTransferClass);
        self::assertNull($type->transferClass);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit --filter PropertyTypeTest`
Expected: FAIL — `Error: Class "Tlab\StoryblokTransfers\Hydration\PropertyType" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Hydration/PropertyType.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Hydration;

/**
 * What a single transfer property needs doing to it before assignment.
 */
final class PropertyType
{
    /**
     * @param class-string|null $transferClass Set when the property holds one nested transfer.
     * @param class-string|null $elementTransferClass Set when the property is an array of transfers.
     */
    public function __construct(
        public readonly ?string $transferClass = null,
        public readonly ?string $elementTransferClass = null,
    ) {
    }

    public function needsConversion(): bool
    {
        return $this->transferClass !== null || $this->elementTransferClass !== null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit --filter PropertyTypeTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Hydration/PropertyType.php tests/Unit/PropertyTypeTest.php
git commit -m "Add PropertyType value object for hydration"
```

---

### Task 3: `PropertyTypeResolver`

Discovers, by reflection, which properties of a transfer class need conversion.

**Files:**
- Create: `src/Hydration/PropertyTypeResolver.php`
- Create: `tests/Unit/PropertyTypeResolverTest.php`
- Create: `tests/Fixture/NestedFixtureTransfer.php`
- Create: `tests/Fixture/ScalarFixtureTransfer.php`

**Interfaces:**
- Consumes: `PropertyType` from Task 2.
- Produces: `PropertyTypeResolver::resolve(string $transferClass): array<string, PropertyType>`,
  keyed by property name. Properties needing no conversion are omitted from the map.

**Background the implementer needs:**

A generated class looks like this. Note that the element type of the array
property appears nowhere in the property's own PHP type — only in the docblock
as a short name, and in the `add*` method's parameter type as a resolved FQCN:

```php
class PageTransfer extends AbstractTransfer
{
    /**
     * @var array<BlokTransfer>
     */
    private array $body = [];

    public function addBodyItem(BlokTransfer $bodyItem): self { /* ... */ }
}
```

Reflection cannot read `use` statements, so resolving `BlokTransfer` from the
docblock alone would mean reimplementing PHP's name resolution. Instead, scan the
class's methods for a parameter whose type's **short name** matches the docblock's
short name; PHP already resolved that parameter type to an FQCN.

- [ ] **Step 1: Write the fixtures**

Create `tests/Fixture/NestedFixtureTransfer.php`. This mirrors the shape the
generator produces — a nested transfer, a transfer array with an `add*` method, a
scalar array, and a plain scalar:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Fixture;

use Tlab\StoryblokTransfers\Transfers\AssetTransfer;
use Tlab\StoryblokTransfers\Transfers\BlokTransfer;
use Tlab\TransferObjects\AbstractTransfer;

class NestedFixtureTransfer extends AbstractTransfer
{
    /**
     * @var AssetTransfer|null
     */
    private ?AssetTransfer $image = null;

    /**
     * @var array<BlokTransfer>
     */
    private array $body = [];

    /**
     * @var array<string>
     */
    private array $tags = [];

    /**
     * @var string|null
     */
    private ?string $headline = null;

    public function getImage(): ?AssetTransfer
    {
        return $this->image;
    }

    public function setImage(?AssetTransfer $image): self
    {
        $this->image = $image;

        return $this;
    }

    /**
     * @return array<BlokTransfer>
     */
    public function getBody(): array
    {
        return $this->body;
    }

    /**
     * @param array<BlokTransfer> $body
     */
    public function setBody(array $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function addBodyItem(BlokTransfer $bodyItem): self
    {
        $this->body[] = $bodyItem;

        return $this;
    }

    /**
     * @return array<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @param array<string> $tags
     */
    public function setTags(array $tags): self
    {
        $this->tags = $tags;

        return $this;
    }

    public function addTag(string $tag): self
    {
        $this->tags[] = $tag;

        return $this;
    }

    public function getHeadline(): ?string
    {
        return $this->headline;
    }

    public function setHeadline(?string $headline): self
    {
        $this->headline = $headline;

        return $this;
    }
}
```

Create `tests/Fixture/ScalarFixtureTransfer.php`, which mirrors a post-processed
`array<mixed>` property — the richtext case, which has no `add*` method because
`GeneratedCodeFixer` removed it:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Fixture;

use Tlab\TransferObjects\AbstractTransfer;

class ScalarFixtureTransfer extends AbstractTransfer
{
    /**
     * @var array<mixed>|null
     */
    private ?array $content = null;

    /**
     * @return array<mixed>|null
     */
    public function getContent(): ?array
    {
        return $this->content;
    }

    /**
     * @param array<mixed>|null $content
     */
    public function setContent(?array $content): self
    {
        $this->content = $content;

        return $this;
    }
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/PropertyTypeResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Hydration\PropertyTypeResolver;
use Tlab\StoryblokTransfers\Tests\Fixture\NestedFixtureTransfer;
use Tlab\StoryblokTransfers\Tests\Fixture\ScalarFixtureTransfer;
use Tlab\StoryblokTransfers\Transfers\AssetTransfer;
use Tlab\StoryblokTransfers\Transfers\BlokTransfer;

final class PropertyTypeResolverTest extends TestCase
{
    private PropertyTypeResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PropertyTypeResolver();
    }

    public function testFindsANestedTransferProperty(): void
    {
        $map = $this->resolver->resolve(NestedFixtureTransfer::class);

        self::assertArrayHasKey('image', $map);
        self::assertSame(AssetTransfer::class, $map['image']->transferClass);
        self::assertNull($map['image']->elementTransferClass);
    }

    public function testResolvesArrayElementTypeThroughTheAddMethod(): void
    {
        $map = $this->resolver->resolve(NestedFixtureTransfer::class);

        self::assertArrayHasKey('body', $map);
        self::assertSame(BlokTransfer::class, $map['body']->elementTransferClass);
        self::assertNull($map['body']->transferClass);
    }

    public function testOmitsScalarArrayProperties(): void
    {
        self::assertArrayNotHasKey('tags', $this->resolver->resolve(NestedFixtureTransfer::class));
    }

    public function testOmitsPlainScalarProperties(): void
    {
        self::assertArrayNotHasKey('headline', $this->resolver->resolve(NestedFixtureTransfer::class));
    }

    public function testOmitsMixedArrayProperties(): void
    {
        self::assertSame([], $this->resolver->resolve(ScalarFixtureTransfer::class));
    }

    public function testReturnsTheSameMapOnRepeatedCalls(): void
    {
        self::assertEquals(
            $this->resolver->resolve(NestedFixtureTransfer::class),
            $this->resolver->resolve(NestedFixtureTransfer::class)
        );
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit --filter PropertyTypeResolverTest`
Expected: FAIL — `Error: Class "Tlab\StoryblokTransfers\Hydration\PropertyTypeResolver" not found`

- [ ] **Step 4: Write minimal implementation**

Create `src/Hydration/PropertyTypeResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Hydration;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Tlab\TransferObjects\TransferInterface;

/**
 * Works out which properties of a transfer class hold nested transfers.
 *
 * Single nested transfers come straight from the property's declared type.
 * Array element types do not: the declared type is just `array`, and the
 * element only appears as a short name in the `@var array<Short>` docblock.
 * Reflection cannot read `use` statements, so rather than reimplement PHP's
 * name resolution we look for a method parameter whose type has the same short
 * name - the generated `add{Singular}()` method - and take its FQCN, which PHP
 * resolved at compile time.
 */
final class PropertyTypeResolver
{
    /** @var array<class-string, array<string, PropertyType>> */
    private array $cache = [];

    /**
     * @param class-string $transferClass
     *
     * @return array<string, PropertyType> Keyed by property name; properties
     *                                     needing no conversion are omitted.
     */
    public function resolve(string $transferClass): array
    {
        if (isset($this->cache[$transferClass])) {
            return $this->cache[$transferClass];
        }

        $reflection = new ReflectionClass($transferClass);
        $shortNameToFqcn = $this->classNamesFromMethodParameters($reflection);

        $map = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PRIVATE) as $property) {
            $type = $this->propertyType($property, $shortNameToFqcn);

            if ($type->needsConversion()) {
                $map[$property->getName()] = $type;
            }
        }

        return $this->cache[$transferClass] = $map;
    }

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return array<string, class-string> Short class name => FQCN.
     */
    private function classNamesFromMethodParameters(ReflectionClass $reflection): array
    {
        $names = [];

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                /** @var class-string $fqcn */
                $fqcn = $type->getName();
                $shortName = substr((string) strrchr('\\' . $fqcn, '\\'), 1);
                $names[$shortName] = $fqcn;
            }
        }

        return $names;
    }

    /**
     * @param array<string, class-string> $shortNameToFqcn
     */
    private function propertyType(ReflectionProperty $property, array $shortNameToFqcn): PropertyType
    {
        $type = $property->getType();

        if (!$type instanceof ReflectionNamedType) {
            return new PropertyType();
        }

        if (!$type->isBuiltin()) {
            /** @var class-string $fqcn */
            $fqcn = $type->getName();

            return is_a($fqcn, TransferInterface::class, true)
                ? new PropertyType(transferClass: $fqcn)
                : new PropertyType();
        }

        if ($type->getName() !== 'array') {
            return new PropertyType();
        }

        $elementClass = $this->elementClass($property, $shortNameToFqcn);

        return $elementClass === null
            ? new PropertyType()
            : new PropertyType(elementTransferClass: $elementClass);
    }

    /**
     * @param array<string, class-string> $shortNameToFqcn
     *
     * @return class-string|null
     */
    private function elementClass(ReflectionProperty $property, array $shortNameToFqcn): ?string
    {
        $docComment = $property->getDocComment();

        if ($docComment === false) {
            return null;
        }

        if (preg_match('/@var\s+array<\s*([A-Za-z_][A-Za-z0-9_]*)\s*>/', $docComment, $matches) !== 1) {
            return null;
        }

        $fqcn = $shortNameToFqcn[$matches[1]] ?? null;

        if ($fqcn === null || !is_a($fqcn, TransferInterface::class, true)) {
            return null;
        }

        return $fqcn;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit --filter PropertyTypeResolverTest`
Expected: PASS (6 tests)

- [ ] **Step 6: Run static analysis and style**

Run: `docker compose run --rm php vendor/bin/phpstan analyse --no-progress`
Expected: `[OK] No errors`

Run: `docker compose run --rm php vendor/bin/phpcs`
Expected: no violations

- [ ] **Step 7: Commit**

```bash
git add src/Hydration/PropertyTypeResolver.php tests/Unit/PropertyTypeResolverTest.php tests/Fixture/
git commit -m "Add PropertyTypeResolver to discover nested transfer properties"
```

---

### Task 4: `HydrationException`

**Files:**
- Create: `src/Hydration/HydrationException.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `HydrationException extends RuntimeException`, thrown by Task 5.

No test of its own — it has no behaviour. Task 5's tests assert it is thrown.

- [ ] **Step 1: Create the class**

Create `src/Hydration/HydrationException.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Hydration;

use RuntimeException;

/**
 * Thrown for programming errors only - a target class that does not exist or is
 * not a transfer. Content drift never throws: an unresolvable blok degrades to
 * a raw array instead.
 */
final class HydrationException extends RuntimeException
{
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Hydration/HydrationException.php
git commit -m "Add HydrationException"
```

---

### Task 5: `StoryblokHydrator`

**Files:**
- Create: `src/Hydration/StoryblokHydrator.php`
- Create: `tests/Unit/StoryblokHydratorTest.php`

**Interfaces:**
- Consumes: `PropertyType`, `PropertyTypeResolver`, `HydrationException`,
  `ComponentNameFormatter::toTransferName()`, and the existing
  `PropertyNameNormalizer::normalize()` from `src/Schema/PropertyNameNormalizer.php`.
- Produces:
  - `new StoryblokHydrator(string $namespace)`
  - `StoryblokHydrator::hydrate(string $transferClass, array $content): AbstractTransfer`

**Background the implementer needs:**

`AbstractTransfer::fromArray()` already camel-cases payload keys, dispatches to
setters, and ignores unknown keys. The only thing it gets wrong is passing raw
arrays to setters that demand transfer instances. So convert the values first,
then hand the whole array to `fromArray()`.

`PropertyNameNormalizer::normalize()` derives the property name from a payload key
using the identical rule `fromArray()` uses, and returns `null` for keys that
cannot be a valid property (e.g. `headline_2`). A `null` here simply means "no
conversion", which is correct — no such property was generated.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/StoryblokHydratorTest.php`. These use the fixtures from Task 3,
so they need no generator run:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Hydration\HydrationException;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;
use Tlab\StoryblokTransfers\Tests\Fixture\NestedFixtureTransfer;
use Tlab\StoryblokTransfers\Tests\Fixture\ScalarFixtureTransfer;
use Tlab\StoryblokTransfers\Transfers\AssetTransfer;

final class StoryblokHydratorTest extends TestCase
{
    private StoryblokHydrator $hydrator;

    protected function setUp(): void
    {
        // Fixture transfers double as the "generated" namespace here.
        $this->hydrator = new StoryblokHydrator('Tlab\\StoryblokTransfers\\Tests\\Fixture');
    }

    public function testHydratesANestedAssetIntoATransfer(): void
    {
        $transfer = $this->hydrator->hydrate(NestedFixtureTransfer::class, [
            'image' => ['id' => 42, 'filename' => 'hero.jpg', 'alt' => 'Hero'],
        ]);

        self::assertInstanceOf(NestedFixtureTransfer::class, $transfer);
        $image = $transfer->getImage();
        self::assertInstanceOf(AssetTransfer::class, $image);
        self::assertSame(42, $image->getId());
        self::assertSame('hero.jpg', $image->getFilename());
        self::assertSame('Hero', $image->getAlt());
    }

    public function testStillAssignsPlainScalars(): void
    {
        $transfer = $this->hydrator->hydrate(NestedFixtureTransfer::class, ['headline' => 'Hello']);

        self::assertInstanceOf(NestedFixtureTransfer::class, $transfer);
        self::assertSame('Hello', $transfer->getHeadline());
    }

    public function testPassesScalarArraysThroughUntouched(): void
    {
        $transfer = $this->hydrator->hydrate(NestedFixtureTransfer::class, ['tags' => ['a', 'b']]);

        self::assertInstanceOf(NestedFixtureTransfer::class, $transfer);
        self::assertSame(['a', 'b'], $transfer->getTags());
    }

    public function testPassesRichtextStructuresThroughUntouched(): void
    {
        $nodes = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];

        $transfer = $this->hydrator->hydrate(ScalarFixtureTransfer::class, ['content' => $nodes]);

        self::assertInstanceOf(ScalarFixtureTransfer::class, $transfer);
        self::assertSame($nodes, $transfer->getContent());
    }

    public function testTurnsAnEmptyStringAssetIntoNullRatherThanThrowing(): void
    {
        $transfer = $this->hydrator->hydrate(NestedFixtureTransfer::class, ['image' => '']);

        self::assertInstanceOf(NestedFixtureTransfer::class, $transfer);
        self::assertNull($transfer->getImage());
    }

    public function testTurnsANullAssetIntoNull(): void
    {
        $transfer = $this->hydrator->hydrate(NestedFixtureTransfer::class, ['image' => null]);

        self::assertInstanceOf(NestedFixtureTransfer::class, $transfer);
        self::assertNull($transfer->getImage());
    }

    public function testIgnoresKeysThatMatchNoProperty(): void
    {
        $transfer = $this->hydrator->hydrate(NestedFixtureTransfer::class, [
            'headline' => 'Hello',
            'headline_2' => 'ignored',
            '_uid' => 'abc',
        ]);

        self::assertInstanceOf(NestedFixtureTransfer::class, $transfer);
        self::assertSame('Hello', $transfer->getHeadline());
    }

    public function testRejectsAClassThatDoesNotExist(): void
    {
        // Annotated rather than passed inline: phpstan rejects an unknown class
        // literal where class-string is expected, and an inline ignore would
        // itself be flagged as unmatched once that is the only error.
        /** @var class-string $missing */
        $missing = 'App\\Nope\\MissingTransfer';

        $this->expectException(HydrationException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        $this->hydrator->hydrate($missing, []);
    }

    public function testRejectsAClassThatIsNotATransfer(): void
    {
        $this->expectException(HydrationException::class);
        $this->expectExceptionMessageMatches('/not a transfer/');

        $this->hydrator->hydrate(self::class, []);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryblokHydratorTest`
Expected: FAIL — `Error: Class "Tlab\StoryblokTransfers\Hydration\StoryblokHydrator" not found`

- [ ] **Step 3: Write minimal implementation**

Create `src/Hydration/StoryblokHydrator.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Hydration;

use Tlab\StoryblokTransfers\Schema\ComponentNameFormatter;
use Tlab\StoryblokTransfers\Schema\PropertyNameNormalizer;
use Tlab\StoryblokTransfers\Transfers\BlokTransfer;
use Tlab\TransferObjects\AbstractTransfer;

/**
 * Turns a Storyblok content array into a populated transfer graph.
 *
 * AbstractTransfer::fromArray() hands raw payload values straight to the setter,
 * so a nested asset arrives as an array where an AssetTransfer is required. This
 * converts those values first and then delegates to fromArray(), which already
 * gets key camel-casing, setter dispatch and unknown-key skipping right.
 */
final class StoryblokHydrator
{
    private readonly PropertyTypeResolver $typeResolver;

    private readonly PropertyNameNormalizer $nameNormalizer;

    private readonly ComponentNameFormatter $componentNameFormatter;

    /**
     * @param string $namespace Namespace the generated transfers live in,
     *                          e.g. 'App\DataTransferObjects'.
     */
    public function __construct(
        private readonly string $namespace,
    ) {
        $this->typeResolver = new PropertyTypeResolver();
        $this->nameNormalizer = new PropertyNameNormalizer();
        $this->componentNameFormatter = new ComponentNameFormatter();
    }

    /**
     * @param class-string $transferClass
     * @param array<string, mixed> $content A Storyblok content array.
     *
     * @throws HydrationException When $transferClass is not a usable transfer.
     */
    public function hydrate(string $transferClass, array $content): AbstractTransfer
    {
        $this->assertHydratable($transferClass);

        $types = $this->typeResolver->resolve($transferClass);
        $converted = [];

        foreach ($content as $key => $value) {
            $propertyName = $this->nameNormalizer->normalize((string) $key);
            $type = $propertyName === null ? null : ($types[$propertyName] ?? null);

            $converted[$key] = $type === null ? $value : $this->convert($type, $value);
        }

        return $transferClass::fromArray($converted);
    }

    private function convert(PropertyType $type, mixed $value): mixed
    {
        $nestedClass = $type->transferClass;

        if ($nestedClass !== null) {
            // A missing asset or link arrives as "" or null, never as an array.
            // Every generated property is nullable, so null always assigns.
            return is_array($value) ? $this->hydrate($nestedClass, $value) : null;
        }

        $elementClass = $type->elementTransferClass;

        if ($elementClass === null || !is_array($value)) {
            return $value;
        }

        return array_map(
            fn (mixed $item): mixed => $this->convertElement($elementClass, $item),
            $value
        );
    }

    /**
     * @param class-string $elementTransferClass
     */
    private function convertElement(string $elementTransferClass, mixed $item): mixed
    {
        if (!is_array($item)) {
            return $item;
        }

        $target = $elementTransferClass === BlokTransfer::class
            ? $this->resolveComponentClass($item)
            : $elementTransferClass;

        // An unresolvable component keeps its raw array: an editor adding a
        // component must not break the page.
        return $target === null ? $item : $this->hydrate($target, $item);
    }

    /**
     * @param array<string, mixed> $blok
     *
     * @return class-string|null
     */
    private function resolveComponentClass(array $blok): ?string
    {
        $component = $blok['component'] ?? null;

        if (!is_string($component) || $component === '') {
            return null;
        }

        $candidate = rtrim($this->namespace, '\\') . '\\'
            . $this->componentNameFormatter->toTransferName($component) . 'Transfer';

        if (!class_exists($candidate) || !is_subclass_of($candidate, AbstractTransfer::class)) {
            return null;
        }

        return $candidate;
    }

    /**
     * @throws HydrationException
     */
    private function assertHydratable(string $transferClass): void
    {
        if (!class_exists($transferClass)) {
            throw new HydrationException('Transfer class does not exist: ' . $transferClass);
        }

        if (!is_subclass_of($transferClass, AbstractTransfer::class)) {
            throw new HydrationException(
                sprintf('%s is not a transfer: it must extend %s', $transferClass, AbstractTransfer::class)
            );
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryblokHydratorTest`
Expected: PASS (9 tests)

- [ ] **Step 5: Run static analysis and style**

Run: `docker compose run --rm php vendor/bin/phpstan analyse --no-progress`
Expected: `[OK] No errors`

Run: `docker compose run --rm php vendor/bin/phpcs`
Expected: no violations

- [ ] **Step 6: Commit**

```bash
git add src/Hydration/StoryblokHydrator.php tests/Unit/StoryblokHydratorTest.php
git commit -m "Add StoryblokHydrator for nested transfer hydration"
```

---

### Task 6: Integration tests against real generated classes

The unit tests use hand-written fixtures. These prove the hydrator works on
whatever the generator actually emits, so they fail if the generator's output
shape changes.

**Files:**
- Create: `tests/Integration/StoryblokHydratorIntegrationTest.php`

**Interfaces:**
- Consumes: `StoryblokHydrator::hydrate()`, `StoryblokTransferGenerator`, `TempDirectory`.
- Produces: nothing.

**Background the implementer needs:**

Follow the pattern in `tests/Integration/StoryblokTransferGeneratorTest.php`: build
a `StoryblokTransferGenerator` with a Guzzle `MockHandler` returning a stubbed
components payload, generate into temp directories, then `require` the generated
files.

Use a namespace unique to this test file (`Hydrated\Gen`) so the classes cannot
clash with those defined by other integration tests in the same process.

**Assert through `toArray()`, never through the generated accessors.** The
accessors do not exist at analysis time, so `$transfer->getImage()` is a level-8
error, and silencing each one with `@phpstan-ignore-next-line` is a trap:
`reportUnmatchedIgnoredErrors` defaults to true, so any ignore that stops
matching becomes an error in its own right. `toArray()` is declared on
`AbstractTransfer`, returns `array<string, mixed>`, and exposes exactly the
hydrated property values — so it needs no suppression at all.

For the same reason, compare classes with `$value::class` after an
`assertInstanceOf(AbstractTransfer::class, $value)` narrowing step, rather than
passing a runtime-concatenated string to `assertInstanceOf()`, which expects a
`class-string`.

Assign each value to a local variable before asserting on it — phpstan narrows a
variable reliably, an array offset less so.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/StoryblokHydratorIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;
use Tlab\StoryblokTransfers\StoryblokTransferGenerator;
use Tlab\StoryblokTransfers\Tests\TempDirectory;
use Tlab\StoryblokTransfers\Transfers\AssetTransfer;
use Tlab\StoryblokTransfers\Transfers\LinkTransfer;
use Tlab\TransferObjects\AbstractTransfer;

final class StoryblokHydratorIntegrationTest extends TestCase
{
    use TempDirectory;

    private const NAMESPACE = 'Hydrated\\Gen';

    private string $definitionsPath;

    private string $outputPath;

    private StoryblokHydrator $hydrator;

    protected function setUp(): void
    {
        $this->definitionsPath = $this->makeTempDir('hyd-def');
        $this->outputPath = $this->makeTempDir('hyd-out');
        $this->hydrator = new StoryblokHydrator(self::NAMESPACE);
    }

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    public function testHydratesAnAssetFieldThatFromArrayCannotHandle(): void
    {
        $this->generate([
            ['name' => 'hero', 'schema' => ['image' => ['type' => 'asset']]],
        ]);

        $data = $this->hydrateToArray('HeroTransfer', [
            'image' => ['id' => 7, 'filename' => 'a.jpg', 'alt' => 'A'],
        ]);

        $image = $data['image'];
        self::assertInstanceOf(AssetTransfer::class, $image);
        self::assertSame(7, $image->getId());
        self::assertSame('a.jpg', $image->getFilename());
        self::assertSame('A', $image->getAlt());
    }

    public function testHydratesAMultilinkIncludingCachedUrl(): void
    {
        $this->generate([
            ['name' => 'hero', 'schema' => ['cta' => ['type' => 'multilink']]],
        ]);

        $data = $this->hydrateToArray('HeroTransfer', [
            'cta' => ['id' => 'x', 'url' => '/a', 'linktype' => 'story', 'cached_url' => 'a'],
        ]);

        $cta = $data['cta'];
        self::assertInstanceOf(LinkTransfer::class, $cta);
        self::assertSame('story', $cta->getLinktype());
        self::assertSame('a', $cta->getCachedUrl());
    }

    public function testHydratesMultiassetIntoAListOfAssetTransfers(): void
    {
        $this->generate([
            ['name' => 'hero', 'schema' => ['images' => ['type' => 'multiasset']]],
        ]);

        $data = $this->hydrateToArray('HeroTransfer', [
            'images' => [
                ['id' => 1, 'filename' => 'a.jpg'],
                ['id' => 2, 'filename' => 'b.jpg'],
            ],
        ]);

        $images = $data['images'];
        self::assertIsArray($images);
        self::assertCount(2, $images);

        $first = $images[0];
        $second = $images[1];
        self::assertInstanceOf(AssetTransfer::class, $first);
        self::assertInstanceOf(AssetTransfer::class, $second);
        self::assertSame(1, $first->getId());
        self::assertSame(2, $second->getId());
    }

    public function testHydratesBloksIntoTheirConcreteTransfers(): void
    {
        $this->generate([
            ['name' => 'page', 'schema' => ['body' => ['type' => 'bloks']]],
            ['name' => 'teaser', 'schema' => ['headline' => ['type' => 'text']]],
        ]);

        $data = $this->hydrateToArray('PageTransfer', [
            'body' => [
                ['component' => 'teaser', 'headline' => 'First'],
                ['component' => 'teaser', 'headline' => 'Second'],
            ],
        ]);

        $body = $data['body'];
        self::assertIsArray($body);
        self::assertCount(2, $body);

        $first = $body[0];
        self::assertInstanceOf(AbstractTransfer::class, $first);
        self::assertSame(self::NAMESPACE . '\\TeaserTransfer', $first::class);
        self::assertSame(['headline' => 'First'], $first->toArray());

        $second = $body[1];
        self::assertInstanceOf(AbstractTransfer::class, $second);
        self::assertSame(['headline' => 'Second'], $second->toArray());
    }

    public function testHydratesABlokNestedInsideABlok(): void
    {
        $this->generate([
            ['name' => 'page', 'schema' => ['body' => ['type' => 'bloks']]],
            ['name' => 'grid', 'schema' => ['columns' => ['type' => 'bloks']]],
            ['name' => 'teaser', 'schema' => ['headline' => ['type' => 'text']]],
        ]);

        $data = $this->hydrateToArray('PageTransfer', [
            'body' => [
                [
                    'component' => 'grid',
                    'columns' => [['component' => 'teaser', 'headline' => 'Deep']],
                ],
            ],
        ]);

        $body = $data['body'];
        self::assertIsArray($body);

        $grid = $body[0];
        self::assertInstanceOf(AbstractTransfer::class, $grid);
        self::assertSame(self::NAMESPACE . '\\GridTransfer', $grid::class);

        $columns = $grid->toArray()['columns'];
        self::assertIsArray($columns);

        $teaser = $columns[0];
        self::assertInstanceOf(AbstractTransfer::class, $teaser);
        self::assertSame(self::NAMESPACE . '\\TeaserTransfer', $teaser::class);
        self::assertSame(['headline' => 'Deep'], $teaser->toArray());
    }

    public function testLeavesAnUnknownComponentAsARawArray(): void
    {
        $this->generate([
            ['name' => 'page', 'schema' => ['body' => ['type' => 'bloks']]],
        ]);

        $raw = ['component' => 'newsletter_signup', 'title' => 'Subscribe'];

        $data = $this->hydrateToArray('PageTransfer', ['body' => [$raw]]);

        self::assertSame([$raw], $data['body']);
    }

    public function testPassesRichtextThroughUntouched(): void
    {
        $this->generate([
            ['name' => 'hero', 'schema' => ['body' => ['type' => 'richtext']]],
        ]);

        $nodes = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];

        $data = $this->hydrateToArray('HeroTransfer', ['body' => $nodes]);

        self::assertSame($nodes, $data['body']);
    }

    public function testPassesOptionsThroughAsStrings(): void
    {
        $this->generate([
            ['name' => 'hero', 'schema' => ['tags' => ['type' => 'options']]],
        ]);

        $data = $this->hydrateToArray('HeroTransfer', ['tags' => ['a', 'b']]);

        self::assertSame(['a', 'b'], $data['tags']);
    }

    public function testTurnsAnEmptyAssetStringIntoNull(): void
    {
        $this->generate([
            ['name' => 'hero', 'schema' => ['image' => ['type' => 'asset']]],
        ]);

        $data = $this->hydrateToArray('HeroTransfer', ['image' => '']);

        self::assertNull($data['image']);
    }

    /**
     * Asserts run against toArray() because the generated accessors do not
     * exist at static-analysis time; toArray() is declared on AbstractTransfer.
     *
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private function hydrateToArray(string $transferName, array $content): array
    {
        /** @var class-string $class */
        $class = self::NAMESPACE . '\\' . $transferName;

        return $this->hydrator->hydrate($class, $content)->toArray();
    }

    /**
     * @param list<array<string,mixed>> $components
     */
    private function generate(array $components): void
    {
        $handler = new MockHandler([
            new Response(200, [], (string) json_encode(['components' => $components])),
        ]);

        (new StoryblokTransferGenerator(
            spaceId: '1',
            token: 'token',
            definitionsPath: $this->definitionsPath,
            outputPath: $this->outputPath,
            namespace: self::NAMESPACE,
            httpClient: new Client(['handler' => HandlerStack::create($handler)]),
        ))->generate();

        foreach ((array) glob($this->outputPath . '/*.php') as $file) {
            require_once (string) $file;
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails, then passes**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryblokHydratorIntegrationTest`

If the hydrator from Task 5 is correct, these pass immediately. That is expected
for integration tests layered over already-tested units — they are here to catch
generator/hydrator mismatch, not to drive new code.

If any fail, the failure is real: fix the hydrator, not the test. Most likely
cause is element-type resolution — check that `PropertyTypeResolver` finds the
`add{Singular}()` parameter type on the generated class.

- [ ] **Step 3: Run the whole suite and both analysers**

Run: `docker compose run --rm php vendor/bin/phpunit`
Expected: PASS, 102 tests

Run: `docker compose run --rm php vendor/bin/phpstan analyse --no-progress`
Expected: `[OK] No errors`

Run: `docker compose run --rm php vendor/bin/phpcs`
Expected: no violations

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/StoryblokHydratorIntegrationTest.php
git commit -m "Add hydrator integration tests against generated classes"
```

---

### Task 7: Documentation

**Files:**
- Modify: `README.md` — replace the manual-mapping workaround in the
  "Nested transfers are not hydrated by `fromArray()`" subsection under
  `## Limitations`

**Interfaces:**
- Consumes: the public API from Task 5.
- Produces: nothing.

- [ ] **Step 1: Replace the limitation section**

In `README.md`, find the `### Nested transfers are not hydrated by `fromArray()``
subsection. Keep the explanation of why `fromArray()` throws, but replace the
manual-mapping code block and the sentence following it with the hydrator. The
subsection should end up reading:

````markdown
### Use the hydrator, not `fromArray()`, for nested fields

`AbstractTransfer::fromArray()` passes raw payload values straight to the setter,
so a nested asset arrives as an `array` where the setter demands an
`AssetTransfer`:

```php
// Throws TypeError
ProductTransfer::fromArray(['image' => ['id' => 1, 'filename' => 'a.jpg']]);
```

`StoryblokHydrator` converts the whole graph instead:

```php
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;

$hydrator = new StoryblokHydrator('App\\DataTransferObjects');

$page = $hydrator->hydrate(PageTransfer::class, $story['content']);
```

Assets, links, multiassets and nested bloks are all resolved, to any depth.
Richtext, tables, custom fields and option lists pass through untouched.

Bloks hydrate into their **concrete** generated transfers, matched on each
blok's `component`. Note that this means the declared `@var array<BlokTransfer>`
is narrower than what the array really holds — PHP enforces only `array`, so
this is safe at runtime, but iterate with a type guard:

```php
foreach ($page->getBody() as $blok) {
    if (!$blok instanceof TeaserTransfer) {
        continue;
    }

    echo $blok->getHeadline();
}
```

A blok whose component has no generated class stays a raw array rather than
throwing, so an editor adding a component in Storyblok cannot break the page.
Regenerate to pick it up.
````

- [ ] **Step 2: Verify the README has no stale manual-mapping advice**

Run: `grep -n "AssetTransfer::fromArray\|Map those fields yourself" README.md`
Expected: no matches. If anything is found, remove it — it contradicts the
hydrator.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "Document StoryblokHydrator in place of manual field mapping"
```

---

## Verification

After all tasks:

- [ ] `docker compose run --rm php vendor/bin/phpunit` — 102 tests, all passing
- [ ] `docker compose run --rm php vendor/bin/phpstan analyse --no-progress` — `[OK] No errors`
- [ ] `docker compose run --rm php vendor/bin/phpcs` — no violations
- [ ] `git status` — clean tree
- [ ] `testFromArrayCannotHydrateNestedTransferFields()` still passes: the
      upstream limitation it documents is unchanged, and it is the reason the
      hydrator exists.
