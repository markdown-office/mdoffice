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
  Markdown to `.pptx`, and a deck back to Markdown
- [`markdown-office/commonmark-generic-directives`](https://github.com/markdown-office/commonmark-generic-directives) —
  the directive syntax, for your own parser

This repository holds the command line, the PHAR and the build pipeline. It has no
public API for converting anything: a document is made by running the program, not by
calling a class. That is the line between the suite's libraries and this tool, and the
documentation sets it out in a chapter of its own — [the command
line](https://markdown-office.github.io/command-line/basic/) — with the options of each
half on a page of their own.

## Parts

The command name says which converter a run is for:

| Part | Does |
| --- | --- |
| `mdoffice word …` | `.docx`, `.odt`, `.rtf`, and `.docx` back to Markdown |
| `mdoffice slides …` | `.pptx`, and `.pptx` back to Markdown |

A bare file is not a run. `mdoffice notes.md` is refused and the parts are listed: with
more than one converter here, a file name alone cannot say which way the data goes, and
an error that guessed would be right half the time and wrong the other half.

### Comparing two decks

`mdoffice slides diff` reads two decks and says what changed between them. It is the
tool for the question every template workflow asks — *I changed the design, what did it
break?* — and it is how a reproduction is checked without a pixel diff.

```bash
mdoffice slides diff before.pptx after.pptx
```

```text
mdoffice slides: diff of before.pptx against after.pptx

slide 1: the text "Volumen niedrig" was removed
slide 1: the shape "Body 2" moved from 10.58mm, 27.52mm to 10.58mm, 31.68mm
slide 2: the text "Ergebnis" changed colour from 00B4D8 to 087995
```

What is compared is the text of every shape, where every shape is and how big it is,
and the colour of every fill and every run. Positions and sizes are in millimetres,
because the EMU a package holds are a hundredth of a millimetre divided by 360 and
nobody reads them.

The two decks are paired slide by slide by the id each slide carries, which survives an
edit and a reorder where a position does not: a slide inserted at the front moves every
slide after it, and a diff that paired them by their place would report the whole deck
as changed.

The archive's own bytes are never compared. A `.pptx` carries a time in every part it
holds and another in the document's own properties, so two renderings of the same deck
are two different files and a byte comparison would say that every deck ever written had
changed.

The report goes to standard error and says what it looked at as well as what it found:
a reader told "no differences" by a tool that looked at half the deck is worse off than
one told what was looked at.

The exit code is the program's own. A run that compared two decks and found differences
did what it was asked, and says so in its report; `--exit-code` makes a difference exit
with 1, for a build that wants to stop on one.

## Requirements

PHP 8.2 or newer with `ext-dom`, `ext-gd`, `ext-mbstring`, `ext-xmlwriter` and `ext-zip`.

`ext-xmlwriter` is here because `markdown-word` uses it to merge numbering definitions,
not because anything in this repository names it.

A deck with pictures in it needs more memory than PHP's own default of 128 MB allows, so
the program raises its own limit to 2 GB. A machine that gave it more keeps what it gave.

## Installation

```bash
composer require markdown-office/mdoffice
```

That gives the command as `vendor/bin/mdoffice`.

**The project has to allow a plugin.** `markdown-presentation` writes a few things a
released PHPPresentation cannot — `Table::setFirstRow()`, `AutoShape::setRoundRectCorner()`
among them — and gets them from a patch applied to the installed package. The patch is
applied by [`cweagans/composer-patches`][composer-patches], which Composer only runs when
the project allows it:

```json
{
    "require": {
        "markdown-office/mdoffice": "^0.1"
    },
    "config": {
        "allow-plugins": {
            "cweagans/composer-patches": true
        }
    }
}
```

Without that line the install stops with Composer's own message naming the plugin. Nothing
else has to be relaxed: every dependency is a stable release, so the default stability
holds.

The plugin writes a `patches.lock.json` into the project that installs this one. It
records the patches applied and is worth committing, so that a change to one of them is
seen rather than absorbed.

### From a clone

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
entries are for development, and an installed copy never sees them: Composer reads
`repositories` from the root package only, so the two libraries are resolved from
Packagist instead.

### From a release

For a machine with no PHP at all, download a release from
[the releases page](https://github.com/markdown-office/mdoffice/releases). Each one
carries a self-contained executable per platform — no PHP, no extensions, nothing to
install — and the archive the executables are built around, for a machine that has
PHP and would rather not download a second copy of it:

| | |
| --- | --- |
| `mdoffice-<version>-macos-arm64` | macOS on Apple silicon |
| `mdoffice-<version>-macos-x86_64` | macOS on Intel |
| `mdoffice-<version>-linux-x86_64` | Linux on x86-64 |
| `mdoffice-<version>-linux-aarch64` | Linux on ARM |
| `mdoffice-<version>-windows-x64.exe` | Windows on x64 |
| `mdoffice-<version>.phar` | the archive on its own, for a machine with PHP |
| `SHA256SUMS` | the checksum of each of the above |

The Linux and macOS executables are the interpreter upstream publishes for that
platform, with the archive appended to it. The Windows one is compiled here, because
upstream publishes no Windows build of it.

Three things differ between them, and all three are deliberate.

**The macOS executables are signed with an ad-hoc signature, and that is not optional.**
A Mac with Apple silicon does not run native arm64 code without a signature, and an
unsigned one is killed by the kernel before Gatekeeper is ever asked. The signature is
ad-hoc, so it is not a developer certificate and not notarised: it satisfies the
kernel and nothing more. It is a signature on the interpreter, which the archive is
appended to rather than signed over: `codesign` will not sign a file with a payload
appended to it, so the executable keeps the signature it was given. Upstream signs the
Apple silicon build and does not sign the Intel one, so the pipeline writes the
signature the Intel build is missing.

**The macOS executables are the largest on disk.** The published macOS interpreter is
three times the size of the published Linux one. The Windows executable is compiled
here rather than packed: PHP's own Windows build links with `/GUARD:CF`, and UPX
refuses to pack a PE file that has Control Flow Guard enabled. The downloads are much
closer together than the files are, because the Linux one barely compresses.

**The Windows executable has no SVG support.** `ext-imagick` cannot be built into a
static PHP on Windows — ImageMagick's own Windows build needs a C++ compiler that
the static toolchain does not provide — so the extension is not there. A deck or a
document with an SVG in it says so, with what to do about it, rather than failing
quietly. The Linux and macOS executables have it.

**A download in a browser asks a question on a Mac that a download in a terminal does
not.** The quarantine flag is set by the application that fetched the file rather than
by where it came from, so a file saved by a browser is quarantined and Gatekeeper
asks — the dialog that says *Apple could not check whether "mdoffice" is free of
malware that could harm your Mac or violate your privacy*. It asks because the
signature is an ad-hoc one and the binary is not notarised, which is what makes
Apple's check impossible rather than merely unconfirmed. A file fetched with `curl`
is not flagged and is not asked about.

The flag comes off like this, and the program runs:

```bash
xattr -d com.apple.quarantine mdoffice
```

Or fetch it with `curl` in the first place, and there is no dialog:

```bash
curl -L -o mdoffice https://github.com/markdown-office/mdoffice/releases/latest/download/mdoffice-0.1.1-macos-arm64
```

### Why the libraries are resolved through `path` repositories

`composer.json` declares both libraries as `path` repositories, which point at
`../markdown-word` and `../markdown-presentation`. That is what makes a change in
either library visible to the command line without a reinstall, and it is why a
clone needs its siblings beside it. An installed copy never sees them: Composer
reads `repositories` from the root package only, so the two libraries are resolved
from Packagist instead.

Each `path` repository also declares the version its sibling is taken to be — `0.2.x-dev`
for both, against the `^0.2` the manifest requires. The number is the branch-alias
form of the line the sibling is on, so a stable-preferring resolve takes the
sibling's code rather than the tag, and it satisfies the constraint, which `dev-main`
would not. Without it the resolve fails outright: a `path` repository is
canonical, so the branch it offers is the only one that counts, and `dev-main` matches
no constraint.

`tools/build-phar.php` rewrites them to `vcs` for the build, because a relative
path resolves differently once the manifest is staged.

A clone needs its siblings beside it, because that is what the `path` entries point at.
The same is why there is no committed `composer.lock`: it would record whichever
branches those two checkouts happened to be on, rather than anything this repository
chose. The three libraries commit none either, and `tools/build-phar.php` stages the
manifest with the two rewritten as `vcs` for the same reason — a relative path resolves
differently once the manifest has been staged, and a lock built against `path`
repositories points at a directory that is not there.

So a released archive holds a **published release** of each library: the build asks
for the series the manifest names — `^0.2` for both — and takes the newest release
in it, so `--version` names a version a reader can look up rather than a branch that
moves. The build refuses a requirement that names no series, and refuses an archive
whose libraries came back as a branch.

Which means the libraries have to be released before this one is: a `v0.1.0` of
`mdoffice` holds the `v0.2.0` of `markdown-word` and the `v0.2.0` of
`markdown-presentation` that existed when the build ran, and no later.

## Development

```bash
tools/install.sh     # what continuous integration runs; --no-dev for a runtime-only tree
composer test        # the suite
composer check       # validate, the binary's own version, and the suite
composer build:phar  # build/mdoffice.phar
```

The build prints what it did, prunes the dependencies' own tests and documentation, and
then runs the archive it wrote — a version check, a conversion in each direction, a check
that the document kept its table, and a deck. An archive that fails any of those is
deleted rather than left behind.

## Licence

MIT. See [LICENSE](LICENSE).

[composer-patches]: https://github.com/cweagans/composer-patches