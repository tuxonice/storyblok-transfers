<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// storyblok/richtext-resolver 2.2.1 (last released 2023) declares an implicitly
// nullable parameter in Resolver's constructor, which PHP 8.4+ reports as a
// deprecation the moment the file is compiled. It is a dev dependency, used only
// by RichtextResolverInteropTest, and not ours to fix.
//
// Loading the class here, with the compile-time deprecation silenced, keeps that
// one known notice out of the run without narrowing failOnDeprecation: every
// other deprecation - from src/, from generated classes, from any dependency -
// still fails the suite.
@class_exists(\Storyblok\RichtextRender\Resolver::class);
