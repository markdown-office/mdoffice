<?php

declare(strict_types=1);

namespace MarkdownOffice\Tests\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use MarkdownWord\Template\ContentControl;
use MarkdownWord\WordPackage;
use MarkdownWord\Xml;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;
use ZipArchive;

/**
 * Builds the Word documents the tests need as input, rather than checking binary
 * fixtures into the repository.
 *
 * Input, which is the whole of the distinction: a fixture exists so a test has
 * something to open, and nobody reads one.
 */
final class TemplateFactory
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** The parts of a package every operation below names, spelled once. */
    private const CONTENT_TYPES = '[Content_Types].xml';
    private const BODY = 'word/document.xml';
    private const BODY_RELS = 'word/_rels/document.xml.rels';
    private const PACKAGE_RELS = '_rels/.rels';

    /**
     * A template with a field, a title and a place for the content — what a report
     * letterhead actually needs.
     */
    public static function report(): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        $section->addText('Quarterly Report');
        // The marker is the whole of the value, so the field's name is whatever is
        // inside it. A marker in the middle of a sentence would need the text either
        // side kept, which is what {@see self::withControl()} does when it splits.
        $section->addText('@@reference@@');

        // `body`, the name {@see ContentControl::BODY} gives and the renderer looks for by
        // default. A template may name it anything — `insert()` takes the name — and
        // a letterhead whose control is called `terms` is as ordinary as one called
        // `body`; this fixture uses the default so the CLI tests exercise it.
        return self::withControl(self::write($phpWord, 'report'), ContentControl::BODY);
    }

    /**
     * A template with nothing but a place for the content, for tests that only care
     * about what is inserted.
     */
    public static function placeholder(string $name = 'body'): string
    {
        $phpWord = new PhpWord();
        $phpWord->addSection();

        return self::withControl(self::write($phpWord, 'placeholder-' . $name), $name);
    }

    /**
     * A template shaped the way a real one is: a masthead, a salutation, fields for
     * the values, and a closing block a person wrote by hand. **No content controls at
     * all**, because nobody making a company letterhead inserts one.
     *
     * This is the case the renderer has to survive. A template authored for a
     * particular tool carries that tool's placeholder; one authored in Word to be
     * filled in by a person carries none, and refusing those would leave the library
     * able to use only the templates it wrote itself.
     */
    public static function letterhead(): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        $section->addText('Northwind Ltd');
        $section->addText('@@client@@');
        $section->addText('Dear colleague,');
        $section->addText('@@reference@@');
        // The closing matter a letterhead ends with, which is the whole reason an
        // append-at-the-end fallback has to be a decision rather than an accident.
        $section->addText('Yours sincerely,');
        $section->addText('The Management');

        return self::withFields(self::write($phpWord, 'letterhead'));
    }

    /**
     * A template that names no place for the content under the name the renderer uses.
     *
     * It does carry a control, called something else, so the failure names what is
     * there — which is the whole of the fix for whoever has to write the template.
     */
    public static function noControl(): string
    {
        $phpWord = new PhpWord();
        $phpWord->addSection();

        return self::withControl(self::write($phpWord, 'no-control'), 'terms');
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
     * @return list<DOMElement>
     */
    private static function nodesOf(DOMXPath $xpath, string $expression, ?DOMNode $context = null): array
    {
        $found = $xpath->query($expression, $context);

        if ($found === false) {
            throw new RuntimeException(sprintf('"%s" is not a query this document answers.', $expression));
        }

        $nodes = [];

        foreach ($found as $node) {
            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * The first node an XPath expression matches, or null when it matches none.
     */
    private static function firstNode(DOMXPath $xpath, string $expression): ?DOMNode
    {
        return self::nodesOf($xpath, $expression)[0] ?? null;
    }

    /**
     * One part of an open archive, as the text it holds.
     *
     * Every caller rewrites what it gets and writes it back, so an absent part
     * returned as `''` becomes a blank one and the fixture still builds.
     */
    private static function part(ZipArchive $zip, string $name): string
    {
        $content = $zip->getFromName($name);

        if ($content === false) {
            throw new RuntimeException(sprintf('The template has no "%s" part to rewrite.', $name));
        }

        return $content;
    }

    /**
     * A real `.dotx` — the same document with its body declared as a template's.
     *
     * Built rather than checked in, because a `.dotx` is a `.docx` with one line of
     * `[Content_Types].xml` changed and there is nothing in that line a binary
     * fixture would show a reader.
     *
     * @param string $mainPart The name the body part is given inside the archive.
     *        `document22.xml` is what documents from Word Online carry, and it is the
     *        case a lookup that assumes `document.xml` gets wrong.
     * @param string $source The template to convert; the default is the plain
     *        `${body}` region, and {@see self::report()} is the one carrying text of
     *        its own — which is what lets a test tell a document rendered into a
     *        template from the same Markdown rendered into nothing.
     */
    public static function dotx(
        string $mainPart = 'document.xml',
        string $name = 'dotx',
        ?string $source = null,
    ): string {
        $source ??= self::placeholder();
        $path = Scratch::path($name . '-' . $mainPart . '.dotx');

        $zip = new ZipArchive();
        $zip->open($source);

        try {
            // Each of these four is `false` when the archive has no such part, and
            // `str_replace` over `false` writes an empty part rather than failing: a
            // fixture that is quietly missing its body still renders, and a test
            // asserting on the result would pass for the wrong reason.
            $declared = self::part($zip, self::CONTENT_TYPES);
            $document = self::part($zip, self::BODY);
            $rels = self::part($zip, self::BODY_RELS);
            $root = self::part($zip, self::PACKAGE_RELS);
        } finally {
            $zip->close();
        }

        copy($source, $path);

        $out = new ZipArchive();
        $out->open($path);

        // Renaming the body means renaming what points at it too, or the package is
        // one no reader can follow the body into.
        if ($mainPart !== 'document.xml') {
            $out->deleteName(self::BODY);
            $out->addFromString('word/' . $mainPart, $document);
            $out->deleteName(self::BODY_RELS);
            $out->addFromString(
                'word/_rels/' . $mainPart . '.rels',
                str_replace('document.xml', $mainPart, $rels),
            );
            $out->deleteName(self::PACKAGE_RELS);
            $out->addFromString(self::PACKAGE_RELS, str_replace('word/document.xml', 'word/' . $mainPart, $root));

            $declared = str_replace('/word/document.xml', '/word/' . $mainPart, $declared);
        }

        $out->deleteName(self::CONTENT_TYPES);
        $out->addFromString(self::CONTENT_TYPES, str_replace(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.template.main+xml',
            $declared,
        ));
        $out->close();

        return $path;
    }

    /**
     * The content type a finished document declares for its body part.
     *
     * What a reader decides a file is by, and the reason a filled-in `.dotx` is
     * not a document while every word in it is exactly where it should be.
     */
    public static function mainContentTypeOf(string $docx): string
    {
        $xml = self::xmlOf($docx, self::CONTENT_TYPES);

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
        $xpath = self::xpathOf($docx);

        $parts = [];
        foreach (self::nodesOf($xpath, '//w:p') as $paragraph) {
            $parts[] = trim(self::textIn($xpath, $paragraph));
        }

        return trim(implode("\n", $parts));
    }

    /**
 * Put a content control into a written template, and turn `@@name@@` into a field.
 *
 * Both are things PHPWord has no API for, which is the point: a template comes from
 * Word, where a person inserts a content control from the Developer tab and a field
 * with Ctrl+F9. Writing them here rather than with `${…}` markers is what keeps the
 * tests honest about what the library actually reads.
 *
 * @param string $body Name of the content control the Markdown goes into.
 */
private static function withControl(string $path, string $body): string
{
    return self::withFields($path, $body);
}

/**
 * Turn every `@@name@@` marker into a real `MERGEFIELD`, and put a content control of
 * that name into the body.
 *
 * Two jobs rather than one because a real template can have either: {@see self::letterhead()}
 * has fields and no control, which is what a letterhead a person made in Word looks
 * like.
 *
 * @param string|null $body The control to add, or null for fields alone.
 */
/**
 * Every run whose text carries a `@@name@@` marker becomes a merge field.
 *
 * A marker is the whole of the value here, so the field takes the name inside it.
 * Word does the same when a field is typed into a sentence: the run is split and
 * what is either side of the field stays. `@@reference@@` splits into three parts —
 * empty, the name, empty — so the name is the middle one.
 */
private static function replaceMarkedRuns(DOMDocument $dom, DOMXPath $xpath): void
{
    foreach ($xpath->query('//w:r[w:t]') ?: [] as $run) {
        if (!$run instanceof DOMElement) {
            continue;
        }

        $text = $run->getElementsByTagNameNS(self::W_NS, 't')->item(0);

        if (!$text instanceof DOMElement || !str_contains((string) $text->textContent, '@@')) {
            continue;
        }

        // A `w:r` this matched always has a parent it came from, so a run without one
        // is a document nothing else put together.
        $parent = $run->parentNode;

        if (!$parent instanceof DOMNode) {
            throw new RuntimeException('A run carrying a marker has no parent to replace it in.');
        }

        [, $name, $after] = explode('@@', (string) $text->textContent, 3);
        $field = self::mergeField($dom, $name);

        $parent->insertBefore($field, $run);
        $parent->removeChild($run);

        if ($after !== '') {
            $tail = $dom->createElementNS(self::W_NS, 'w:r');
            $tailText = $dom->createElementNS(self::W_NS, 'w:t');
            $tailText->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            $tailText->appendChild($dom->createTextNode($after));
            $tail->appendChild($tailText);
            $parent->insertBefore($tail, $field->nextSibling);
        }
    }
}

/**
 * Put the content control where the renderer looks for it.
 */
private static function putContentControl(DOMDocument $dom, DOMXPath $xpath, string $body): void
{
    $document = $dom->documentElement;
    $control = Xml::parse(
        sprintf(
            '<root xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            . 'xmlns:xml="http://www.w3.org/XML/1998/namespace">%s</root>',
            ContentControl::markup($body),
        ),
    )?->documentElement?->firstChild;

    if (!$document instanceof DOMElement || !$control instanceof DOMNode) {
        throw new RuntimeException('The content control could not be built.');
    }

    $bodyElement = self::firstNode($xpath, '//w:body');

    if (!$bodyElement instanceof DOMElement) {
        throw new RuntimeException('The document has no body to put the content control in.');
    }

    // Before the `w:sectPr`, which has to stay last in a body or Word rejects it.
    $section = self::firstNode($xpath, '//w:body/w:sectPr');

    if ($section instanceof DOMNode) {
        $bodyElement->insertBefore($dom->importNode($control, true), $section);
    } else {
        $bodyElement->appendChild($dom->importNode($control, true));
    }
}

private static function withFields(string $path, ?string $body = null): string
{
    $zip = new ZipArchive();
    $zip->open($path);
    $xml = (string) $zip->getFromName(self::BODY);
    $zip->close();

    $dom = Xml::parse($xml) ?? throw new RuntimeException(
        sprintf('"%s" has a document part that is not XML.', $path),
    );

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    self::replaceMarkedRuns($dom, $xpath);

    if ($body !== null) {
        self::putContentControl($dom, $xpath, $body);
    }

    $zip = new ZipArchive();
    $zip->open($path);
    $zip->deleteName(self::BODY);
    $zip->addFromString(self::BODY, (string) $dom->saveXML());
    $zip->close();

    return $path;
}

/** A `w:fldSimple` naming a `MERGEFIELD`, in the shape Word writes. */
private static function mergeField(DOMDocument $dom, string $name): DOMElement
{
    $field = $dom->createElementNS(self::W_NS, 'w:fldSimple');
    $field->setAttributeNS(self::W_NS, 'w:instr', ' MERGEFIELD ' . trim($name, ' ') . ' \\* MERGEFORMAT ');

    $run = $dom->createElementNS(self::W_NS, 'w:r');
    $text = $dom->createElementNS(self::W_NS, 'w:t');
    $text->appendChild($dom->createTextNode('«' . trim($name, ' ') . '»'));
    $run->appendChild($text);
    $field->appendChild($run);

    return $field;
}

    private static function write(PhpWord $phpWord, string $name): string
    {
        $path = Scratch::path($name);

        Upstream::quietly(static function () use ($phpWord, $path): void {
            IOFactory::createWriter($phpWord, 'Word2007')->save($path);
        });

        return $path;
    }

    public static function textOf(string $docx): string
    {
        $xpath = self::xpathOf($docx);

        $parts = [];
        foreach (self::nodesOf($xpath, '//w:p') as $paragraph) {
            $parts[] = trim(self::textIn($xpath, $paragraph));
        }

        return trim(implode("\n", $parts));
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

    private static function xpathOf(string $docx): \DOMXPath
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML(self::read($docx, WordPackage::mainPartOf($docx)), LIBXML_NOCDATA);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        return $xpath;
    }

    public static function xmlOf(string $docx, string $part = self::BODY): string
    {
        return self::read($docx, $part);
    }

    private static function read(string $docx, string $part): string
    {
        $zip = new ZipArchive();

        if ($zip->open($docx) !== true) {
            throw new RuntimeException(sprintf('Unable to open "%s".', $docx));
        }

        $contents = $zip->getFromName($part);
        $zip->close();

        if ($contents === false) {
            throw new RuntimeException(sprintf('"%s" does not contain "%s".', $docx, $part));
        }

        return $contents;
    }
}
