<?php

declare(strict_types=1);


use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\GenerationResult;
use Tlab\StoryblokTransfers\Hydration\HydrationException;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;
use Tlab\StoryblokTransfers\Schema\ComponentNameFormatter;
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
        Usage: php tools/smoke-test.php [story-slug-or-id]

        Generates transfer classes from the Storyblok space in your .env, fetches one
        story and hydrates it, printing the resulting graph.

        Without an argument the first non-folder story in the space is used.

        Reads STORYBLOK_SPACE_ID, STORYBLOK_MANAGEMENT_TOKEN and STORYBLOK_AUTH_SCHEME
        from the environment, falling back to .env in the repository root.

        Generated output goes to tools/.output and is git-ignored. The space is only
        ever read from.

        USAGE;

    private const DEFINITIONS_SUBDIR = 'definitions';

    private const TRANSFERS_SUBDIR = 'Transfers';

    public function __construct(
        private readonly Console $console,
        private readonly Configuration $configuration,
        private readonly StoryFetcher $fetcher,
        private readonly TransferGraphPrinter $printer,
        private readonly string $outputRoot,
        private readonly string $namespace,
    ) {
    }

    /**
     * @throws SmokeTestFailure When any step fails.
     */
    public function run(?string $storySlugOrId): void
    {
        $this->console->title('Storyblok transfers smoke test');
        $this->console->info('space ' . $this->configuration->spaceId . '  →  ' . $this->outputRoot);

        $generation = $this->generate();
        $this->registerGeneratedClassAutoloader();

        $story = $this->fetchStory($storySlugOrId);
        $summary = $this->hydrate($story, $generation);

        $this->report($generation, $summary);
    }

    /**
     * @throws SmokeTestFailure
     */
    private function generate(): GenerationResult
    {
        $this->console->heading('[1/3] Generating transfers from the space schemas');

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
     * @throws SmokeTestFailure
     */
    private function fetchStory(?string $storySlugOrId): Story
    {
        $this->console->heading('[2/3] Fetching a story');

        try {
            $story = $this->fetcher->fetch($this->configuration->spaceId, $storySlugOrId);
        } catch (StoryblokApiException $e) {
            throw new SmokeTestFailure('Could not fetch a story: ' . $e->getMessage());
        }

        $this->console->ok(sprintf(
            'story "%s" (slug "%s", id %s)',
            $story->name,
            $story->slug,
            $story->id
        ));

        $component = $story->component();

        if ($component === null) {
            throw new SmokeTestFailure(
                'The story content has no "component" key, so there is nothing to hydrate into.'
            );
        }

        $this->console->info('root component: ' . $component . '  (' . count($story->content) . ' keys)');

        return $story;
    }

    /**
     * @throws SmokeTestFailure
     */
    private function hydrate(Story $story, GenerationResult $generation): GraphSummary
    {
        $this->console->heading('[3/3] Hydrating');

        $shortName = (new ComponentNameFormatter())->toTransferName((string) $story->component()) . 'Transfer';
        $transferClass = rtrim($this->namespace, '\\') . '\\' . $shortName;

        if (!class_exists($transferClass)) {
            throw new SmokeTestFailure(
                sprintf('No generated class for the root component "%s".', (string) $story->component()),
                'Expected: ' . $transferClass,
                'Generated: ' . implode(', ', $generation->componentNames),
                'A component whose every field is a tab or section generates nothing '
                . '- that may be the cause.'
            );
        }

        $hydrator = new StoryblokHydrator($this->namespace);

        try {
            $transfer = $hydrator->hydrate($transferClass, $story->content);
        } catch (HydrationException $e) {
            throw new SmokeTestFailure('Hydration failed: ' . $e->getMessage());
        } catch (Throwable $e) {
            throw new SmokeTestFailure('Hydration threw ' . $e::class . ': ' . $e->getMessage());
        }

        $this->console->ok('hydrated into ' . $shortName);
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
