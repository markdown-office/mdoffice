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

PHP 8.2 or newer with `ext-dom`, `ext-gd`, `ext-mbstring` and `ext-zip`.

## Installation

```bash
git clone https://github.com/markdown-office/mdoffice.git
cd mdoffice
composer install
php bin/mdoffice word README.md
```

For a machine with no PHP at all, download a release from
[the releases page](https://github.com/markdown-office/mdoffice/releases) — each one
carries a single self-contained executable per platform.

### Why `markdown-word` is required as `*`

Composer warns that an unbound constraint should be avoided, and it is right in general.
Here neither library is published under its `markdown-office` name yet, so there is no
released version to bound against: `markdown-office/markdown-word` resolves to nothing
today, and the package that *is* published is `fabeat/markdown-word`.

For the same reason the two are resolved through `path` repositories in `composer.json`,
which is what makes a change in either library visible to the command line without a
reinstall. `tools/build-phar.php` rewrites those to `vcs` for the build, because a
relative path resolves differently once the manifest is staged. Both go away when the
libraries are registered under their own names — see the plan.

## Development

```bash
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