<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

use DOMDocument;
use DOMElement;

/**
 * The XML of a presentation, read.
 *
 * The two namespaces a `.pptx` is written in, and the two questions every reader
 * of a part asks of it: which child element is this, and which are the children
 * of the root.
 */
final class Xml
{
    public const PRESENTATIONML = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    public const DRAWINGML = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    public const CORE = 'http://purl.org/dc/elements/1.1/';

    /**
     * The first child element with this name in this namespace, or null.
     */
    public static function child(?DOMElement $parent, string $namespace, string $name): ?DOMElement
    {
        if ($parent === null) {
            return null;
        }

        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->namespaceURI === $namespace && $node->localName === $name) {
                return $node;
            }
        }

        return null;
    }

    /**
     * The child elements of a document's root.
     *
     * @return list<DOMElement>
     */
    public static function elements(DOMDocument $document): array
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
}
