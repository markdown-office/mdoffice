<?php

declare(strict_types=1);

namespace MarkdownOffice\Tests\Support;

use DOMNode;
use DOMXPath;
use MarkdownWord\WordPackage;
use ZipArchive;

/**
 * Reads a finished Word package: what it declares itself to be, and what it says.
 *
 * Against {@see TemplateFactory}, which builds one. A test never does both — it either
 * writes a fixture to open, or opens one that already exists.
 */
final class Package
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const BODY = 'word/document.xml';

    /**
     * What a reader decides a file is by, and the reason a filled-in `.dotx` is not a
     * document while every word in it is exactly where it should be.
     */
    public static function mainContentTypeOf(string $docx): string
    {
        $xml = self::xmlOf($docx, '[Content_Types].xml');

        preg_match('~PartName="/word/document[^"]*" ContentType="([^"]*)"~', $xml, $matches);

        return $matches[1] ?? '';
    }

    /**
     * The body part's text, read through the declarations.
     *
     * {@see self::textOf()} reads `word/document.xml` directly: right for what this
     * library writes, wrong for a package that calls its body something else.
     */
    public static function bodyTextOf(string $docx): string
    {
        return self::paragraphsOf(self::xpathOf($docx));
    }

    public static function textOf(string $docx): string
    {
        return self::paragraphsOf(self::xpathOf($docx));
    }

    /**
     * Every `w:t` under a node, concatenated.
     *
     * A reader cannot see a run boundary, so this answers "did the words survive" and
     * not "is the paragraph where it should be" — {@see self::textOf()} answers that.
     */
    public static function textIn(\DOMXPath $xpath, \DOMNode $context): string
    {
        $text = '';

        foreach (self::nodesOf($xpath, './/w:t', $context) as $node) {
            $text .= $node->textContent;
        }

        return $text;
    }

    public static function xmlOf(string $docx, string $part = self::BODY): string
    {
        return self::read($docx, $part);
    }

    /**
     * Every element an XPath expression matches.
     *
     * `query()` answers `false` for an expression it could not compile, which is not
     * the same fact as one that matched nothing and must not be read as it.
     *
     * @return list<\DOMElement>
     */
    public static function nodesOf(\DOMXPath $xpath, string $expression, ?\DOMNode $context = null): array
    {
        $found = $xpath->query($expression, $context);

        if ($found === false) {
            throw new FixtureUnavailable(sprintf('"%s" is not a query this document answers.', $expression));
        }

        $nodes = [];

        foreach ($found as $node) {
            if ($node instanceof \DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    public static function firstNode(\DOMXPath $xpath, string $expression): ?\DOMNode
    {
        return self::nodesOf($xpath, $expression)[0] ?? null;
    }

    private static function paragraphsOf(\DOMXPath $xpath): string
    {
        $parts = [];

        foreach (self::nodesOf($xpath, '//w:p') as $paragraph) {
            $parts[] = trim(self::textIn($xpath, $paragraph));
        }

        return trim(implode("\n", $parts));
    }

    private static function xpathOf(string $docx): \DOMXPath
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML(self::read($docx, WordPackage::mainPartOf($docx)), LIBXML_NOCDATA);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        return $xpath;
    }

    /**
     * One part of a finished package, as the text it holds.
     */
    private static function read(string $docx, string $part): string
    {
        $zip = new ZipArchive();

        if ($zip->open($docx) !== true) {
            throw new FixtureUnavailable(sprintf('Unable to open "%s".', $docx));
        }

        $contents = $zip->getFromName($part);
        $zip->close();

        if ($contents === false) {
            throw new FixtureUnavailable(sprintf('"%s" does not contain "%s".', $docx, $part));
        }

        return $contents;
    }
}
