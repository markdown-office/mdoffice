<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Slides\Diff;

use DOMDocument;
use DOMElement;
use MarkdownOffice\Console\ConsoleException;
use ZipArchive;

/**
 * A `.pptx`, opened and bounded before anything is read out of it.
 *
 * The deck is read as the package it is rather than through PHPPresentation: the
 * parts a diff asks about are the ones that library does not keep — where a shape
 * sits, what colour a run is drawn in, and the id a slide carries — and a diff
 * that read them through a model would be a diff of the model.
 *
 * The bounds are the ones the suite's own package reader uses. A zip says how its
 * contents are laid out and nothing about how much room they will take up, so the
 * number of parts and the size of each are read out of the central directory,
 * which costs no decompression at all.
 */
final class Package
{
    /**
     * The largest one part of a package may be once decompressed.
     */
    private const MAX_PART_BYTES = 268435456;

    /**
     * The largest number of parts a package may have.
     */
    private const MAX_PARTS = 4096;

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

        try {
            self::bound($zip, $path);
        } catch (ConsoleException $refused) {
            $zip->close();

            throw $refused;
        }

        return new self($zip, $path);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function has(string $part): bool
    {
        return $this->zip->locateName($part) !== false;
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
     * The relationships of a part, by id.
     *
     * @return array<string, string>
     */
    public function relationships(string $part): array
    {
        $rels = self::relsOf($part);

        if (!$this->has($rels)) {
            return [];
        }

        $document = $this->document($rels);
        $found = [];

        foreach (self::elements($document) as $node) {
            if ($node->localName !== 'Relationship') {
                continue;
            }

            $found[$node->getAttribute('Id')] = self::resolve(dirname($part), $node->getAttribute('Target'));
        }

        return $found;
    }

    /**
     * A target against the part it is written in, as the path of a part.
     */
    public static function resolve(string $base, string $target): string
    {
        $segments = explode('/', $base);

        foreach (explode('/', $target) as $segment) {
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            if ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    /**
     * The relationship file of a part.
     */
    private static function relsOf(string $part): string
    {
        return dirname($part) . '/_rels/' . basename($part) . '.rels';
    }

    /**
     * The child elements of a document's root.
     *
     * @return list<DOMElement>
     */
    private static function elements(DOMDocument $document): array
    {
        $found = [];
        $root = $document->documentElement;

        if ($root === null) {
            return $found;
        }

        foreach ($root->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $found[] = $node;
            }
        }

        return $found;
    }

    /**
     * Refuse a package that holds more than a package may hold.
     *
     * The sizes are the ones the central directory declares, which costs one
     * lookup per part and no decompression.
     */
    private static function bound(ZipArchive $zip, string $path): void
    {
        if ($zip->numFiles > self::MAX_PARTS) {
            throw new ConsoleException(sprintf(
                'The deck "%s" has %d parts, which is more than a deck may hold.',
                $path,
                $zip->numFiles,
            ));
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                continue;
            }

            $size = (int) $stat['size'];

            if ($size > self::MAX_PART_BYTES) {
                throw new ConsoleException(sprintf(
                    'The part "%s" of the deck "%s" is %d bytes, which is more than a part may be.',
                    (string) $stat['name'],
                    $path,
                    $size,
                ));
            }
        }
    }
}
