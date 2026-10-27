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
 *
 * @phpstan-type Layout array{name: string, placeholders: list<string>}
 * @phpstan-type Shape array{name: string, id?: int, at?: array{x: int, y: int, cx: int, cy: int}, fill?: ?string, outline?: ?string, alpha?: ?int, kind?: string, runs?: list<array{text: string, colour: string}>}
 * @phpstan-type Slide array{text: string, size: int, colour: string, face: string, background: ?string, layout: int, fill?: ?string, scheme?: ?string, id?: int, shapes?: list<Shape>}
 * @phpstan-type Options array{size?: array{int, int}, list?: bool, theme?: bool, master?: bool, map?: array<string, string>, slots?: list<string>, sys?: list<string>}
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
     * @param list<Layout> $layouts The layouts, in the order the deck holds them. A
     *        placeholder is `title`, `subTitle`, `body`, `pic` or one of the furniture
     *        types `dt`, `ftr` and `sldNum`.
     * @param list<Slide> $slides The slides, each with the text it carries and the layout
     *        it uses. A slide may state its own shapes instead, which is what a deck
     *        whose shapes stand at a place of their own is.
     * @param array<string, string> $scheme The theme part's colour slots.
     * @param array{heading: string, body: string} $fonts The theme part's latin typefaces.
     * @param array{created?: string, modified?: string, title?: string, creator?: string, written?: int} $properties
     *        The document's own properties, and the time the archive says it was written at.
     * @param Options $options The shape of the package: the page the slides are, whether
     *        the deck lists its slides, whether it carries a theme part and a master, the
     *        colour map of the master, the slots the theme part declares and the slots
     *        it declares as a system colour.
     */
    public static function deck(
        array $layouts,
        array $slides,
        array $scheme = [],
        array $fonts = ['heading' => 'Arial', 'body' => 'Arial'],
        array $properties = [],
        array $options = [],
    ): string {
        $path = Scratch::path('deck', '.pptx');

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new FixtureUnavailable(sprintf('Unable to write the fixture "%s".', $path));
        }

        $theme = $options['theme'] ?? true;
        $master = $options['master'] ?? true;

        $zip->addFromString('[Content_Types].xml', self::contentTypes(count($layouts), count($slides), $theme, $master));
        $zip->addFromString('_rels/.rels', self::rootRelationships());
        $zip->addFromString('ppt/presentation.xml', self::presentation($slides, $options));
        $zip->addFromString('ppt/_rels/presentation.xml.rels', self::presentationRelationships(count($slides), $theme));

        if ($master) {
            $zip->addFromString('ppt/slideMasters/slideMaster1.xml', self::master(count($layouts), $options));
            $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', self::masterRelationships(count($layouts), $theme));
        }

        if ($theme) {
            $zip->addFromString('ppt/theme/theme1.xml', self::theme($scheme, $fonts, $options));
        }

        if ($properties !== []) {
            $zip->addFromString('docProps/core.xml', self::core($properties));
        }

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

        self::write($zip, $properties);

        $zip->close();

        return $path;
    }

    /**
     * The time the archive says it was written at, on every part of it.
     *
     * A zip stores the time of each entry in a field of its own, and it counts in
     * seconds of two, so two renderings of the same deck written a moment apart
     * are two different files.
     *
     * @param array{created?: string, modified?: string, title?: string, creator?: string, written?: int} $properties
     */
    private static function write(ZipArchive $zip, array $properties): void
    {
        $written = $properties['written'] ?? null;

        if ($written === null) {
            return;
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $zip->setMtimeName((string) $zip->getNameIndex($index), $written);
        }
    }

    /**
     * The document's own properties.
     *
     * @param array{created?: string, modified?: string, title?: string, creator?: string, written?: int} $properties
     */
    private static function core(array $properties): string
    {
        $lines = '';

        if (isset($properties['title'])) {
            $lines .= sprintf('<dc:title>%s</dc:title>', htmlspecialchars($properties['title'], ENT_QUOTES));
        }

        if (isset($properties['creator'])) {
            $lines .= sprintf('<dc:creator>%s</dc:creator>', htmlspecialchars($properties['creator'], ENT_QUOTES));
        }

        foreach (['created', 'modified'] as $moment) {
            if (!isset($properties[$moment])) {
                continue;
            }

            $lines .= sprintf(
                '<dcterms:%s xsi:type="dcterms:W3CDTF">%s</dcterms:%s>',
                $moment,
                $properties[$moment],
                $moment,
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . $lines
            . '</cp:coreProperties>';
    }

    private static function contentTypes(int $layouts, int $slides, bool $theme, bool $master): string
    {
        $types = [
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>',
            '<Default Extension="xml" ContentType="application/xml"/>',
            '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>',
        ];

        if ($master) {
            $types[] = '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>';
        }

        if ($theme) {
            $types[] = '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>';
        }

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

    /**
     * The presentation part, with the list of slides.
     *
     * @param list<Slide> $slides
     * @param Options     $options
     */
    private static function presentation(array $slides, array $options = []): string
    {
        $ids = '';

        foreach ($slides as $index => $slide) {
            $ids .= sprintf(
                '<p:sldId id="%d" r:id="rId%d"/>',
                $slide['id'] ?? 255 + $index + 1,
                $index + 2,
            );
        }

        // A deck with no list of slides still has slide parts, and a reader that
        // knows no better takes them in the order they are numbered in.
        $list = ($options['list'] ?? true) === true
            ? sprintf('<p:sldIdLst>%s</p:sldIdLst>', $ids)
            : '';

        $size = $options['size'] ?? [9144000, 5143500];
        $type = $size === [9144000, 5143500] ? 'screen16x9' : 'custom';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . sprintf('<p:presentation %s>', self::NS)
            . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
            . $list
            . sprintf('<p:sldSz cx="%d" cy="%d" type="%s"/>', $size[0], $size[1], $type)
            . '<p:notesSz cx="6858000" cy="9144000"/>'
            . '</p:presentation>';
    }

    private static function presentationRelationships(int $slides, bool $theme): string
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

        if ($theme) {
            $relationships[] = sprintf(
                '<Relationship Id="rId%d" Type="%stheme" Target="theme/theme1.xml"/>',
                $slides + 2,
                self::RELS,
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . sprintf('<Relationships xmlns="%s">', self::PACKAGE_RELS)
            . implode('', $relationships)
            . '</Relationships>';
    }

    /**
     * @param Options $options
     */
    private static function master(int $layouts, array $options = []): string
    {
        $ids = '';

        for ($number = 1; $number <= $layouts; $number++) {
            $ids .= sprintf('<p:sldLayoutId id="%d" r:id="rId%d"/>', 2147483648 + $number, $number);
        }

        $map = $options['map'] ?? [
            'bg1' => 'lt1', 'tx1' => 'dk1', 'bg2' => 'lt2', 'tx2' => 'dk2',
            'accent1' => 'accent1', 'accent2' => 'accent2',
            'accent3' => 'accent3', 'accent4' => 'accent4',
            'accent5' => 'accent5', 'accent6' => 'accent6',
            'hlink' => 'hlink', 'folHlink' => 'folHlink',
        ];

        $attributes = '';

        foreach ($map as $slot => $target) {
            $attributes .= sprintf(' %s="%s"', $slot, $target);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . sprintf('<p:sldMaster %s>', self::NS)
            . '<p:cSld><p:bg><p:bgRef idx="1001"><a:schemeClr val="bg1"/></p:bgRef></p:bg>'
            . '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
            . '<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr></p:spTree></p:cSld>'
            . sprintf('<p:clrMap%s/>', $attributes)
            . sprintf('<p:sldLayoutIdLst>%s</p:sldLayoutIdLst>', $ids)
            . '</p:sldMaster>';
    }

    private static function masterRelationships(int $layouts, bool $theme): string
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

        if ($theme) {
            $relationships[] = sprintf(
                '<Relationship Id="rId%d" Type="%stheme" Target="../theme/theme1.xml"/>',
                $layouts + 1,
                self::RELS,
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . sprintf('<Relationships xmlns="%s">', self::PACKAGE_RELS)
            . implode('', $relationships)
            . '</Relationships>';
    }

    /**
     * @param Layout $layout
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
     * @param Slide $slide
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

        $shapes = isset($slide['shapes'])
            ? self::shapes($slide['shapes'])
            : self::plainShapes($slide);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . sprintf('<p:sld %s>', self::NS)
            . '<p:cSld>'
            . $background
            . '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr/>'
            . $shapes
            . '</p:spTree></p:cSld>'
            . '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>'
            . '</p:sld>';
    }

    /**
     * The shapes a slide has when it does not state its own.
     *
     * @param Slide $slide
     */
    private static function plainShapes(array $slide): string
    {
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

        return $filled
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
            . '</a:p></p:txBody></p:sp>';
    }

    /**
     * The shapes a slide states for itself.
     *
     * @param list<Shape> $shapes
     */
    private static function shapes(array $shapes): string
    {
        $markup = '';
        $id = 2;

        foreach ($shapes as $shape) {
            $markup .= self::shape($shape, $id);
            $id++;
        }

        return $markup;
    }

    /**
     * One shape, as a slide states it.
     *
     * A shape is a shape, a picture or a group of shapes, and the three are told
     * apart by the `kind` it is given: the format writes a picture as a picture
     * and a group as a group, and a reader that takes them for shapes reads a
     * picture's name as the name of a shape with no text in it.
     *
     * @param Shape $shape
     */
    private static function shape(array $shape, int $fallback): string
    {
        $transform = '';

        if (isset($shape['at'])) {
            $at = $shape['at'];

            $transform = sprintf(
                '<a:xfrm><a:off x="%d" y="%d"/><a:ext cx="%d" cy="%d"/></a:xfrm>',
                $at['x'],
                $at['y'],
                $at['cx'],
                $at['cy'],
            );
        }

        $fill = '';

        if (($shape['fill'] ?? null) !== null) {
            $fill = sprintf(
                '<a:solidFill><a:srgbClr val="%s">%s</a:srgbClr></a:solidFill>',
                $shape['fill'],
                self::change($shape['alpha'] ?? null),
            );
        }

        $outline = '';

        if (($shape['outline'] ?? null) !== null) {
            $outline = sprintf(
                '<a:ln><a:solidFill><a:srgbClr val="%s"/></a:solidFill></a:ln>',
                $shape['outline'],
            );
        }

        $paragraphs = '';

        foreach ($shape['runs'] ?? [] as $run) {
            $paragraphs .= sprintf(
                '<a:p><a:r><a:rPr lang="en-US" sz="1800"><a:solidFill><a:srgbClr val="%s"/></a:solidFill></a:rPr>'
                . '<a:t>%s</a:t></a:r></a:p>',
                $run['colour'],
                htmlspecialchars($run['text'], ENT_QUOTES),
            );
        }

        $id = $shape['id'] ?? $fallback;
        $name = htmlspecialchars($shape['name'], ENT_QUOTES);

        if (($shape['kind'] ?? null) === 'picture') {
            return sprintf(
                '<p:pic><p:nvPicPr><p:cNvPr id="%d" name="%s"/><p:cNvPicPr/><p:nvPr/></p:nvPicPr>'
                . '<p:blipFill><a:blip r:embed="rId2"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
                . '<p:spPr>%s<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>%s</p:spPr></p:pic>',
                $id,
                $name,
                $transform,
                $fill,
            );
        }

        if (($shape['kind'] ?? null) === 'group') {
            return sprintf(
                '<p:grpSp><p:nvGrpSpPr><p:cNvPr id="%d" name="%s"/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
                . '<p:grpSpPr>%s</p:grpSpPr>'
                . '<p:sp><p:nvSpPr><p:cNvPr id="%d" name="%s"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
                . '<p:spPr/><p:txBody><a:bodyPr/><a:lstStyle/>%s</p:txBody></p:sp></p:grpSp>',
                $id,
                $name,
                $transform,
                $id + 1,
                $name,
                $paragraphs,
            );
        }

        return sprintf(
            '<p:sp><p:nvSpPr><p:cNvPr id="%d" name="%s"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr>%s<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>%s%s</p:spPr>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/>%s</p:txBody></p:sp>',
            $id,
            $name,
            $transform,
            $fill,
            $outline,
            $paragraphs,
        );
    }

    /**
     * The change made to a colour, in the form DrawingML writes it in.
     *
     * A change of a hundred per cent changes nothing, and a deck written by
     * PowerPoint says so on every colour it draws.
     */
    private static function change(?int $alpha): string
    {
        if ($alpha === null) {
            return '';
        }

        return sprintf('<a:alpha val="%d"/>', $alpha);
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
     * @param Options $options
     */
    private static function theme(array $scheme, array $fonts, array $options = []): string
    {
        $colours = '';
        $slots = $options['slots'] ?? self::SLOTS;
        $system = $options['sys'] ?? [];

        foreach ($slots as $slot) {
            if (in_array($slot, $system, true)) {
                // A system colour carries the colour it last was in beside it, which
                // is what DrawingML records it in.
                $colours .= sprintf('<a:%s><a:sysClr val="windowText" lastClr="%s"/></a:%s>', $slot, $scheme[$slot] ?? '000000', $slot);

                continue;
            }

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
