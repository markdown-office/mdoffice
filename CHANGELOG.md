# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **`mdoffice theme init <deck.pptx>` writes a theme from a deck.** A theme is a
  directory — `theme.yml`, `layouts.yml` and `template.pptx` — and making one by
  hand is most of the work of designing a deck again. The command reads a deck and
  writes all three: the colours, the fonts and the type scale out of the deck, the
  eight layout roles mapped onto the deck's own layouts, and the deck's layouts as
  the template. The directory goes beside the deck unless `-o` says otherwise.

  It reads the deck rather than asking about it, and it says what it read: the
  colours come from the deck's theme part where the deck is drawn in that theme and
  from its slides where it is not, the type scale is read off the deck's text
  because a deck records a size for every paragraph rather than a scale, and the
  layouts are mapped by the placeholders they hold and not by their names. A role
  the deck has no layout for is reported rather than pointed at a layout that is
  something else, and a theme that is already there is not overwritten without
  `--force`.

  A deck is bounded before any of it is read. A zip says how its contents are laid
  out and nothing about how much room they will take up, so a deck with more parts
  than a deck may hold, or with a part that is larger than a part may be once it is
  decompressed, is refused rather than read — the same decks, in the same words,
  that `mdoffice slides diff` refuses.

  The files it writes are written in the shape the library's own reader takes. A
  deck with no layouts has no role to map, and the layouts file says so by leaving
  the mapping out: an empty mapping written as a key with nothing under it is read
  as a role called `layouts` rather than as the mappings, and the theme would be one
  nobody could use.

  The three files are written into a directory of their own and moved into place
  only once the library's own reader has read them back. A theme the command cannot
  read is a failure here rather than a theme nobody can use — and the failure
  leaves nothing behind, so the next run is not told to reach for `--force` to get
  back to where it started.

- **`mdoffice slides diff` compares two decks.** It is the tool for the question every
  template workflow asks — *I changed the design, what did it break?* — and it is how a
  reproduction is checked without a pixel diff. The two decks are read as the packages
  they are and what changed between them is reported on standard error, one sentence
  per difference:

  > slide 1: the text "Volumen niedrig" was removed
  > slide 1: the shape "Body 2" moved from 10.58mm, 27.52mm to 10.58mm, 31.68mm
  > slide 2: the text "Ergebnis" changed colour from 00B4D8 to 087995

  What is compared is the text of every shape, where every shape is and how big it is,
  and the colour of every fill and every run. The slides of the two decks are paired by
  the id each one carries, which survives an edit and a reorder where a position does
  not. The archive's own bytes are never compared: a `.pptx` carries a time in every
  part it holds and another in the document's own properties, so two renderings of the
  same deck are two different files. The report says what it looked at as well as what
  it found, so a reader told "no differences" is not left wondering what was skipped.

  The exit code is the program's own: a run that compared two decks and found
  differences did what it was asked, and says so in its report. `--exit-code` makes a
  difference exit with 1, for a build that wants to stop on one.

### Changed

- **The install section says the package is on Packagist.** It is, and the
  Composer install is the first thing the section offers again. The sentence
  that said otherwise, and the test that asked for it, change together: the test
  was the question "is it published now?", and the answer is now yes.

### Fixed

- **A corrupt file said which part was broken and not which deck.** The message
  named the part of the deck and left the deck itself empty, so a reader was
  told *`docProps/core.xml`* and nothing about the file it was in. The deck's
  name is in the message now.

- **A colour written with a hash was read as a different colour.** The hash was
  taken off after the six characters were cut, so `#1A3A4A` was read as `1A3A4` —
  a different blue. A theme written with hashes in it — which is how a theme file
  spells a colour — got the wrong channels for every colour it named.

- **A theme reported a page colour the deck did not have.** The note said the
  page came from the deck's own theme part whatever happened, and named the slot
  by whether the page was dark rather than by the slot the colour map paints it
  with: a deck that paints its page with `dk2` was reported as `dk1`. A deck
  whose theme part does not declare that slot has no page of its own to report,
  and the library's own default is not the deck's.

- **A template was written without its content types.** The part is not reached
  through a relationship, so the template the theme command writes was a package
  no reader could open.

- **A theme is found beside the document that names it.** The `theme:` a
  document names in its own `---` block was resolved against the working
  directory rather than against the document's own: `mdoffice slides
  /tmp/proj/deck.md` from anywhere but the deck's own directory refused a theme
  that was sitting next to the deck, and named the themes that ship with the
  library instead. The document's directory is handed to the resolver now, so a
  build script that converts a document by its path finds the theme that travels
  with it. A document read from a pipe has no directory, and the working
  directory stands for it.

- **The released archive ran out of memory on a real deck.** PHP's own default
  is 128 MB, and a 28-slide deck with pictures in it needs more: the released
  executable died with *Allowed memory size of 134217728 bytes exhausted* rather
  than on the deck. The program raises the limit to 2 GB itself, and only
  upwards — a machine that gave it more keeps what it gave. Every way of
  running the program goes through the entry script, so the archive and the
  self-contained executable are covered by the same change.

- **The released archive asked for PHP 8.4 while this README promised 8.2.**
  Composer's platform check is written from the requirements of the packages the
  archive holds, and two of them have releases that ask for a newer PHP:
  `symfony/yaml` 8 for PHP 8.4 and `maennchen/zipstream-php` 3.2 for PHP 8.3.
  The manifest refuses those releases, so the archive's check asks for 8.2 —
  and the build refuses to write an archive that asks for more than the manifest
  promises, which is how the two drift apart no more.

## [0.1.1] - 2026-10-26

The first release that was published. The `v0.1.0` tag was cut and the release
was attempted, and it never happened: the pipeline stopped on the macOS build for
Intel, and a release waits for every binary, so nothing was published on any
platform. What this release adds is the fix for that and the corrections that
went with it.

The features are in the notes for 0.1.0, which is what the `v0.1.0` tag holds
and what a consumer of that tag would have got.

### Changed

- **The install section said the package is not on Packagist.** It was not, and
  the README's first instruction was `composer require`, which did not resolve.
  What worked was a clone or a release; the Composer install was kept for the day
  the package was published, with the plugin a consumer has to allow. The `0.1.0`
  notes below said the package was on Packagist, which was never true, and no
  longer say so.

- **The `path` repositories are not stripped by Packagist.** Composer reads
  `repositories` from the root package only, so an installed copy never sees the two
  `path` entries whatever Packagist does with them. The README gave a different
  reason, and the reason it gave was not the reason.

- **`markdown-presentation` reads a deck back**, and the README described it as
  writing one only.

### Fixed

- **The release pipeline signs the interpreter when upstream published it
  unsigned.** Upstream publishes an ad-hoc signature with the arm64 build and
  none at all with the Intel one, and the pipeline stopped on the Intel one —
  so nothing was published on any platform, because the release waits for every
  binary. The signature is put on here rather than refused for want of it: the
  same kind of signature the arm64 build already carries, and the one the
  kernel asks for.

- **The archive is stamped with the commit's date.** The step that read it
  named a directory that is not there, and a `git` error inside a command
  substitution is swallowed by the `echo` around it — the step succeeded, wrote
  nothing, and the archive fell back to the modification times of the sources.
  The date is now read from the repository the step runs in, and the build stops
  rather than writing an empty one.

## [0.1.0] - 2026-10-24

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

- **`mdoffice slides`, in both directions.** `to-pptx` and `to-markdown`, through
  [`markdown-presentation`][presentation]. A deck read back out is the Markdown this
  tool writes decks in, so it can be edited as text and put in again with a
  different theme — which is what taking a deck somebody else made apart and
  putting it into a new design comes to.

- **`mdoffice help` and `--version`.** The version line names this program and the
  two libraries it is holding, each read out of the install rather than declared in
  the source, so an archive and a `--version` beside it cannot disagree.

- **A PHAR**, and a self-contained executable per platform. `composer build:phar`
  assembles it, prunes the dependencies' own tests and documentation, and then runs
  the archive it wrote — a version check, a conversion in each direction, a check
  that the document kept its table, and a deck. An archive that fails any of those
  is deleted rather than left behind.

- **A static executable per platform, built by the release workflow.** A macOS on
  Apple silicon, a macOS on Intel, a Linux on x86-64, a Linux on ARM and a Windows on
  x64, each a single file with no PHP and no extensions in it, joined to the archive.
  The workflow builds the archive once and reuses it, so the archive inside every
  executable is byte-identical to the released one — the only reproducibility
  claim made, because a compiled static PHP is not reproducible. Each executable is
  then run on the runner that built it: a version check, a conversion in each
  direction, a deck, and the extensions the build ended up with. The macOS ones are
  ad-hoc signed, which an arm64 binary cannot run without, and the Windows one has
  no `ext-imagick`, which that platform cannot build.

- **The static executables are the interpreter upstream publishes.** Linux and macOS
  take the `micro` build upstream publishes for that platform, pinned by its
  checksum, and the archive is appended to it. Windows has no published build, so
  that one is compiled, by the tool that compiles interpreters, at a pinned commit.
  The build had compiled all three, from a download of that tool that rotates
  several times a day — so the checksum in the pipeline no longer matched the file
  it was meant to, and the build could not have passed.

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
  is `^0.2`. Both are published before this one is, so there is a released version to
  bound against, and an unbound constraint would let a future breaking release in
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

- **A released archive held whatever was on a branch when the build ran.** The build
  asked the two libraries for by `dev-main`, so two builds a day apart held different
  code, and `--version` said `dev-main` for a library rather than a version a reader
  could look up. The build now asks for the series the manifest names — `^0.2` for
  both — and takes the newest *release* in it, so an archive holds a version of each
  a reader can look up, and says so. A requirement naming no series is refused, and
  so is a resolve that came back as a branch.
  The consequence is an order: the libraries have to be released before this one is,
  because a release holds the releases that existed when it was built.

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
  and runs `composer update`, and a released archive holds the newest *release* of
  each library that existed when the build ran.

- Installing it needs nothing but the plugin: every dependency is a stable release,
  so the default stability holds. `markdown-presentation` reaches
  `phpoffice/phppresentation` through a patch rather than through a commit, because
  the APIs it uses are in no released version of that library yet. The README says
  so, and says what to put in a consumer's `composer.json`.

[0.1.1]: https://github.com/markdown-office/mdoffice/releases/tag/v0.1.1
[0.1.0]: https://github.com/markdown-office/mdoffice/releases/tag/v0.1.0
[presentation]: https://github.com/markdown-office/markdown-presentation
