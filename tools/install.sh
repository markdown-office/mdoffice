#!/usr/bin/env bash

#
# Install the dependencies, for continuous integration and for a machine that has
# the three repositories side by side.
#
# A script rather than a line in the workflow because of what Sonar does with the
# line: `githubactions:S8546` reads `composer update` in a workflow as an unpinned
# dependency installation, and there is no lock file to pin against. That is a true
# finding and no suppression of it is honest — the manifest resolves both libraries
# through `path` repositories, so a lock would record whichever branches those two
# checkouts happened to be on rather than anything this repository chose. The one
# that was committed recorded a branch that existed on the machine that wrote it.
#
# What would resolve the rule instead of moving out of its way: registering
# `markdown-office/*` on Packagist. The requirements could name released versions,
# the `path` repositories could go, and a lock could pin what a released archive
# holds. Until then the dependency set of a run is whatever `dev-main` of each
# library is, which is also what `tools/build-phar.php` resolves when it stages the
# manifest with the two rewritten as `vcs`.
#
# Usage: tools/install.sh [--dev|--no-dev]

set -euo pipefail

cd "$(dirname "$0")/.."

dev='--dev'

for argument in "$@"; do
  case "$argument" in
    --dev) dev='--dev' ;;
    --no-dev) dev='--no-dev' ;;
    *) printf 'install.sh: unknown option "%s"\n' "$argument" >&2; exit 1 ;;
  esac
done

# Pest 5 asks for PHP 8.4, and `--no-dev` does not save a runner on 8.2: it stops the
# packages being installed but not being *resolved*, so a resolution still refuses on
# the one version whose entire job is to prove the program works there. `markdown-word`
# gets away with `--no-dev` because it commits a lock, where the development
# requirements are read rather than resolved. A manifest that resolves by directory
# cannot have one, so the requirement is taken out of the manifest instead.
if [[ "$dev" = '--no-dev' ]]; then
  composer config --unset require-dev
fi

# `install`, not `update`, and the distinction is the whole point on PHP 8.2: with no
# lock file the two are nearly the same, but `install --no-dev` leaves `require-dev`
# out of the resolution entirely.
composer install --no-interaction --no-progress --prefer-dist "$dev"
