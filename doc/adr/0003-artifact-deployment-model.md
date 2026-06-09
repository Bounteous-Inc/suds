# 3. Artifact-based deployment

Date: 2026-04-10

## Status

Accepted

## Context

Several Drupal hosting platforms (Acquia Cloud, Pantheon, Platform.sh) deploy from a git repository: the platform watches a branch and pulls from it when it updates. This creates a tension: the source repository contains build tooling, test suites, dev dependencies, and local configuration that production does not need and should not receive.

The alternatives considered were:

1. **Deploy directly from the source repository.** Simple, but means production receives the full source tree including `require-dev` packages, test fixtures, CI configuration, and local developer tooling. Some platforms do not support excluding paths from deployment, and the `vendor/` tree will include dev dependencies unless the platform runs its own `composer install --no-dev`.

2. **Use a platform-specific build hook.** Platforms like Acquia and Pantheon provide build hooks that can run `composer install --no-dev` on the server side. This couples the build process to the platform and means build steps (asset compilation, etc.) run on the server rather than in CI where failures are caught earlier.

3. **Build a clean artifact and push it to a separate repository.** CI constructs a clean directory via rsync (honouring an exclusion list), runs build steps inside it, commits the result, and force-pushes to a deployment repository branch. The hosting platform is configured to deploy from the artifact repository, not the source repository.

## Decision

`suds:deploy` implements the third approach: it assembles a clean artifact directory, runs configured build steps inside it, commits, and force-pushes to a separate deployment repository.

The workflow:

1. Run `deploy.hooks.pre_deploy` in the project root (CI machine)
2. Rsync the project into a fresh artifact directory, honouring `deploy.exclude` and `deploy.exclude_extra`
3. Run `deploy.build_steps` inside the artifact directory (default: `composer install --no-dev --optimize-autoloader`)
4. Optionally write a build manifest (`SUDS_BUILD.txt`) recording branch, commit hash, and timestamp
5. Commit and force-push to `deploy.repo.branch` on `deploy.repo.url`
6. Optionally push a tag on the artifact repository
7. Run `deploy.hooks.post_deploy` in the project root

Force-pushing to the artifact repository is intentional. The artifact repo's history is not meaningful — each deployment is a complete replacement. The authoritative history lives in the source repository.

## Consequences

- Consuming projects must configure and maintain a separate deployment repository. This is a one-time setup cost.
- The hosting platform must be pointed at the artifact repository, not the source repository.
- Build failures are surfaced in CI before anything reaches the server, rather than failing on the server during a platform build hook.
- The artifact is fully auditable via the build manifest without needing to cross-reference commit hashes between two repositories.
- The force-push pattern means the artifact repository cannot be used as a reliable audit trail; the source repository and its CI logs serve that purpose.
- Teams accustomed to pushing from source directly may need to adjust their mental model and hosting platform configuration.
