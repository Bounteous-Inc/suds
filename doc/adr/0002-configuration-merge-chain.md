# 2. Configuration merge chain

Date: 2026-04-10

## Status

Accepted

## Context

Drupal projects run in multiple environments — local development, CI, staging, production — each with configuration needs that differ from the others. A single committed `suds.yml` cannot address all cases without either encoding environment-specific branches in that file or requiring per-developer manual configuration outside the repository.

Three competing requirements drove the design:

1. **Shared defaults** — A base set of project configuration should be committed and identical for all team members and pipeline runs, eliminating "works on my machine" divergence.
2. **CI-specific values** — Deployment identity (git name/email) and environment-specific overrides should live in the repository and be applied automatically in CI without manual intervention.
3. **Local overrides** — Individual developers may need to point at a different sync source, use a local database URL, or suppress certain steps without committing those preferences.

A single file cannot satisfy all three requirements. Environment variables were considered for CI and local overrides but would require every configurable value to have an env var mapping, making configuration opaque and hard to document.

## Decision

Configuration is assembled from four layers in order, each overriding the previous:

| Layer | File | Committed | Condition |
|---|---|---|---|
| Built-in defaults | `config/suds.defaults.yml` (in the SUDS package) | — | Always |
| Project config | `suds.yml` | Yes | Always |
| CI overrides | `suds.ci.yml` | Yes | When `$CI` env var is set |
| Local overrides | `suds.local.yml` | No | Always (if present) |

`suds.local.yml` is added to `.gitignore` by convention. `suds.ci.yml` is committed and loaded automatically on any standard CI platform (GitHub Actions, Bitbucket Pipelines, GitLab CI all set `CI=true`).

**Merge semantics** follow two rules:

- **Associative (keyed) arrays merge recursively.** A partial override in `suds.yml` preserves all sibling keys from the layer below. Teams only write what differs from the defaults.
- **Lists (indexed arrays) replace entirely.** A list in any layer wholly replaces the list from the layer below. This is predictable and explicit — a list override is always the complete intended list.

For cases where a team wants to append to a built-in default list rather than replace it entirely, SUDS provides `*_extra` keys (e.g., `deploy.exclude_extra`) that are appended to the corresponding default list after the merge chain resolves.

## Consequences

- Teams write minimal configuration — only what differs from sensible defaults.
- `suds.ci.yml` can be audited in the repository alongside the rest of CI configuration.
- `suds.local.yml` must be added to `.gitignore` in consuming projects; `suds:init` does this automatically.
- The replace-entirely semantics for lists can surprise users who expect list overrides to append. The `*_extra` pattern mitigates this but requires awareness that it exists. `suds:config:dump` and `suds:config:dump --defaults` are the escape hatch for debugging unexpected resolved values.
- Adding a new config key requires a default in `config/suds.defaults.yml`; omitting it means the key is absent in projects that do not set it, which can cause unexpected `null` values in command logic.
