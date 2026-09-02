# Changelog

All notable changes to this project will be documented in this file.

## [2.0.0] - 2026-09-02

### Bug Fixes
- Exclude files from suds deploy artifact
- Supersede stale release PR when version bump changes between runs
- Dispatch drush commands in-context instead of via PATH


### Documentation
- Add ADR 0005 on site UUID mismatch handling


### Features
- Fail on site UUID mismatch, with opt-in reconciliation
- Add suds:deps-update command for routine maintenance updates


## [1.0.0] - 2026-08-20

### Bug Fixes
- Remove non-functional validator arg from DrushStyle::ask() in suds:init
- Remove hash salt generation from suds:init


