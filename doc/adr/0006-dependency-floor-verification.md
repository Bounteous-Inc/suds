# 6. Dependency floor verification

Date: 2026-09-03

## Status

Accepted

## Context

Nothing in CI exercised the *lower* bounds of the `^` constraints in `composer.json`. The lockfile and Dependabot both track newest versions, so a declared floor was only ever documentation. Investigating this ([#16](https://github.com/Bounteous-Inc/suds/issues/16)) found that three of them did not work at all.

The obvious fix — a `composer update --prefer-lowest --prefer-stable` job — does not work here. Composer minimises every package in the graph independently, which produces resolutions no consumer can actually install:

- `phpunit/phpunit ^11` requires `sebastian/diff ^6`, while `drupal/core` 10.4 requires `^4`. The two cannot coexist, so `--prefer-lowest` silently pushes `drupal/core` *up* to 11.2 and never tests the declared `^10.4` at all.
- With core at 11.2, `symfony/console ^7` is forced, but `--prefer-lowest` selects Drush's transitive `grasmash/yaml-cli 3.2.0`, whose `execute()` lacks the `: int` return type that symfony/console ≥ 7.3 requires. Fatal error before any test runs. 3.2.1 fixed it.
- PHPStan's amphp-based parallel worker pool hangs rather than analysing at floor `amphp/*` versions.

None of these are defects in SUDS, and fixing them would mean adding constraints on packages SUDS does not depend on — `sebastian/diff`, `grasmash/yaml-cli`, `amphp/parallel` — pinning transitive dependencies we have no relationship with in order to satisfy a resolution strategy no user employs.

What we actually want to know is narrower: *do the floors we publish work?* That is answered by pinning our own direct constraints to their declared minimum and letting Composer resolve the remainder normally.

Two real defects surfaced this way and are worth recording, because both were invisible to the lockfile:

1. **`drush/drush: ^13.0` was wrong.** Drush discovers commands via `ServiceManager::discoverPsr4Commands()`, which until 13.3.3 matched only files named `*DrushCommands.php`. Ours are `ConfigCommands.php`, `DbCommands.php`, and so on, so on Drush ≤ 13.3.2 **every SUDS command is invisible** — `drush list --filter=suds` reports that the category does not exist. [drush-ops/drush#6153](https://github.com/drush-ops/drush/pull/6153) relaxed the pattern to `*Commands.php` in 13.3.3. Note that `drush.services.yml` does not help: Drush 13's `ServiceManager` never reads `extra.drush.services`.

2. **`drupal/core-composer-scaffold` was used but never declared.** `composer.json` configures it and allows it as a plugin, but only received it transitively from `drupal/core` ≥ 11.4.5 (via `self.version`). At any lower resolution it vanished, `sut/` was never scaffolded, and `composer sut:si` failed with a misleading `assert($this->bootstrap instanceof DrupalBoot8)`.

The Drupal floor is a separate problem. Because Drupal 10.4 and PHPUnit 11 cannot share a vendor tree, the `^10.4` half of the `drupal/core-recommended` dev constraint was unreachable and untested. Decoupling `sut/` into its own Composer project was considered and rejected: the drush binary must autoload both Drupal *and* SUDS from a single autoloader, so a split forces SUDS to be path-installed into the SUT, which re-merges the trees. The coupling moves rather than disappears, while `composer analyze` breaks on a fresh clone (`mglaman/phpstan-drupal` throws without a discoverable Drupal root) and `symlink: true|false` becomes load-bearing.

SUDS has no Drupal PHP-API coupling at all — there is not one `use Drupal\...` in `src/`; it interacts with Drupal only by shelling out to drush. So the Drupal version can only affect us through the drush command surface, and the same drush 13.x runs against both majors, absorbing most of that. The realistic risk is confined to the handful of tests that exercise real site operations.

## Decision

Verify floors by pinning our own direct constraints, not by minimising the whole graph. The `Dependency floors` CI job runs:

```
composer require --no-update drush/drush:13.3.3 drupal/coder:8.3.28
composer update
```

then lint, static analysis, and the unit and integration suites. It runs the checks individually rather than via `composer check`, because grumphp's `composer` task fails on the deliberately-mutated `composer.json`.

Floors corrected to versions that actually work: `drush/drush: ^13.3.3`, `drupal/coder: ^8.3.28`, and `drupal/core-composer-scaffold` declared explicitly.

Drupal 10.4 coverage is bought with a CI job rather than a repo restructure. `Functional (Drupal 10.4)` builds a Drupal 10.4 site outside the repo with SUDS installed into it as a `path` repository (`symlink: false`), then points the existing functional suite at that site via two environment variables read by `tests/Support/FunctionalTestCase.php`:

- `SUDS_SUT_ROOT` — the Drupal root.
- `SUDS_DRUSH_BIN` — the drush binary, which must come from the same vendor tree as that root.

The dev constraint on `drupal/core-recommended` is narrowed to `^11.0`, describing what the local tree can hold rather than what SUDS supports. The supported range stays "Drupal 10.4 or 11" and is now verified by that job.

## Consequences

The floors we publish are checked on every PR, and a stale lower bound fails in CI instead of surfacing in a consumer's install. The two defects above are fixed, and `drush/drush: ^13.3.3` is now an honest floor: below it SUDS does not function at all.

`--prefer-lowest` remains unusable, so the floors job does not prove that *every* transitive dependency works at its minimum — only that our own declared minimums do. That is the property we publish, and the narrower claim is the accurate one.

The Drupal 10.4 job resolves its site fresh on each run rather than from a lockfile. This is deliberate — it is how an upstream Drupal patch release that breaks SUDS gets caught — but it means the job can go red without a change on our side. It is therefore kept out of the required status checks.

Because that job runs a subset of the functional suite (the classes that perform real site operations), Drupal 10.4 coverage is narrower than Drupal 11's. The remaining tests only need *a* bootstrappable Drupal for `suds:*` to register, which Drupal 11 already demonstrates.

One notable gap is unaddressed: recipes. `SetupCommands` shells out to `php <drupal-root>/core/scripts/drupal recipe`, the one genuinely Drupal-version-sensitive surface in SUDS, and it has no functional coverage on any version. Adding the 10.4 job does not change that; closing it needs a new test.

Functional tests now share `tests/Support/FunctionalTestCase.php` instead of repeating `getSutRoot()` in all eleven classes. That base class must keep `use DrushTestTrait` and its `getPathToDrush()` override together: `DrushTestTrait::drush()` calls `self::getPathToDrush()`, and inside a trait `self` binds to the composing class, so an override in a subclass is silently ignored and the tests would quietly run this project's drush instead of the SUT's.
