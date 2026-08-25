#!/usr/bin/env php
<?php

/**
 * End-to-end smoke test against a real Storyblok space.
 *
 * Usage:
 *     php tools/smoke-test.php [story-slug-or-id]
 *
 * Wiring only - the run itself lives in Tlab\StoryblokTransfers\Tools\SmokeTest,
 * so it can be exercised without a subprocess. Keeping this file free of symbol
 * declarations is also what lets PSR1.Files.SideEffects pass on it.
 */

declare(strict_types=1);

use GuzzleHttp\Client;
use Tlab\StoryblokTransfers\Hydration\PropertyTypeResolver;

require __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/Console.php';
require_once __DIR__ . '/Configuration.php';
require_once __DIR__ . '/DotEnvFile.php';
require_once __DIR__ . '/GraphSummary.php';
require_once __DIR__ . '/SmokeTest.php';
require_once __DIR__ . '/Story.php';
require_once __DIR__ . '/StoryFetcher.php';
require_once __DIR__ . '/TransferGraphPrinter.php';


$argument = $argv[1] ?? null;

if (in_array($argument, ['-h', '--help'], true)) {
    echo SmokeTest::USAGE;

    exit(0);
}

$console = Console::forStream(STDOUT);

try {
    $configuration = Configuration::fromEnvironment(dirname(__DIR__) . '/.env');

    $smokeTest = new SmokeTest(
        console: $console,
        configuration: $configuration,
        fetcher: new StoryFetcher(new Client(), $configuration->authorizationHeader()),
        printer: new TransferGraphPrinter($console, new PropertyTypeResolver()),
        // Never src/: the generator clears every *Transfer.php in its output
        // directory on each run.
        outputRoot: __DIR__ . '/.output',
        // Deliberately not the library's default namespace - these classes are
        // throwaway diagnostics, and the name should say so in every line of
        // output.
        namespace: 'SmokeTest\\DataTransferObjects',
    );

    $smokeTest->run($argument);
} catch (SmokeTestFailure $failure) {
    $console->failure($failure);

    exit(1);
}

$console->success();

exit(0);
