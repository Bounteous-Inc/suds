# Contributing

Thank you for your interest in contributing to SUDS!

## Prerequisites

- PHP 8.3+
- [Composer](https://getcomposer.org/)
- Drush 13.3.3+

## Setup

```bash
git clone git@github.com:Bounteous-Inc/suds.git
cd suds
composer install
```

`composer grumphp:init` installs git hooks that run linting, static analysis, and unit tests before each commit.

### Dependencies

`composer.lock` is committed, so `composer install` gives you the same versions CI uses. It has no effect on projects that depend on SUDS — Composer only reads the root package's lock file — but it keeps local and CI runs reproducible and makes every dependency bump a reviewable commit.

Resolution is pinned to `config.platform.php` 8.3, the minimum supported version, so the lock installs cleanly on every PHP version in the CI matrix. Take updates in their own commit — `composer update`, then commit the lock — rather than folding a lock change into an unrelated PR.

[Dependabot](.github/dependabot.yml) opens those update PRs weekly: runtime dependencies individually, dev tooling grouped. Each one carries a lock diff and runs the full CI matrix, so a breaking upstream release lands in its own PR instead of turning an unrelated build red.

Only `require` constraints are a promise to consumers. `drush/drush` is the one runtime dependency, and a `Dependency floors` CI job pins it to its declared minimum and runs lint, static analysis, and the unit and integration suites against that resolution. It deliberately does not use `composer update --prefer-lowest`, which minimises every transitive package independently and produces combinations no consumer can install — see [ADR 0006](doc/adr/0006-dependency-floor-verification.md).

`require-dev` is our own toolchain; nobody installs it, so its lower bounds carry no promise and are not tested. Those constraints track current and Dependabot keeps them moving, so bump them freely rather than treating them as floors.

The Drupal floor is declared as a `conflict` on `drupal/core` rather than a `require`, because SUDS is installed *into* a project that already has Drupal. That makes "Drupal 10.4 or 11" enforceable at install time, and the separate `Functional (Drupal 10.4)` job described below exercises it for real.

## Running checks

```bash
composer lint          # PHPCS (Drupal coding standards)
composer lint:fix      # Auto-fix PHPCS violations
composer analyze       # PHPStan static analysis
composer test:unit     # Unit tests (no Drupal required)
composer validate:all  # All of the above
```

### Functional tests

Functional tests shell out to a live Drupal installation. Provision it first:

```bash
composer sut:si
composer test:functional
```

The suite defaults to the repo's own `sut/` directory and the drush binary in this project's `vendor/`. Both can be redirected to a Drupal site built elsewhere:

```bash
SUDS_SUT_ROOT=/path/to/drupal/web \
SUDS_DRUSH_BIN=/path/to/drupal/vendor/bin/drush \
  composer test:functional
```

The two must come from the same vendor tree, because drush has to autoload both Drupal and SUDS's command classes. CI uses this to test against a Drupal version that cannot coexist with our dev dependencies.

## Project standards

This project enforces Drupal coding standards via PHP_CodeSniffer (`Drupal` + `DrupalPractice` sniffs), PHPStan level 8 static analysis, and Conventional Commits. The GrumPHP pre-commit hooks run all checks automatically before each commit.

## Commit messages

This project uses [Conventional Commits](https://www.conventionalcommits.org/). Examples:

```
feat: add database sanitization to suds:sync
fix: correct config import order in suds:update
docs: update contributing guide
chore: bump phpstan to 2.x
```

The GrumPHP hook will reject commits that do not follow this format.

## Submitting a pull request

1. Create a branch from `main`.
2. Make your changes with tests.
3. Ensure `composer validate:all` passes.
4. Open a pull request against `main` with a clear description of the change and why it is needed.

## Releasing

Releases are automated. Maintainers do not run any local release commands.

### How it works

Every successful CI run on `main` triggers the [Release workflow](.github/workflows/release.yml) via GitHub Actions:

- **Any regular merge** — the workflow computes the next version using [git-cliff](https://git-cliff.org/) (based on Conventional Commits since the last tag), updates `CHANGELOG.md` and the `version` field in `composer.json`, then opens a `release/X.Y.Z` pull request. If that PR already exists it is force-pushed with refreshed content. If no version-worthy commits landed since the last tag, no release PR is opened.
- **Merging a `release/X.Y.Z` PR** — the workflow detects the release commit (via the GitHub API, not the commit message) and pushes the `vX.Y.Z` tag. No CHANGELOG or version changes are made.

Version bumping follows Conventional Commits: `feat` bumps minor, `fix` bumps patch, a breaking change (`!` or `BREAKING CHANGE` footer) bumps major.

### Maintainer checklist

When a release PR appears:

1. Review `CHANGELOG.md` — confirm the version and entries look correct.
2. Merge the PR. The workflow tags automatically; no manual steps needed.

### Prerequisites

None — the release workflow uses the built-in `GITHUB_TOKEN`, no additional secrets required.
