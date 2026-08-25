<?php

declare(strict_types=1);


use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Tlab\StoryblokTransfers\Hydration\PropertyTypeResolver;
use Tlab\StoryblokTransfers\Transfers\BlokTransfer;
use Tlab\TransferObjects\AbstractTransfer;

/**
 * Dumps a hydrated transfer graph with Symfony's VarDumper.
 *
 * VarDumper is handed the transfer itself rather than toArray(): flattening to
 * plain arrays would lose exactly what is worth seeing here - that a blok became
 * its concrete class rather than staying an array.
 *
 * A dump shows the graph but cannot judge it, so it is walked once more after the
 * dump. That walk is what the run's verdict is made of: it counts the transfers
 * and the properties VarDumper could only mark with a "?", and it names the raw
 * arrays standing where a BlokTransfer was expected - which no dump can know
 * means "no generated class for that component".
 */
final class TransferGraphPrinter
{
    /** How deep the graph is followed before it gives up. */
    private const MAX_DEPTH = 8;

    /** Values cloned for the dump before the rest is elided. */
    private const MAX_ITEMS = 500;

    /** Characters of a string value shown before truncation. */
    private const MAX_STRING = 60;

    private int $transfers = 0;

    private int $rawBloks = 0;

    private int $uninitialized = 0;

    public function __construct(
        private readonly Console $console,
        private readonly PropertyTypeResolver $resolver,
    ) {
    }

    public function print(AbstractTransfer $transfer, string $indent = '    '): GraphSummary
    {
        $this->transfers = 0;
        $this->rawBloks = 0;
        $this->uninitialized = 0;

        $this->dump($transfer, $indent);

        // The root counts too: it is one of the objects that had to hydrate.
        $this->transfers++;
        $this->inspect($transfer, '', 1);

        return new GraphSummary($this->transfers, $this->rawBloks, $this->uninitialized);
    }

    private function dump(AbstractTransfer $transfer, string $indent): void
    {
        $cloner = new VarCloner();
        $cloner->setMaxItems(self::MAX_ITEMS);
        $cloner->setMaxString(self::MAX_STRING);

        // Writing through Console instead of straight to the stream keeps the
        // dump indented with the rest of the run, and colour one decision made
        // in one place.
        $dumper = new CliDumper(function (string $line, int $depth, string $indentPad) use ($indent): void {
            // -1 is VarDumper signalling the end of the dump, not a line.
            if ($depth >= 0) {
                $this->console->line($indent . str_repeat($indentPad, $depth) . $line);
            }
        });
        $dumper->setColors($this->console->usesColor());

        $dumper->dump($cloner->cloneVar($transfer)->withMaxDepth(self::MAX_DEPTH));
    }

    private function inspect(AbstractTransfer $transfer, string $path, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        $types = $this->resolver->resolve($transfer::class);

        foreach ((new ReflectionClass($transfer))->getProperties(ReflectionProperty::IS_PRIVATE) as $property) {
            $name = $property->getName();
            $childPath = $path === '' ? $name : $path . '.' . $name;

            // A defaultless typed property is exactly what makes toArray()
            // throw, and a "?" in the dump is too quiet for something the run
            // has to fail on.
            if (!$property->isInitialized($transfer)) {
                $this->uninitialized++;
                $this->console->error($childPath . ' is uninitialized');

                continue;
            }

            $value = $property->getValue($transfer);

            if ($value instanceof AbstractTransfer) {
                $this->transfers++;
                $this->inspect($value, $childPath, $depth + 1);

                continue;
            }

            if (is_array($value)) {
                $expectsBloks = ($types[$name] ?? null)?->elementTransferClass === BlokTransfer::class;
                $this->inspectArray($value, $childPath, $depth, $expectsBloks);
            }
        }
    }

    /**
     * @param array<mixed> $value
     */
    private function inspectArray(array $value, string $path, int $depth, bool $expectsBloks): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        foreach ($value as $index => $element) {
            $childPath = $path . '[' . $index . ']';

            if ($element instanceof AbstractTransfer) {
                $this->transfers++;
                $this->inspect($element, $childPath, $depth + 1);

                continue;
            }

            if (!is_array($element)) {
                continue;
            }

            if ($expectsBloks) {
                $this->reportRawBlok($childPath, $element);

                continue;
            }

            // A field can nest arrays before it reaches transfers - richtext
            // content is the usual one - so keep descending for the count.
            $this->inspectArray($element, $childPath, $depth + 1, false);
        }
    }

    /**
     * Documented behaviour rather than a failure: a blok whose component has no
     * generated class stays a raw array, so an editor adding a component in
     * Storyblok cannot break the page.
     *
     * @param array<mixed> $blok
     */
    private function reportRawBlok(string $path, array $blok): void
    {
        $this->rawBloks++;
        $component = is_string($blok['component'] ?? null) ? $blok['component'] : '?';

        $this->console->warn(sprintf('%s stayed a raw array - no class for component "%s"', $path, $component));
    }
}
