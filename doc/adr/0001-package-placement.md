# 1. Package placement in require vs require-dev

Date: 2026-04-10

## Status

Accepted

## Context

Composer projects conventionally place developer tools in `require-dev` so that `composer install --no-dev` produces a lean production vendor tree. SUDS looks like a developer tool — it provides CLI commands and is only consumed at the project level, never shipped as a library dependency.

However, two SUDS commands run outside local development:

- `suds:update` runs on production servers after every deployment. It rebuilds caches, runs database updates, and imports configuration. Many hosting workflows invoke it via a Drush remote alias from CI: `drush @prod suds:update`, which SSHes to the server and executes the command there.
- `suds:deploy` runs in CI pipelines and produces an artifact by running `composer install --no-dev --optimize-autoloader` inside the artifact directory. If SUDS itself is a `require-dev` dependency, it is excluded from that step — but the artifact build step must complete before SUDS is no longer needed.

The second issue is subtler: the artifact is assembled from the source project, then `composer install --no-dev` is run inside the artifact directory. SUDS is present in the source project's vendor tree and orchestrates this process, so it does not need to survive into the artifact. The first issue is the binding constraint.

## Decision

SUDS is declared in `require`, not `require-dev`, in consuming projects.

## Consequences

- SUDS and its runtime dependency (Drush) are present in every environment, including production server vendor trees.
- `composer install --no-dev` in the artifact does not break anything SUDS depends on for the deployment itself.
- Teams accustomed to placing CLI tools in `require-dev` may find this counterintuitive. The distinction should be documented prominently — as it is in the README — to prevent well-intentioned moves to `require-dev` that break server-side execution.
- SUDS intentionally has a minimal runtime dependency footprint (only Drush, which consuming projects already require) to keep this overhead negligible.
