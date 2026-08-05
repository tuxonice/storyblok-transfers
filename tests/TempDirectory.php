<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Creates real directories for tests, so the generator is exercised against a
 * real filesystem rather than a mocked one.
 */
trait TempDirectory
{
    /** @var list<string> */
    private array $tempDirs = [];

    private function makeTempDir(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/sbtg-' . $prefix . '-' . bin2hex(random_bytes(6));

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException('Could not create temp dir ' . $path);
        }

        $this->tempDirs[] = $path;

        return $path;
    }

    private function removeTempDirs(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            /** @var SplFileInfo $item */
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }

            rmdir($dir);
        }

        $this->tempDirs = [];
    }
}
