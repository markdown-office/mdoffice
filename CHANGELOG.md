# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-10-17

The first release.

The first release. `mdoffice` is the command line for the suite and nothing else:
it decides which file is read, which converter runs and what exit status is
returned, and it calls the libraries rather than reimplementing any of them.

### Added

- **`mdoffice word`, in both directions.** `to-docx`, `to-odt`, `to-rtf` and
  `to-markdown`, each of which accepts the options the library behind it accepts.
  Naming the command is optional: a file's own bytes say which way it has to go,
  because a `.docx` is a zip archive and Markdown is text. `--to` overrides that,
  which is what makes reading from standard input work at all — a pipe has no name
  to go on.
- **`mdoffice slides`.** Markdown to `.pptx`, through
  [`markdown-presentation`][presentation].
- **`mdoffice help` and `--version`.** The version line names this program and the
  two libraries it is holding, each read out of the install rather than declared in
  the source, so an archive and a `--version` beside it cannot disagree.
- **A PHAR**, and a self-contained executable per platform. `composer build:phar`
  assembles it, prunes the dependencies' own tests and documentation, and then runs
  the archive it wrote — a version check, a conversion in each direction and a check
  that the document kept its table. An archive that fails any of those is deleted
  rather than left behind.
- **Continuous integration.** The suite, a coverage gate, PHPStan at level 9 and
  SonarCloud, in the shape the two libraries already use. `composer check` runs
  everything of that which needs no network.

### Fixed

- **`mdoffice word --version` and `mdoffice --version` disagreed.** The part answered
  with a version typed into its source and the dispatcher read the same library out of
  the install, so a checkout answered `0.1.1` and `dev-feat/…` for one package. A bug
  report quotes the part's own answer. Both now read `InstalledVersions`, which is what
  the docblock claimed the constant was for.
- **A `use` statement named a class that does not exist.**
  `MarkdownOffice\Console\Word\Command\Command` is at `Console\Command\Command`; every
  `class-string<Command>` annotation in the Word half was therefore not a type at all.
- **The committed lock named the presentation library under its old package name**, so
  `mdoffice --version` reported `not installed` for a library whose classes loaded
  perfectly. Nothing said the two had to agree, and 157 tests stayed green through it.
  The lock is gone altogether — see the note below — and a test now asserts that every
  package the manifest requires is one the install can account for.
- **`ext-xmlwriter` was missing from the requirements in the README.** It is required,
  by `markdown-word`'s numbering pass.
- **Continuous integration could not install anything.** Every job ran `composer install`
  against a manifest that resolves both libraries by directory, so on a runner with no
  siblings it failed in about ten seconds with `Source path "../markdown-presentation"
  is not found`. The jobs now check all three repositories out and resolve afresh.

### Notes

- **There is no committed `composer.lock`**, which is the opposite of what an application
  usually does and worth the reason. The manifest resolves the two libraries through
  `path` repositories, so a lock would record whichever branches those checkouts
  happened to be on — the one committed recorded `dev-feat/directive-furniture`, a
  branch that existed on the machine that wrote it. `tools/build-phar.php` already
  discarded the lock for the same reason. The three libraries commit none either.
  Continuous integration therefore checks all three repositories out beside each other
  and runs `composer update`, and a released archive is whatever `dev-main` of each
  library was when the build ran.
- It is not on Packagist, so installation is a clone of this repository **with the
  two libraries beside it** — the manifest resolves them through `path`
  repositories, which is what makes a change in either library visible to the
  command line without a reinstall. Both require as `*` for the same reason: neither
  is published under its `markdown-office` name yet.

[presentation]: https://github.com/markdown-office/markdown-presentation
