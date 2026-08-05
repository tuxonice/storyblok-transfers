<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Definition;

use RuntimeException;

/**
 * Repairs the output transfer-objects emits for plain `array` properties.
 *
 * Upstream's template appends an "add" method to every property whose PHP type
 * is `array`, but a bare `array` type carries no element type and no singular
 * name, so it emits a nameless method and a doubled initialiser:
 *
 *     private ?array $body = [] = null;   // two initialisers
 *     public function add( $): self       // no method name, no parameter name
 *
 * That does not parse. Rather than distort the definition files - which are the
 * artefact humans read and review - we keep them semantically correct and
 * repair the generated classes here.
 *
 * Everything else upstream produces is left byte-for-byte alone.
 *
 * @see https://github.com/tuxonice/data-transfer-object - fix belongs upstream
 */
final class GeneratedCodeFixer
{
    /**
     * A doc comment, matched without consuming the closing delimiter early.
     */
    private const DOC_COMMENT_BODY = '(?:[^*]|\*(?!\/))*';

    /**
     * @return list<string> Paths of the files that needed repairing.
     */
    public function fix(string $outputPath): array
    {
        $repaired = [];

        foreach ($this->generatedFiles($outputPath) as $file) {
            $original = file_get_contents($file);

            if ($original === false) {
                throw new RuntimeException('Could not read generated file ' . $file);
            }

            $fixed = $this->fixSource($original);

            if ($fixed === $original) {
                continue;
            }

            if (file_put_contents($file, $fixed) === false) {
                throw new RuntimeException('Could not write repaired file ' . $file);
            }

            $repaired[] = $file;
        }

        return $repaired;
    }

    public function fixSource(string $source): string
    {
        $nullableArrayProperties = $this->collectArrayProperties($source);

        $source = $this->removeDoubledInitialiser($source);
        $source = $this->removeNamelessAddMethods($source);

        return $this->repairArrayDocblocks($source, $nullableArrayProperties);
    }

    /**
     * @return list<string>
     */
    private function generatedFiles(string $outputPath): array
    {
        $path = rtrim($outputPath, '/');

        $files = array_merge(
            glob($path . '/*Transfer.php') ?: [],
            glob($path . '/*TransferImmutable.php') ?: [],
        );

        sort($files, SORT_STRING);

        return array_values(array_unique($files));
    }

    /**
     * @return array<string,bool> Property name => is nullable.
     */
    private function collectArrayProperties(string $source): array
    {
        preg_match_all('/private (\??)array \$(\w+)/', $source, $matches, PREG_SET_ORDER);

        $properties = [];
        foreach ($matches as $match) {
            $properties[$match[2]] = $match[1] === '?';
        }

        return $properties;
    }

    /**
     * `private ?array $body = [] = null;` -> `private ?array $body = null;`
     */
    private function removeDoubledInitialiser(string $source): string
    {
        return $this->replace('/(private \??array \$\w+) = \[\] = null;/', '$1 = null;', $source);
    }

    /**
     * Drops `public function add( $): self` together with its doc comment.
     */
    private function removeNamelessAddMethods(string $source): string
    {
        $pattern = '/\n[ \t]*\/\*\*' . self::DOC_COMMENT_BODY . '\*\/\n'
            . '[ \t]*public function add\(\s*\$\): self\n'
            . '[ \t]*\{\n.*?\n[ \t]*\}\n/s';

        return $this->replace($pattern, '', $source);
    }

    /**
     * Upstream renders the element type into the docblock unconditionally, so a
     * bare `array` yields `array<>` / `array|null<>`. Restore a valid generic.
     *
     * @param array<string,bool> $arrayProperties
     */
    private function repairArrayDocblocks(string $source, array $arrayProperties): string
    {
        $source = str_replace('@var array|null<>', '@var array<mixed>|null', $source);
        $source = str_replace('@var array<>', '@var array<mixed>', $source);

        // The getter docblock does not name the property, so take nullability
        // from the signature that immediately follows it.
        $source = $this->replaceCallback(
            '/@return array(?:\|null)?<>(' . self::DOC_COMMENT_BODY . '\*\/\s*public function \w+\(\): (\??)array\b)/',
            static fn (array $m): string => '@return array<mixed>' . ($m[2] === '?' ? '|null' : '') . $m[1],
            $source
        );

        // The setter docblock does name it, so look nullability up.
        $source = $this->replaceCallback(
            '/@param array(?:\|null)?<> \$(\w+)/',
            static function (array $m) use ($arrayProperties): string {
                $suffix = ($arrayProperties[$m[1]] ?? false) ? '|null' : '';

                return '@param array<mixed>' . $suffix . ' $' . $m[1];
            },
            $source
        );

        // Any remaining occurrence had no signature to disambiguate.
        return str_replace(['array|null<>', 'array<>'], ['array<mixed>|null', 'array<mixed>'], $source);
    }

    private function replace(string $pattern, string $replacement, string $subject): string
    {
        $result = preg_replace($pattern, $replacement, $subject);

        if ($result === null) {
            throw new RuntimeException('Pattern failed while repairing generated code: ' . $pattern);
        }

        return $result;
    }

    /**
     * @param callable(array<int|string,string>): string $callback
     */
    private function replaceCallback(string $pattern, callable $callback, string $subject): string
    {
        $result = preg_replace_callback($pattern, $callback, $subject);

        if ($result === null) {
            throw new RuntimeException('Pattern failed while repairing generated code: ' . $pattern);
        }

        return $result;
    }
}
