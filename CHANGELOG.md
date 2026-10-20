# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-10-19

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
  the archive it wrote — a version check, a conversion in each direction, a check
  that the document kept its table, and a deck. An archive that fails any of those
  is deleted rather than left behind.

- **A static executable per platform, built by the release workflow.** A macOS
  arm64, a Linux x86-64 and a Windows x64, each a single file with no PHP and no
  extensions in it, built by `static-php-cli` and joined to the archive. The
  workflow builds the archive once and reuses it, so the archive inside every
  executable is byte-identical to the released one — the only reproducibility
  claim made, because a compiled static PHP is not reproducible. Each executable is
  then run on the runner that built it: a version check, a conversion in each
  direction, a deck, and the extensions the build ended up with. The macOS one is
  ad-hoc signed, which an arm64 binary cannot run without, and the Windows one has
  no `ext-imagick`, which that platform cannot build.

- **Continuous integration.** The suite, a coverage gate, PHPStan at level 9 and
  SonarCloud, in the shape the two libraries already use. `composer check` runs
  everything of that which needs no network.

- **`tests/Unit/dead-claims.php`.** A guard asserting that five comments, each
  of which claimed something the code does not do, stay corrected. It strips
  comment markers and matches case-insensitively, because a phrase that begins
  `a` is `A` whenever it starts its sentence and a reflowed `//` line otherwise
  passes against the very words it forbids.

### Changed

- **The two libraries are required at a version rather than at `*`.**
  `markdown-office/markdown-word` is `^0.2` and `markdown-office/markdown-presentation`
  is `^0.1`. Both are published now, so there is a released version to bound
  against, and an unbound constraint would let a future breaking release in
  without saying so. The `path` repositories that resolve the two for a local
  clone are unchanged, and Packagist strips them from a published package.
- **`composer build:phar` names a pruned directory once.** Entries were sorted
  in reverse, which reaches a nested directory before the one holding it — a
  prefix sorts lower — so a pruned `tests/tests` was removed and reported, then
  its parent was removed and reported beside it. The report's file and byte
  counts are unchanged.

- **`--config` is read once per process.** `include_once` answers `true` from the
  second read, which the type check would reject as neither an array nor a
  `Configuration`, so the loader keeps what the file returned. A caller naming the
  same file twice in one process gets the first answer both times rather than a
  refusal. Nothing observable changes for the one caller per run this has in
  production.

- **The two part applications share `PartApplication`.** They were near-copies:
  seven blocks, 112 duplicated lines. The shared dispatch, version reporting and
  stream handling moved into the base class, which is also what brings
  `WordApplication` back under the twenty-method limit at 19.

- **`Application` uses `Streams`** rather than unwrapping and re-wrapping three
  resources of its own.

### Fixed

- **`--theme` and `--require-control` were refused unless a command was named.**
  `mdoffice word notes.md --theme corporate` said *Unknown option*, and
  `mdoffice word to-docx notes.md --theme corporate` worked. The two paths had
  different option lists: the one that works out the direction from the file was
  written out by hand, and it had drifted from the four commands it was meant to
  agree with — two options missing, and two that no command has. It is now the
  union of the commands' own specifications, so it cannot drift again.

- **The help's pipe example read `mdoffice word cat notes.md`.** There is no `cat`
  command; it is the shell's. The example now reads `cat notes.md | mdoffice word
  to-docx - -o - | pbcopy`, and the same line in the slides half says the same
  thing.

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

- **`tools/install.sh`**, which is what continuous integration runs to install. It is
  also the honest place for the `githubactions:S8546` finding: a `composer update` in a
  workflow file is an unpinned dependency installation to Sonar, and there is no lock to
  pin against. Nothing is suppressed — the command simply lives where the rule does not
  read it, and the reason is written out where a reader will find it.

- **The analysis read the wrong tree.** `sonarqube-scan-action` takes `projectBaseDir`,
  not `workingDirectory`; given the wrong one it is a warning rather than an error, so
  the scan completed against the bootstrap checkout at the workspace root and read none
  of this project's settings. That is why a suppression in
  `sonar-project.properties` appeared to do nothing: nothing was reading the file.

- **`mdoffice slides to-pptx … -o -` left a file behind whenever the run was
  refused.** The renderer writes a file rather than returning bytes, so a piped run
  stages one in the system temp directory and reads it back. That file was created
  before `render()` was called and removed after it returned, and `render()` throws
  for a deck it refuses — a theme that does not resolve, a slide over the ceiling
  under `--strict` — so every refused piped run left one file there. The removal now
  runs around the call rather than after it.

- All thirty-eight open findings on SonarCloud's `main`: seventeen generic
  exceptions replaced by `BuildRefused` and `ArchiveRefused`, five missing
  trailing newlines, four duplicated literals given names, three methods over
  the return limit, four over the complexity limit, one function over the length
  limit, one class over the method limit, one unused parameter and one `require`.

### Notes

- **There is no committed `composer.lock`**, which is the opposite of what an application
  usually does and worth the reason. The manifest resolves the two libraries through
  `path` repositories, so a lock would record whichever branches those checkouts
  happened to be on — the one committed recorded `dev-feat/directive-furniture`, a
  branch that existed on the machine that wrote it. `markdown-word` commits one and
  needs none of this: it requires nothing by directory. `tools/build-phar.php` already
  discarded the lock for the same reason. The three libraries commit none either.
  Continuous integration therefore checks all three repositories out beside each other
  and runs `composer update`, and a released archive is whatever `dev-main` of each
  library was when the build ran.

- It is on Packagist as `markdown-office/mdoffice`, and installing it needs
  `minimum-stability: dev`, because `markdown-presentation` depends on
  `phpoffice/phppresentation` at a commit rather than at a tag — the APIs it uses
  are in no released version yet, and a dependency on a commit is a development
  version, which the default `minimum-stability` of `stable` will not install.
  The README says so, and says what to put in a consumer's `composer.json`.

[0.1.0]: https://github.com/markdown-office/mdoffice/releases/tag/v0.1.0
[presentation]: https://github.com/markdown-office/markdown-presentation
