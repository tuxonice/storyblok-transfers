#!/usr/bin/env php
<?php

/**
 * End-to-end smoke test against a real Storyblok space.
 *
 * Usage:
 *     php tools/smoke-test.php [story-slug]
 *
 * Wiring only - the run itself lives in Tlab\StoryblokTransfers\Tools\SmokeTest,
 * so it can be exercised without a subprocess. Keeping this file free of symbol
 * declarations is also what lets PSR1.Files.SideEffects pass on it.
 */

declare(strict_types=1);

use Tlab\StoryblokTransfers\Content\ContentOptions;
use Tlab\StoryblokTransfers\Content\StoryblokContent;
use Tlab\StoryblokTransfers\Content\Version;
use Tlab\StoryblokTransfers\Hydration\PropertyTypeResolver;

require __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/Console.php';
require_once __DIR__ . '/Configuration.php';
require_once __DIR__ . '/DotEnvFile.php';
require_once __DIR__ . '/GraphSummary.php';
require_once __DIR__ . '/SmokeTest.php';
// Every failure path in this tool throws one of these, and nothing else loads
// it: Composer's PSR-4 map covers src/ and tests/, not tools/. Without this
// line the credential guard below - and every other check - dies with
// "Class SmokeTestFailure not found" instead of its own message.
require_once __DIR__ . '/SmokeTestFailure.php';
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
        stories: StoryblokContent::create(
            deliveryToken: $configuration->deliveryToken,
            namespace: 'SmokeTest\\DataTransferObjects',
            defaults: new ContentOptions(Version::Draft),
        )->stories(),
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
