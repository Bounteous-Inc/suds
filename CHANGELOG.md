# Changelog

All notable changes to this project will be documented in this file.

## [1.1.0] - 2026-09-04

### Bug Fixes
- Exclude files from suds deploy artifact
- Supersede stale release PR when version bump changes between runs
- Dispatch drush commands in-context instead of via PATH
- Check for open PRs by state, not just branch name, before creating a release PR
- Commit artifact deploys on top of existing branch history instead of --force
- Register Drush's Symfony-compatibility autoloader for dev tooling
- Correct dependency floors to versions that actually work
- Make dependency declarations reflect what we actually promise
- Keep SUT env at step level, not job level
- Apply recipes via the dr CLI with a core/scripts/drupal fallback


### Documentation
- Add ADR 0005 on site UUID mismatch handling
- Document dependency floor verification


### Features
- Fail on site UUID mismatch, with opt-in reconciliation
- Add suds:deps-update command for routine maintenance updates


## [1.0.0] - 2026-08-20

### Bug Fixes
- Remove non-functional validator arg from DrushStyle::ask() in suds:init
- Remove hash salt generation from suds:init


