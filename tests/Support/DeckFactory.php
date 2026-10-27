<?php

declare(strict_types=1);

namespace MarkdownOffice\Tests\Support;

use ZipArchive;

/**
 * Builds the decks the tests need as input, rather than checking binary fixtures
 * into the repository.
 *
 * Input, which is the whole of the distinction: a fixture exists so a test has
 * something to open, and nobody reads one.
 *
 * The package is written by hand rather than through PHPPresentation, because
 * the two things the tests are about are the two that library does not write: a
 * layout's placeholders and a layout's name. A deck made by the library has
 * layouts with neither, and a theme scaffolded from it could not be told apart
 * from one scaffolded from any other deck.
 */
final class DeckFactory
{
    private const NS = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';

    private const RELS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
    private const PACKAGE_RELS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * The twelve colour slots, in the order a theme part lists them.
     */
    private const SLOTS = [
        'dk1', 'lt1', 'dk2', 'lt2', 'accent1', 'accent2',
        'accent3', 'accent4', 'accent5', 'accent6', 'hlink', 'folHlink',
    ];

    /**
     * A deck with the given layouts and slides.
     *
     * @param list<array{name: string, placeholders: list<string>}> $layouts The layouts, in the
     *        order the deck holds them. A placeholder is `title`, `subTitle`, `body`, `pic` or
     *        one of the furniture types `dt`, `ftr` and `sldNum`.
     * @param list<array{text: string, size: int, colour: string, face: string, background: ?string, layout: int, fill?: ?string}> $slides
     *        The slides, each with the text it carries and the layout it uses.
     * @param array<string, string>                               $scheme The theme part's colour slots.
     * @param array{heading: string, body: string}               $fonts  The theme part's latin typefaces.
     */
    public static function deck(
        array $layouts,
        array $slides,
        array $scheme = [],
        array $fonts = ['heading' => 'Arial', 'body' => 'Arial'],
    ): string {
        $path = Scratch::path('deck', '.pptx');

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new FixtureUnavailable(sprintf('Unable to write the fixture "%s".', $path));
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes(count($layouts), count($slides)));
        $zip->addFromString('_rels/.rels', self::rootRelationships());
        $zip->addFromString('ppt/presentation.xml', self::presentation(count($slides)));
        $zip->addFromString('ppt/_rels/presentation.xml.rels', self::presentationRelationships(count($slides)));
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', self::master(count($layouts)));
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', self::masterRelationships(count($layouts)));
        $zip->addFromString('ppt/theme/theme1.xml', self::theme($scheme, $fonts));

        foreach ($layouts as $index => $layout) {
            $number = $index + 1;
            $zip->addFromString(sprintf('ppt/slideLayouts/slideLayout%d.xml', $number), self::layout($layout));
            $zip->addFromString(sprintf('ppt/slideLayouts/_rels/slideLayout%d.xml.rels', $number), self::layoutRelationships());
        }

        foreach ($slides as $index => $slide) {
            $number = $index + 1;
            $zip->addFromString(sprintf('ppt/slides/slide%d.xml', $number), self::slide($slide));
            $zip->addFromString(sprintf('ppt/slides/_rels/slide%d.xml.rels', $number), self::slideRelationships($slide['layout']));
        }

        $zip->close();

        return $path;
    }

    private static function contentTypes(int $layouts, int $slides): string
    {
        $types = [
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>',
            '<Default Extension="xml" ContentType="application/xml"/>',
            '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>',
            '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>',
            '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>',
        ];

        for ($number = 1; $number <= $layouts; $number++) {
            $types[] = sprintf(
                '<Override PartName="/ppt/slideLayouts/slideLayout%d.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>',
                $number,
            );
        }

        for ($number = 1; $number <= $slides; $number++) {
            $types[] = sprintf(
                '<Override PartName="/ppt/slides/slide%d.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>',
                $number,
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . implode('', $types)
            . '</Types>';
    }

    private static function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . sprintf(
                '<Relationships xmlns="%s"><Relationship Id="rId1" Type="%sofficeDocument" Target="ppt/presentation.xml"/></Relationships>',
                self::PACKAGE_RELS,
                self::RELS,
            );
    }

    private static function presentation(int $slides): string
    {
        $ids = '';

        for ($number = 1; $number <= $slides; $number++) {
            $ids .= sprintf('<p:sldId id="%d" r:id="rId%d"/>', 255 + $number, $number + 1);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . sprintf('<p:presentation %s>', self::NS)
            . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
            . sprintf('<p:sldIdLst>%s</p:sldIdLst>', $ids)
            . '<p:sldSz cx="9144000" cy="5143500" type="screen16x9"/>'
            . '<p:notesSz cx="6858000" cy="9144000"/>'
            . '</p:presentation>';
    }

    private static function presentationRelationships(int $slides): string
    {
        $relationships = [
            sprintf('<Relationship Id="rId1" Type="%sslideMaster" Target="slideMasters/slideMaster1.xml"/>', self::RELS),
        ];

        for ($number = 1; $number <= $slides; $number++) {
            $relationships[] = sprintf(
                '<Relationship Id="rId%d" Type="%sslide" Target="slides/slide%d.xml"/>',
                $number + 1,
                self::RELS,
                $number,
            );
        }

        $relationships[] = sprintf(
            '<Relationship Id="rId%d" Type="%stheme" Target="theme/theme1.xml"/>',
            $slides + 2,
            self::RELS,
        );

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . sprintf('<Relationships xmlns="%s">', self::PACKAGE_RELS)
            . implode('', $relationships)
            . '</Relationships>';
    }

    private static function master(int $layouts): string
    {
        $ids = '';

        for ($number = 1; $number <= $layouts; $number++) {
            $ids .= sprintf('<p:sldLayoutId id="%d" r:id="rId%d"/>', 2147483648 + $number, $number);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . sprintf('<p:sldMaster %s>', self::NS)
            . '<p:cSld><p:bg><p:bgRef idx="1001"><a:schemeClr val="bg1"/></p:bgRef></p:bg>'
            . '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
            . '<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr></p:spTree></p:cSld>'
            . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2"'
            . ' accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6"'
            . ' hlink="hlink" folHlink="folHlink"/>'
            . sprintf('<p:sldLayoutIdLst>%s</p:sldLayoutIdLst>', $ids)
            . '</p:sldMaster>';
    }

    private static function masterRelationships(int $layouts): string
    {
        $relationships = [];

        for ($number = 1; $number <= $layouts; $number++) {
            $relationships[] = sprintf(
                '<Relationship Id="rId%d" Type="%sslideLayout" Target="../slideLayouts/slideLayout%d.xml"/>',
                $number,
                self::RELS,
                $number,
            );
        }

        $relationships[] = sprintf(
            '<Relationship Id="rId%d" Type="%stheme" Target="../theme/theme1.xml"/>',
            $layouts + 1,
            self::RELS,
        );

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . sprintf('<Relationships xmlns="%s">', self::PACKAGE_RELS)
            . implode('', $relationships)
            . '</Relationships>';
    }

    /**
     * @param array{name: string, placeholders: list<string>} $layout
     */
    private static function layout(array $layout): string
    {
        $shapes = '';
        $id = 2;

        foreach ($layout['placeholders'] as $placeholder) {
            $type = $placeholder === 'body' ? '' : sprintf(' type="%s"', $placeholder);
            $shapes .= sprintf(
                '<p:sp><p:nvSpPr><p:cNvPr id="%d" name="Placeholder %d"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr>'
                . '<p:nvPr><p:ph%s/></p:nvPr></p:nvSpPr><p:spPr/>'
                . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p/></p:txBody></p:sp>',
                $id,
                $id,
                $type,
            );
            $id++;
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . sprintf('<p:sldLayout %s type="blank" preserve="1">', self::NS)
            . sprintf('<p:cSld name="%s">', htmlspecialchars($layout['name'], ENT_QUOTES))
            . '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr/>'
            . $shapes
            . '</p:spTree></p:cSld>'
            . '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>'
            . '</p:sldLayout>';
    }

    private static function layoutRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . sprintf(
                '<Relationships xmlns="%s"><Relationship Id="rId1" Type="%sslideMaster" Target="../slideMasters/slideMaster1.xml"/></Relationships>',
                self::PACKAGE_RELS,
                self::RELS,
            );
    }

    /**
     * @param array{text: string, size: int, colour: string, face: string, background: ?string, layout: int, fill?: ?string, scheme?: ?string} $slide
     */
    private static function slide(array $slide): string
    {
        $background = '';

        if ($slide['background'] !== null) {
            $background = sprintf(
                '<p:bg><p:bgPr><a:solidFill><a:srgbClr val="%s"/></a:solidFill></p:bgPr></p:bg>',
                $slide['background'],
            );
        }

        $filled = '';

        if (($slide['fill'] ?? null) !== null) {
            $filled = sprintf(
                '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Shape"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
                . '<p:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
                . '<a:solidFill><a:srgbClr val="%s"/></a:solidFill></p:spPr>'
                . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p/></p:txBody></p:sp>',
                $slide['fill'],
            );
        }

        // A deck drawn in its own theme names the theme's slots rather than
        // spelling the colours out, and that is what tells the two apart.
        $colour = ($slide['scheme'] ?? null) !== null
            ? sprintf('<a:schemeClr val="%s"/>', $slide['scheme'])
            : sprintf('<a:srgbClr val="%s"/>', $slide['colour']);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . sprintf('<p:sld %s>', self::NS)
            . '<p:cSld>'
            . $background
            . '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr/>'
            . $filled
            . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Text"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr/>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:lnSpc><a:spcPct val="100000"/></a:lnSpc></a:pPr>'
            . sprintf(
                '<a:r><a:rPr lang="en-US" sz="%d"><a:solidFill>%s</a:solidFill>'
                . '<a:latin typeface="%s"/></a:rPr><a:t>%s</a:t></a:r>',
                $slide['size'] * 100,
                $colour,
                htmlspecialchars($slide['face'], ENT_QUOTES),
                htmlspecialchars($slide['text'], ENT_QUOTES),
            )
            . '</a:p></p:txBody></p:sp></p:spTree></p:cSld>'
            . '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>'
            . '</p:sld>';
    }

    private static function slideRelationships(int $layout): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . sprintf(
                '<Relationships xmlns="%s"><Relationship Id="rId1" Type="%sslideLayout" Target="../slideLayouts/slideLayout%d.xml"/></Relationships>',
                self::PACKAGE_RELS,
                self::RELS,
                $layout + 1,
            );
    }

    /**
     * The theme part: the colour scheme and the font scheme.
     *
     * @param array<string, string>         $scheme
     * @param array{heading: string, body: string} $fonts
     */
    private static function theme(array $scheme, array $fonts): string
    {
        $colours = '';

        foreach (self::SLOTS as $slot) {
            $colour = $scheme[$slot] ?? '000000';
            $colours .= sprintf('<a:%s><a:srgbClr val="%s"/></a:%s>', $slot, $colour, $slot);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Office">'
            . '<a:themeElements>'
            . sprintf('<a:clrScheme name="Office">%s</a:clrScheme>', $colours)
            . sprintf(
                '<a:fontScheme name="Office"><a:majorFont><a:latin typeface="%s"/></a:majorFont>'
                . '<a:minorFont><a:latin typeface="%s"/></a:minorFont></a:fontScheme>',
                htmlspecialchars($fonts['heading'], ENT_QUOTES),
                htmlspecialchars($fonts['body'], ENT_QUOTES),
            )
            . '<a:fmtScheme name="Office">'
            . '<a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst>'
            . '<a:lnStyleLst><a:ln><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln></a:lnStyleLst>'
            . '<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'
            . '<a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:bgFillStyleLst>'
            . '</a:fmtScheme>'
            . '</a:themeElements></a:theme>';
    }
}
