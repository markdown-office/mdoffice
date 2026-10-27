<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

use DOMDocument;
use DOMElement;

/**
 * The layouts of a deck: what each one holds.
 *
 * A layout is what it holds and not what it is called: the placeholders are the
 * same in every office and the names are not.
 */
final class Layouts
{
    /**
     * @param list<Layout> $layouts The layouts of the deck.
     */
    private function __construct(private readonly array $layouts)
    {
    }

    /**
     * The layouts of a deck.
     *
     * @param array<string, array{slides: int, words: int}> $usage The slides and words, by layout part.
     */
    public static function of(Package $package, array $usage): self
    {
        $layouts = [];

        foreach ($package->parts() as $name) {
            if (preg_match('~^ppt/slideLayouts/slideLayout(\d+)\.xml$~', $name, $matches) !== 1) {
                continue;
            }

            $document = $package->document($name);
            $layouts[(int) $matches[1]] = self::layoutFrom($document, $name, $usage);
        }

        ksort($layouts);

        return new self(array_values($layouts));
    }

    /**
     * The layouts of the deck, in the order the package lists them.
     *
     * @return list<Layout>
     */
    public function layouts(): array
    {
        return $this->layouts;
    }

    /**
     * One layout: its name and its placeholders.
     *
     * @param array<string, array{slides: int, words: int}> $usage
     */
    private static function layoutFrom(DOMDocument $document, string $part, array $usage): Layout
    {
        $root = Xml::child($document->documentElement, Xml::PRESENTATIONML, 'cSld');
        $name = $root?->getAttribute('name') ?? '';
        $placeholders = [];

        $tree = Xml::child($root, Xml::PRESENTATIONML, 'spTree');

        if ($tree !== null) {
            foreach ($tree->childNodes as $node) {
                if (! $node instanceof DOMElement || $node->namespaceURI !== Xml::PRESENTATIONML) {
                    continue;
                }

                $properties = Xml::child(Xml::child($node, Xml::PRESENTATIONML, 'nvSpPr'), Xml::PRESENTATIONML, 'nvPr');
                $placeholder = Xml::child($properties, Xml::PRESENTATIONML, 'ph');

                if ($placeholder === null) {
                    continue;
                }

                // A placeholder states no type when it is an ordinary content
                // placeholder, which is what the default means.
                $type = $placeholder->getAttribute('type');

                $placeholders[] = $type === '' ? 'body' : $type;
            }
        }

        return new Layout(
            $name,
            $placeholders,
            $usage[$part]['slides'] ?? 0,
            $usage[$part]['words'] ?? 0,
        );
    }
}
