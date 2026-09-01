<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Client\ContentResponse;
use Tlab\StoryblokTransfers\Content\StoryblokContent;
use Tlab\StoryblokTransfers\Content\StoryQuery;
use Tlab\StoryblokTransfers\Content\StoryRepository;
use Tlab\StoryblokTransfers\Content\UnresolvableComponentException;
use Tlab\StoryblokTransfers\StoryblokTransferGenerator;
use Tlab\StoryblokTransfers\Tests\Fixture\FakeContentClient;
use Tlab\StoryblokTransfers\Tests\TempDirectory;
use Tlab\StoryblokTransfers\Transfers\AssetTransfer;
use Tlab\TransferObjects\AbstractTransfer;

/**
 * The content layer against real generator output.
 *
 * The unit tests all run against the hand-written fixture transfers, which
 * cannot tell us whether the generated shape still matches what the repository
 * assumes. This generates classes from a schema and reads stories into them.
 */
final class StoryRepositoryIntegrationTest extends TestCase
{
    use TempDirectory;

    private string $namespace;

    private string $definitionsPath;

    private string $outputPath;

    protected function setUp(): void
    {
        // A namespace per test: require_once guards by file path, and every test
        // generates into a fresh temp directory, so a shared namespace would make
        // the second test fatally redeclare the first test's classes.
        $this->namespace = 'ContentGen\\' . ucfirst($this->name());
        $this->definitionsPath = $this->makeTempDir('content-def');
        $this->outputPath = $this->makeTempDir('content-out');
    }

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    public function testReadsAStoryIntoItsGeneratedClass(): void
    {
        $this->generate([
            ['name' => 'page', 'schema' => ['headline' => ['type' => 'text']]],
        ]);

        $story = $this->stories(new ContentResponse([
            'story' => [
                'uuid' => 'u-1',
                'slug' => 'home',
                'full_slug' => 'en/home',
                'name' => 'Home',
                'lang' => 'en',
                'published_at' => '2026-08-01 10:00:00',
                'content' => ['component' => 'page', 'headline' => 'Hello'],
            ],
        ]))->bySlug('home');

        self::assertNotNull($story);
        self::assertSame($this->namespace . '\\PageTransfer', $story->getContent()::class);
        self::assertSame('Hello', $story->getContent()->toArray()['headline']);
        self::assertSame('en/home', $story->getFullSlug());
        self::assertSame('en', $story->getLang());
        self::assertSame('2026-08-01 10:00:00', $story->getPublishedAt());
    }

    public function testHydratesABundledTransferInsideAGeneratedClass(): void
    {
        $this->generate([
            ['name' => 'page', 'schema' => ['image' => ['type' => 'asset']]],
        ]);

        $story = $this->stories(new ContentResponse([
            'story' => [
                'uuid' => 'u-1',
                'slug' => 'home',
                'full_slug' => 'home',
                'content' => [
                    'component' => 'page',
                    'image' => ['id' => 9, 'filename' => 'a.jpg', 'alt' => 'A'],
                ],
            ],
        ]))->bySlug('home');

        self::assertNotNull($story);
        $image = $story->getContent()->toArray()['image'];
        self::assertInstanceOf(AssetTransfer::class, $image);
        self::assertSame('a.jpg', $image->getFilename());
    }

    public function testTurnsANestedBlokIntoItsConcreteGeneratedClass(): void
    {
        $this->generate([
            ['name' => 'page', 'schema' => ['body' => ['type' => 'bloks']]],
            ['name' => 'teaser', 'schema' => ['headline' => ['type' => 'text']]],
        ]);

        $story = $this->stories(new ContentResponse([
            'story' => [
                'uuid' => 'u-1',
                'slug' => 'home',
                'full_slug' => 'home',
                'content' => [
                    'component' => 'page',
                    'body' => [['component' => 'teaser', 'headline' => 'Deep']],
                ],
            ],
        ]))->bySlug('home');

        self::assertNotNull($story);
        $body = $story->getContent()->toArray()['body'];
        self::assertIsArray($body);
        self::assertInstanceOf(AbstractTransfer::class, $body[0]);
        self::assertSame($this->namespace . '\\TeaserTransfer', $body[0]::class);
    }

    public function testMakesAResolvedRelationReachableWithoutBreakingTheUuidProperty(): void
    {
        $this->generate([
            ['name' => 'page', 'schema' => ['author' => ['type' => 'option', 'source' => 'internal_stories']]],
            ['name' => 'author', 'schema' => ['headline' => ['type' => 'text']]],
        ]);

        $story = $this->stories(new ContentResponse([
            'story' => [
                'uuid' => 'u-1',
                'slug' => 'home',
                'full_slug' => 'home',
                // The uuid the CDA leaves in place, untouched by resolve_relations.
                'content' => ['component' => 'page', 'author' => 'author-1'],
            ],
            // Where the resolved story actually arrives.
            'rels' => [
                [
                    'uuid' => 'author-1',
                    'full_slug' => 'authors/jane',
                    'content' => ['component' => 'author', 'headline' => 'Jane'],
                ],
            ],
        ]))->bySlug('home');

        self::assertNotNull($story);

        // The generated property is a ?string and still holds the uuid, which is
        // the whole point of keeping relations beside the content.
        self::assertSame('author-1', $story->getContent()->toArray()['author']);

        // assertInstanceOf rather than assertNotNull: getRelation() returns
        // AbstractTransfer|array|null, and only the instance assertion both
        // narrows the type for static analysis and proves the thing this test
        // is actually about - that the relation was hydrated rather than left
        // as a raw array.
        $author = $story->getRelation('author-1');
        self::assertInstanceOf(AbstractTransfer::class, $author);
        self::assertSame($this->namespace . '\\AuthorTransfer', $author::class);
        self::assertSame('Jane', $author->toArray()['headline']);
    }

    public function testSurvivesTheReflectionRoundTripThatCatchesUninitializedProperties(): void
    {
        // toArray(true) walks every property by reflection, which is where a
        // defaultless typed property on a generated class would blow up.
        $this->generate([
            [
                'name' => 'page',
                'schema' => [
                    'headline' => ['type' => 'text'],
                    'image' => ['type' => 'asset'],
                    'body' => ['type' => 'bloks'],
                    'tags' => ['type' => 'options'],
                ],
            ],
        ]);

        $story = $this->stories(new ContentResponse([
            'story' => [
                'uuid' => 'u-1',
                'slug' => 'home',
                'full_slug' => 'home',
                // Deliberately sparse: Storyblok omits untouched fields.
                'content' => ['component' => 'page'],
            ],
        ]))->bySlug('home');

        self::assertNotNull($story);
        // The invocation above is most of the test - toArray(true) reflects over
        // every property, which is where a defaultless typed property would
        // raise an Error. The assertion is a named key rather than
        // assertNotSame([], ...), which could essentially never be false:
        // toArray() emits one entry per declared property, set or not.
        self::assertArrayHasKey('headline', $story->getContent()->toArray(true));
    }

    public function testReadsAListingIntoGeneratedClassesWithOneSharedRelationMap(): void
    {
        $this->generate([
            ['name' => 'post', 'schema' => ['author' => ['type' => 'option', 'source' => 'internal_stories']]],
            ['name' => 'author', 'schema' => ['headline' => ['type' => 'text']]],
        ]);

        $list = $this->stories(new ContentResponse([
            'stories' => [
                [
                    'uuid' => 'p-1',
                    'slug' => 'one',
                    'full_slug' => 'blog/one',
                    'content' => ['component' => 'post', 'author' => 'a-1'],
                ],
                [
                    'uuid' => 'p-2',
                    'slug' => 'two',
                    'full_slug' => 'blog/two',
                    'content' => ['component' => 'post', 'author' => 'a-1'],
                ],
            ],
            // One rels array for the whole page, which is why the shared map is
            // structural here rather than merged together per story.
            'rels' => [
                ['uuid' => 'a-1', 'content' => ['component' => 'author', 'headline' => 'Jane']],
            ],
        ], 2, 25))->findBy(new StoryQuery(startsWith: 'blog/'));

        self::assertCount(2, $list->getStories());
        self::assertSame(2, $list->getTotal());

        [$first, $second] = $list->getStories();
        self::assertSame($first->getRelations(), $second->getRelations());

        // Both stories point at the same uuid and both reach the one resolved
        // author, from the single map built off the response root.
        $author = $second->getRelation('a-1');
        self::assertInstanceOf(AbstractTransfer::class, $author);
        self::assertSame('Jane', $author->toArray()['headline']);
    }

    public function testResolvesOnlyOneLevelOfRelations(): void
    {
        // The README lists this as a limitation and claims each one is pinned by
        // a test. Storyblok resolves the fields named in resolve_relations and
        // stops: a uuid inside a *resolved* story's own content is never looked
        // up, so it stays a bare string with nothing in the map behind it.
        $this->generate([
            ['name' => 'page', 'schema' => ['author' => ['type' => 'option', 'source' => 'internal_stories']]],
            [
                'name' => 'author',
                'schema' => [
                    'headline' => ['type' => 'text'],
                    'mentor' => ['type' => 'option', 'source' => 'internal_stories'],
                ],
            ],
        ]);

        $story = $this->stories(new ContentResponse([
            'story' => [
                'uuid' => 'u-1',
                'slug' => 'home',
                'full_slug' => 'home',
                'content' => ['component' => 'page', 'author' => 'a-1'],
            ],
            'rels' => [
                [
                    'uuid' => 'a-1',
                    'content' => ['component' => 'author', 'headline' => 'Jane', 'mentor' => 'a-2'],
                ],
            ],
        ]))->bySlug('home');

        self::assertNotNull($story);

        $author = $story->getRelation('a-1');
        self::assertInstanceOf(AbstractTransfer::class, $author);

        // The second-level uuid survives in the resolved story's own content,
        // because nothing rewrites content at any depth.
        self::assertSame('a-2', $author->toArray()['mentor']);

        // And it resolves to nothing: the CDA never put a-2 in rels.
        self::assertNull($story->getRelation('a-2'));
    }

    public function testThrowsWhenTheRootComponentWasNeverGenerated(): void
    {
        $this->generate([
            ['name' => 'page', 'schema' => ['headline' => ['type' => 'text']]],
        ]);

        $this->expectException(UnresolvableComponentException::class);
        $this->expectExceptionMessageMatches('/never_generated/');

        $this->stories(new ContentResponse([
            'story' => [
                'uuid' => 'u-1',
                'slug' => 'home',
                'full_slug' => 'home',
                'content' => ['component' => 'never_generated'],
            ],
        ]))->bySlug('home');
    }

    private function stories(ContentResponse $response): StoryRepository
    {
        return StoryblokContent::usingClient(
            FakeContentClient::returning($response),
            $this->namespace,
        )->stories();
    }

    /**
     * @param list<array<string, mixed>> $components
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
            namespace: $this->namespace,
            httpClient: new Client(['handler' => HandlerStack::create($handler)]),
        ))->generate();

        foreach ((array) glob($this->outputPath . '/*.php') as $file) {
            require_once (string) $file;
        }
    }
}
