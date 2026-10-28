<?php

declare(strict_types=1);

/**
 * What the interpreter that runs this file ended up with.
 *
 * A released binary cannot be asked what it carries: it runs one program and stops.
 * So the same interpreter is asked instead — the `micro` SAPI with this file
 * appended to it, which is the very file the released binary is built from. What it
 * reports is therefore what the released binary has, and not what a sibling build of
 * the same commit happened to have.
 *
 * The report is three lines, because the smoke test reads them and does not parse
 * PHP:
 *
 *     extensions: dom,gd,imagick,zip
 *     jpeg: yes
 *     parts: [Content_Types].xml,_rels/.rels,ppt/presentation.xml
 *
 * The names are lowercased, because `SimpleXML` and `simplexml` are the same
 * extension and the smoke test compares them against the names the libraries ask
 * for.
 */

$extensions = array_map('strtolower', get_loaded_extensions());

sort($extensions);

echo 'extensions: ', implode(',', $extensions), PHP_EOL;

$arguments = $_SERVER['argv'] ?? [];

/**
 * The path of the JPEG to read, when there is one and it is a path.
 *
 * `$_SERVER['argv']` is whatever the SAPI put there, and this file runs in a
 * program with a fixed job, so the check is the narrowing rather than an assertion.
 */
$jpeg = is_array($arguments) && is_string($arguments[1] ?? null) ? $arguments[1] : '';

/**
 * `ext-gd` being present says nothing about the libraries behind it: the function
 * is compiled in either way, and only reading a JPEG tells the difference. The
 * file is checked first, because a missing one is a missing file and not a library
 * that cannot read it.
 */
$readable = $jpeg !== '' && is_file($jpeg) && imagecreatefromjpeg($jpeg) !== false;

echo 'jpeg: ', $readable ? 'yes' : 'no', PHP_EOL;

/**
 * The parts of an archive, when there is one and it can be opened.
 *
 * The archive is opened by the interpreter that is being probed, which is the one
 * the binary carries, so what it says about the archive is what the binary would
 * say about it. The smoke test uses it to look inside a deck the binary wrote
 * without needing `ext-zip` in the PHP that reads the test.
 */
$parts = [];
$relationships = 'none';
$contentType = 'none';

if (is_array($arguments) && is_string($arguments[2] ?? null) && is_file($arguments[2]) && class_exists(ZipArchive::class)) {
    $archive = new ZipArchive();

    if ($archive->open($arguments[2]) === true) {
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $parts[] = (string) $archive->getNameIndex($index);
        }

        // The relationships of the archive name what is in it. A reader that
        // cannot find them cannot read the deck, so whether they are there is
        // worth saying.
        $root = $archive->getFromName('_rels/.rels');

        if ($root !== false) {
            $relationships = str_contains($root, 'officeDocument') ? 'yes' : 'no';

            // The relationships are written out as they are: a reader that
            // cannot make sense of them says why, and the bytes say why.
            echo 'rels: ', str_replace("\n", "\nrels: ", $root), PHP_EOL;
        }

        // The content type of the presentation, as the package's content-type
        // stream declares it. A reader looks for this before it looks for
        // anything else, and it is found by parsing the stream rather than by
        // reading it, so whether the stream parses is worth saying too.
        $types = $archive->getFromName('[Content_Types].xml');

        if ($types !== false && class_exists(DOMDocument::class)) {
            $document = new DOMDocument();

            if ($document->loadXML($types, LIBXML_NONET)) {
                $xpath = new DOMXPath($document);
                $nodes = $xpath->query('*');

                foreach ($nodes === false ? [] : $nodes as $node) {
                    if ($node instanceof DOMElement
                        && 'Override' === $node->localName
                        && 0 === strcasecmp(ltrim($node->getAttribute('PartName'), '/'), 'ppt/presentation.xml')) {
                        $contentType = $node->getAttribute('ContentType');

                        break;
                    }
                }
            }
        }

        $archive->close();
    }
}

echo 'parts: ', implode(',', $parts), PHP_EOL;

echo 'relationships: ', $relationships, PHP_EOL;

echo 'content-type: ', $contentType, PHP_EOL;
