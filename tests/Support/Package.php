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
 * Split from {@see TemplateFactory}, which builds one. The two were one class until
 * the reading half took it past twenty methods, and the reason they are separate is
 * that a test never does both — it either writes a fixture to open, or it opens one
 * that already exists to say something about.
 */
final class Package
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const BODY = 'word/document.xml';

    /**
     * The content type a finished document declares for its body part.
     *
     * What a reader decides a file is by, and the reason a filled-in `.dotx` is
     * not a document while every word in it is exactly where it should be.
     */
    public static function mainContentTypeOf(string $docx): string
    {
        $xml = self::xmlOf($docx, '[Content_Types].xml');

        preg_match('~PartName="/word/document[^"]*" ContentType="([^"]*)"~', $xml, $matches);

        return $matches[1] ?? '';
    }

    /**
     * The text of whichever part holds the body, read through the declarations.
     *
     * {@see self::textOf()} reads `word/document.xml` directly, which is right for
     * the documents this library writes and wrong for a package that calls its body
     * something else — which is the whole of what these tests are about.
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
     * The visible text of a document, with no regard for where it is broken up.
     *
     * What a reader sees is the concatenation of every `w:t` under a node, so a
     * run boundary is a detail of the writer rather than of the text. That makes
     * this the thing to compare when the question is whether the words survived
     * and the wrong thing when it is whether a paragraph is where it should be;
     * {@see self::textOf()} is that.
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
     * `query()` answers `false` for an expression it could not compile, which is a
     * different fact from one that matched nothing and must not be read as the second.
     * A `DOMNodeList` is walked as `DOMNode`, so a namespace node can appear in it; the
     * filter is here rather than a cast at each call site because none of the
     * expressions below can match one.
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

    /**
     * The first node an XPath expression matches, or null when it matches none.
     */
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
     *
     * {@see FixtureUnavailable} rather than a message, because a test that cannot open
     * its fixture has failed for a reason no assertion will ever describe.
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
