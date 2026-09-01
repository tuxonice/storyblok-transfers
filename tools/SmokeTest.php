<?php

declare(strict_types=1);


use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\Content\ContentOptions;
use Tlab\StoryblokTransfers\Content\StoryQuery;
use Tlab\StoryblokTransfers\Content\StoryRepository;
use Tlab\StoryblokTransfers\Content\StoryTransfer;
use Tlab\StoryblokTransfers\Content\UnexpectedComponentException;
use Tlab\StoryblokTransfers\Content\UnresolvableComponentException;
use Tlab\StoryblokTransfers\Content\Version;
use Tlab\StoryblokTransfers\GenerationResult;
use Tlab\StoryblokTransfers\StoryblokTransferGenerator;

/**
 * Runs the library end to end against a real Storyblok space.
 *
 * The unit suite covers each piece against fixtures; this checks the pieces
 * still line up against schemas nobody wrote for the test. Generation writes
 * only under the output root - the space itself is only ever read from.
 */
final class SmokeTest
{
    public const USAGE = <<<'USAGE'
        Usage: php tools/smoke-test.php [story-slug]

        Generates transfer classes from the Storyblok space in your .env, lists the
        space, then reads one story through the library's own StoryRepository and
        prints the resulting graph.

        Without an argument the first story the listing returns is used.

        Reads STORYBLOK_SPACE_ID, STORYBLOK_MANAGEMENT_TOKEN and STORYBLOK_AUTH_SCHEME
        for generation, and STORYBLOK_DELIVERY_TOKEN for reading content. Set
        STORYBLOK_SMOKE_RELATIONS to a comma-separated list of component.field pairs
        to exercise resolve_relations.

        Resolution only shows up when the story being read actually carries one of
        those fields, so STORYBLOK_SMOKE_RELATIONS is normally paired with an
        explicit slug argument rather than left to whichever story the default
        listing happens to return. In this space, for example, the relation field
        is "test.author" - it lives on the "test" component nested inside the story
        at slug "test", not at "home", which is only the relation's target - so
        exercising it end to end means:
        STORYBLOK_SMOKE_RELATIONS=test.author php tools/smoke-test.php test

        Generated output goes to tools/.output and is git-ignored. The space is only
        ever read from.

        USAGE;

    private const DEFINITIONS_SUBDIR = 'definitions';

    private const TRANSFERS_SUBDIR = 'Transfers';

    public function __construct(
        private readonly Console $console,
        private readonly Configuration $configuration,
        private readonly StoryRepository $stories,
        private readonly TransferGraphPrinter $printer,
        private readonly string $outputRoot,
        private readonly string $namespace,
    ) {
    }

    /**
     * @throws SmokeTestFailure When any step fails.
     */
    public function run(?string $slug): void
    {
        $this->console->title('Storyblok transfers smoke test');
        $this->console->info('space ' . $this->configuration->spaceId . '  →  ' . $this->outputRoot);

        $generation = $this->generate();
        $this->registerGeneratedClassAutoloader();

        $slug ??= $this->firstSlug();
        $story = $this->fetchStory($slug, $generation);
        $summary = $this->inspect($story);

        $this->report($generation, $summary);
    }

    /**
     * @throws SmokeTestFailure
     */
    private function generate(): GenerationResult
    {
        $this->console->heading('[1/4] Generating transfers from the space schemas');

        $generator = new StoryblokTransferGenerator(
            spaceId: $this->configuration->spaceId,
            token: $this->configuration->token,
            definitionsPath: $this->definitionsPath(),
            outputPath: $this->transfersPath(),
            namespace: $this->namespace,
            authorizationScheme: $this->configuration->authorizationScheme,
        );

        try {
            $result = $generator->generate();
        } catch (StoryblokApiException $e) {
            throw new SmokeTestFailure('Storyblok API error: ' . $e->getMessage());
        } catch (Throwable $e) {
            throw new SmokeTestFailure('Generation failed: ' . $e->getMessage(), $e::class);
        }

        if ($result->componentNames === []) {
            throw new SmokeTestFailure(
                'No components with usable fields were found in space ' . $this->configuration->spaceId . '.'
            );
        }

        $this->console->ok(sprintf('%d transfer classes generated', count($result->componentNames)));
        $this->console->info(implode(', ', array_map(
            static fn (string $name): string => $name . 'Transfer',
            $result->componentNames
        )));

        foreach ($result->warnings as $warning) {
            $this->console->warn($warning);
        }

        if ($result->warnings === []) {
            $this->console->ok('no skipped fields');
        }

        return $result;
    }

    /**
     * Also the only place the listing and its header-borne totals get exercised
     * against the real API.
     *
     * @throws SmokeTestFailure
     */
    private function firstSlug(): string
    {
        $this->console->heading('[2/4] Listing stories');

        try {
            $list = $this->stories->findBy(new StoryQuery(perPage: 1));
        } catch (StoryblokApiException $e) {
            throw new SmokeTestFailure('Could not list stories: ' . $e->getMessage());
        } catch (UnresolvableComponentException $e) {
            throw new SmokeTestFailure(
                'The first story in the space has no generated class: ' . $e->getMessage()
            );
        }

        $this->console->ok(sprintf(
            '%d story/stories in the space, %d per page',
            $list->getTotal(),
            $list->getPerPage()
        ));

        $first = $list->getStories()[0] ?? null;

        if ($first === null) {
            throw new SmokeTestFailure('The space contains no stories to hydrate.');
        }

        $this->console->info('first story: ' . $first->getFullSlug());

        return $first->getFullSlug();
    }

    /**
     * @throws SmokeTestFailure
     */
    private function fetchStory(string $slug, GenerationResult $generation): StoryTransfer
    {
        $this->console->heading('[3/4] Fetching and hydrating one story');

        $relations = $this->relationsToResolve();

        if ($relations !== []) {
            $this->console->info('resolving relations: ' . implode(', ', $relations));
        }

        $options = (new ContentOptions(Version::Draft))->withResolveRelations($relations);

        try {
            $story = $this->stories->bySlug($slug, null, $options);
        } catch (UnresolvableComponentException $e) {
            throw new SmokeTestFailure(
                'No generated class for the story\'s root component: ' . $e->getMessage(),
                'Generated: ' . implode(', ', $generation->componentNames),
                'A component whose every field is a tab or section generates nothing '
                . '- that may be the cause.'
            );
        } catch (UnexpectedComponentException | StoryblokApiException $e) {
            throw new SmokeTestFailure('Could not read the story: ' . $e->getMessage());
        } catch (Throwable $e) {
            throw new SmokeTestFailure('Reading the story threw ' . $e::class . ': ' . $e->getMessage());
        }

        if ($story === null) {
            throw new SmokeTestFailure(sprintf('No story at slug "%s".', $slug));
        }

        $this->console->ok(sprintf(
            'story "%s" (uuid %s) hydrated into %s',
            $story->getFullSlug(),
            $story->getUuid(),
            $story->getContent()::class
        ));

        if ($relations !== []) {
            $this->console->info(
                $story->getRelations()->isEmpty()
                    ? sprintf(
                        'no relations came back resolved - "%s" may not carry any of: %s',
                        $slug,
                        implode(', ', $relations)
                    )
                    : 'relations resolved and reachable through getRelation()'
            );
        }

        return $story;
    }

    /**
     * @return list<string>
     */
    private function relationsToResolve(): array
    {
        $configured = getenv('STORYBLOK_SMOKE_RELATIONS');

        if (!is_string($configured) || $configured === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }

    /**
     * @throws SmokeTestFailure
     */
    private function inspect(StoryTransfer $story): GraphSummary
    {
        $this->console->heading('[4/4] Walking the graph');

        $transfer = $story->getContent();

        $this->console->line();
        $summary = $this->printer->print($transfer);
        $this->console->line();

        // The reflection-driven round trip is where a defaultless typed property
        // on a generated class would surface, so exercise it explicitly.
        try {
            $roundTripped = $transfer->toArray(true);
        } catch (Throwable $e) {
            throw new SmokeTestFailure('toArray(true) threw ' . $e::class . ': ' . $e->getMessage());
        }

        $this->console->ok(sprintf('toArray(true) returned %d keys', count($roundTripped)));

        return $summary;
    }

    /**
     * @throws SmokeTestFailure
     */
    private function report(GenerationResult $generation, GraphSummary $summary): void
    {
        $this->console->heading('Summary');

        $this->console->info(sprintf('%d transfer objects in the graph', $summary->transfers));
        $this->console->info(sprintf('%d fields skipped at generation', count($generation->warnings)));

        if ($summary->rawBloks > 0) {
            $this->console->warn(sprintf(
                '%d nested blok(s) stayed raw arrays - no generated class for their component',
                $summary->rawBloks
            ));
        }

        if ($summary->uninitialized > 0) {
            throw new SmokeTestFailure(
                sprintf('%d property/properties were uninitialized.', $summary->uninitialized)
            );
        }
    }

    /**
     * The classes did not exist when Composer built its autoloader, and the
     * hydrator resolves nested bloks through class_exists(), so they have to be
     * loadable by name.
     */
    private function registerGeneratedClassAutoloader(): void
    {
        $prefix = rtrim($this->namespace, '\\') . '\\';
        $directory = $this->transfersPath();

        spl_autoload_register(static function (string $class) use ($prefix, $directory): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $file = $directory . '/' . substr($class, strlen($prefix)) . '.php';

            if (is_file($file)) {
                require $file;
            }
        });
    }

    private function definitionsPath(): string
    {
        return $this->outputRoot . '/' . self::DEFINITIONS_SUBDIR;
    }

    private function transfersPath(): string
    {
        return $this->outputRoot . '/' . self::TRANSFERS_SUBDIR;
    }
}
