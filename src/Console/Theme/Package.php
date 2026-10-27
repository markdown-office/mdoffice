<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

use DOMDocument;
use MarkdownOffice\Console\ConsoleException;
use ZipArchive;

/**
 * A `.pptx`, opened and read.
 *
 * The parts of a package and the documents they hold, and nothing else: what a
 * deck is made of is read out of these by the readers that know what they are
 * looking for.
 */
final class Package
{
    private function __construct(
        private readonly ZipArchive $zip,
        private readonly string $path,
    ) {
    }

    /**
     * Open a deck.
     *
     * @throws ConsoleException when the file is not there, or is not a package.
     */
    public static function open(string $path): self
    {
        if (!is_file($path)) {
            throw new ConsoleException(
                sprintf('The deck "%s" does not exist.', $path),
                ['Name the deck to read.'],
            );
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new ConsoleException(
                sprintf('The deck "%s" could not be opened.', $path),
                ['A .pptx is a zip archive; this file is not one.'],
            );
        }

        return new self($zip, $path);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Every part of the package, by name.
     *
     * @return list<string>
     */
    public function parts(): array
    {
        $names = [];

        for ($index = 0; $index < $this->zip->numFiles; $index++) {
            $name = (string) $this->zip->getNameIndex($index);

            if (!str_ends_with($name, '/')) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function has(string $part): bool
    {
        return $this->zip->locateName($part) !== false;
    }

    /**
     * A part of the package, as a document.
     *
     * @throws ConsoleException when the part is not there, or is not XML.
     */
    public function document(string $part): DOMDocument
    {
        $source = $this->zip->getFromName($part);

        if ($source === false) {
            throw new ConsoleException(sprintf('The deck "%s" has no "%s" part.', $this->path, $part));
        }

        $quiet = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($source, LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($quiet);

        if (!$loaded) {
            throw new ConsoleException(sprintf('The part "%s" of the deck "%s" is not XML.', $part, $this->path));
        }

        return $document;
    }

    public function close(): void
    {
        $this->zip->close();
    }

    /**
     * A target against the part it is written in, as the path of a part.
     *
     * `../slideLayouts/slideLayout1.xml` written in `ppt/slides` is
     * `ppt/slideLayouts/slideLayout1.xml`.
     */
    public static function resolve(string $base, string $target): string
    {
        $path = $base;

        foreach (explode('/', $target) as $segment) {
            if ($segment === '..') {
                $path = dirname($path);

                continue;
            }

            if ($segment !== '' && $segment !== '.') {
                $path .= '/' . $segment;
            }
        }

        return $path;
    }
}
