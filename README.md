# mdoffice

The command line for [markdown-office](https://github.com/markdown-office): Markdown to
Word and to PowerPoint, in one program.

```bash
mdoffice word notes.md              # → notes.docx
mdoffice word report.docx           # → report.md
mdoffice word notes.md --to odt     # → notes.odt
mdoffice slides deck.md             # → deck.pptx
mdoffice help
```

The converters themselves are separate libraries and are meant to be used on their own:

- [`markdown-office/markdown-word`](https://github.com/markdown-office/markdown-word) —
  Markdown to `.docx`, `.odt` and `.rtf`, and `.docx` back to Markdown
- [`markdown-office/markdown-presentation`](https://github.com/markdown-office/markdown-presentation) —
  Markdown to `.pptx`
- [`markdown-office/commonmark-generic-directives`](https://github.com/markdown-office/commonmark-generic-directives) —
  the directive syntax, for your own parser

This repository holds the command line, the PHAR and the build pipeline. It has no
public API for converting anything: a document is made by running the program, not by
calling a class. That is the line between the suite's libraries and this tool, and the
documentation sets it out in full under **The suite**.

## Parts

The command name says which converter a run is for:

| Part | Does |
| --- | --- |
| `mdoffice word …` | `.docx`, `.odt`, `.rtf`, and `.docx` back to Markdown |
| `mdoffice slides …` | `.pptx` |

A bare file is not a run. `mdoffice notes.md` is refused and the parts are listed: with
more than one converter here, a file name alone cannot say which way the data goes, and
an error that guessed would be right half the time and wrong the other half.

## Requirements

PHP 8.2 or newer with `ext-dom`, `ext-gd`, `ext-mbstring`, `ext-xmlwriter` and `ext-zip`.

`ext-xmlwriter` is here because `markdown-word` uses it to merge numbering definitions,
not because anything in this repository names it.

## Installation

```bash
composer require markdown-office/mdoffice
```

That gives the command as `vendor/bin/mdoffice`.

**`minimum-stability` has to allow a development version.** `markdown-presentation`
depends on `phpoffice/phppresentation` at a commit rather than at a tag, because the
APIs this program uses — `Table::setFirstRow()`, `AutoShape::setRoundRectCorner()`
among them — are in no released version yet. A dependency on a commit is a
development version, and Composer will not install one under the default
`minimum-stability` of `stable`. So the project has to say so:

```json
{
    "require": {
        "markdown-office/mdoffice": "^0.1"
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

`prefer-stable` keeps every other dependency on a stable release; only the one
that has to be a commit is not. The constraint on `phpoffice/phppresentation` is
the only reason this is asked of a consumer.

### From a clone instead

All three repositories, side by side:

```bash
git clone https://github.com/markdown-office/mdoffice.git
git clone https://github.com/markdown-office/markdown-word.git
git clone https://github.com/markdown-office/markdown-presentation.git
cd mdoffice
composer install
php bin/mdoffice word README.md
```

The siblings are not optional. The manifest resolves both libraries through `path`
repositories, which point at `../markdown-word` and `../markdown-presentation`, so a
clone of this repository on its own has nothing for Composer to resolve. The `path`
entries are for development and are not published — Packagist strips them — so an
installed copy resolves the two libraries from Packagist instead.

For a machine with no PHP at all, download a release from
[the releases page](https://github.com/markdown-office/mdoffice/releases) — each one
carries a single self-contained executable per platform, and none of that applies.

### Why the libraries are resolved through `path` repositories

`composer.json` declares both libraries as `path` repositories, which point at
`../markdown-word` and `../markdown-presentation`. That is what makes a change in
either library visible to the command line without a reinstall, and it is why a
clone needs its siblings beside it. Packagist strips the entries from a published
package, so an installed copy resolves the two libraries from Packagist instead and
never sees them.

`tools/build-phar.php` rewrites them to `vcs` for the build, because a relative
path resolves differently once the manifest is staged.

A clone needs its siblings beside it, because that is what the `path` entries point at.
The same is why there is no committed `composer.lock`: it would record whichever
branches those two checkouts happened to be on, rather than anything this repository
chose. The three libraries commit none either, and `tools/build-phar.php` stages the
manifest with the two rewritten as `vcs` for the same reason — a relative path resolves
differently once the manifest has been staged, and a lock built against `path`
repositories points at a directory that is not there.

So a released archive holds whatever `dev-main` of each library was at the moment the
build ran. Pinning that needs a commit to pin, and there is nowhere to record one that
a `path` repository will not overwrite.

## Development

```bash
tools/install.sh     # what continuous integration runs; --no-dev for a runtime-only tree
composer test        # the suite
composer check       # validate, the binary's own version, and the suite
composer build:phar  # build/mdoffice.phar
```

The build prints what it did, prunes the dependencies' own tests and documentation, and
then runs the archive it wrote — a version check, a conversion in each direction, and a
check that the document kept its table. An archive that fails any of those is deleted
rather than left behind.

## Licence

MIT. See [LICENSE](LICENSE).