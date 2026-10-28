<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

use DOMDocument;
use DOMElement;
use MarkdownOffice\Console\ConsoleException;
use MarkdownOffice\Console\Package\Bounds;
use ZipArchive;

/**
 * The template of a theme: the deck's layouts, without its slides.
 *
 * A template places; it does not typeset. What a theme's template gives the
 * renderer is where the boxes of the layouts are, and that is all it is read for.
 * The slides are none of that, and a template carrying them is a copy of the deck
 * rather than a template: every slide of the original would be in every deck
 * drawn against it.
 *
 * So the template is the deck's package with the slides taken out of it, and
 * everything that reaches them taken out with them: the pictures on the slides
 * are the slides' own, and a template that carried them would be a megabyte of
 * pictures no slide refers to. The layouts, the master, the theme part and the
 * page size all stay, because those are what a template is.
 */
final class Template
{
    private const PRESENTATION_RELS = 'ppt/_rels/presentation.xml.rels';
    private const CONTENT_TYPES = '[Content_Types].xml';

    /**
     * The relationship type of a slide, which is what the template drops.
     */
    private const SLIDE = '/slide';

    /**
     * The message the template gives when it cannot be written.
     */
    private const TEMPLATE_UNWRITABLE = 'Unable to write "%s".';

    /**
     * Write the template of a deck.
     *
     * @throws ConsoleException when the deck cannot be read, or the template cannot
     *         be written.
     */
    public static function write(string $deck, string $path): void
    {
        $zip = new ZipArchive();

        if ($zip->open($deck) !== true) {
            throw new ConsoleException(sprintf('The deck "%s" could not be opened.', $deck));
        }

        // The parts are inflated into the template one by one, so the deck is
        // bounded before the first of them is.
        try {
            Bounds::check($zip, $deck);
        } catch (ConsoleException $refused) {
            $zip->close();

            throw $refused;
        }

        $temporary = tempnam(dirname($path), '.mdoffice_');

        if ($temporary === false) {
            $zip->close();

            throw new ConsoleException(sprintf(self::TEMPLATE_UNWRITABLE, $path));
        }

        try {
            $parts = self::parts($zip);
            $keep = self::reachable($zip, $parts);
            $out = new ZipArchive();
            $opened = $out->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE);

            if ($opened !== true) {
                throw new ConsoleException(sprintf(self::TEMPLATE_UNWRITABLE, $path));
            }

            foreach ($parts as $name) {
                if (! isset($keep[$name])) {
                    continue;
                }

                $contents = self::contents($zip, $name);
                $out->addFromString($name, $contents);
            }

            $out->close();
        } finally {
            $zip->close();
        }

        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new ConsoleException(sprintf(self::TEMPLATE_UNWRITABLE, $path));
        }
    }

    /**
     * Every part of the package.
     *
     * @return array<string, string>
     */
    private static function parts(ZipArchive $zip): array
    {
        $parts = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (! str_ends_with($name, '/')) {
                $parts[$name] = $name;
            }
        }

        return $parts;
    }

    /**
     * The parts a template keeps: everything the package reaches without going
     * through a slide.
     *
     * @param array<string, string> $parts
     * @return array<string, true>
     */
    private static function reachable(ZipArchive $zip, array $parts): array
    {
        // The content types are not reached through a relationship, and a package
        // without them is not a package: the format requires them, and a reader
        // that finds none refuses the file rather than guessing at it.
        $keep = [self::CONTENT_TYPES => true];
        $queue = ['_rels/.rels'];

        while ($queue !== []) {
            $rels = array_shift($queue);
            $keep[$rels] = true;

            foreach (self::targets($zip, $rels) as $target) {
                $part = self::resolve(self::baseOf($rels), $target);

                if (! isset($parts[$part]) || isset($keep[$part])) {
                    continue;
                }

                $keep[$part] = true;
                $queue[] = self::relsOf($part);
            }
        }

        return $keep;
    }

    /**
     * The parts a relationship file points at, with the slides left out.
     *
     * @return list<string>
     */
    private static function targets(ZipArchive $zip, string $rels): array
    {
        $source = $zip->getFromName($rels);

        if ($source === false) {
            return [];
        }

        $document = new DOMDocument();

        if (! @$document->loadXML($source, LIBXML_NONET)) {
            return [];
        }

        $targets = [];

        foreach (self::elements($document) as $node) {
            if ($node->localName !== 'Relationship') {
                continue;
            }

            if (str_ends_with($node->getAttribute('Type'), self::SLIDE)) {
                continue;
            }

            if ($node->getAttribute('TargetMode') === 'External') {
                continue;
            }

            $targets[] = $node->getAttribute('Target');
        }

        return $targets;
    }

    /**
     * The directory a relationship file's targets are written against.
     */
    private static function baseOf(string $rels): string
    {
        if ($rels === '_rels/.rels') {
            return '';
        }

        return dirname(dirname($rels));
    }

    /**
     * The relationship file of a part.
     */
    private static function relsOf(string $part): string
    {
        return dirname($part) . '/_rels/' . basename($part) . '.rels';
    }

    /**
     * A target against the part it is written in, as the path of a part.
     */
    private static function resolve(string $base, string $target): string
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

        return ltrim($path, '/');
    }

    /**
     * A part of the package, as the template keeps it.
     */
    private static function contents(ZipArchive $zip, string $name): string
    {
        $source = (string) $zip->getFromName($name);

        return match ($name) {
            Package::PRESENTATION => self::withoutSlides($source),
            self::PRESENTATION_RELS => self::withoutSlideRelations($source),
            self::CONTENT_TYPES => self::withoutSlideTypes($source),
            default => $source,
        };
    }

    /**
     * The presentation part without the list of slides.
     */
    private static function withoutSlides(string $source): string
    {
        $document = new DOMDocument();

        if (! @$document->loadXML($source, LIBXML_NONET)) {
            return $source;
        }

        $list = self::child($document->documentElement, 'sldIdLst');

        if ($list !== null) {
            $list->parentNode?->removeChild($list);
        }

        return (string) $document->saveXML();
    }

    /**
     * The presentation's relationships without the slides.
     */
    private static function withoutSlideRelations(string $source): string
    {
        $document = new DOMDocument();

        if (! @$document->loadXML($source, LIBXML_NONET)) {
            return $source;
        }

        foreach (self::elements($document) as $node) {
            if ($node->localName !== 'Relationship') {
                continue;
            }

            if (str_ends_with($node->getAttribute('Type'), self::SLIDE)) {
                $node->parentNode?->removeChild($node);
            }
        }

        return (string) $document->saveXML();
    }

    /**
     * The content types without the slides.
     */
    private static function withoutSlideTypes(string $source): string
    {
        $document = new DOMDocument();

        if (! @$document->loadXML($source, LIBXML_NONET)) {
            return $source;
        }

        foreach (self::elements($document) as $node) {
            if ($node->localName !== 'Override') {
                continue;
            }

            $part = ltrim($node->getAttribute('PartName'), '/');

            if (str_starts_with($part, 'ppt/slides/') || str_starts_with($part, 'ppt/notesSlides/')) {
                $node->parentNode?->removeChild($node);
            }
        }

        return (string) $document->saveXML();
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
     * The first child element with this name.
     */
    private static function child(?DOMElement $parent, string $name): ?DOMElement
    {
        if ($parent === null) {
            return null;
        }

        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $name) {
                return $node;
            }
        }

        return null;
    }
}
