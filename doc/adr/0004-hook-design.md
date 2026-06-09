# 4. Hook design: shell command lists

Date: 2026-04-10

## Status

Accepted

## Context

SUDS orchestrates multi-step workflows (sync, deploy, update, setup). Each workflow has points where project-specific behaviour needs to run: toggling maintenance mode before an update, compiling assets before a deploy, seeding local configuration after a sync. SUDS cannot know what any given project needs at those points.

Three approaches were considered for exposing extension points:

1. **PHP callbacks.** A consuming project registers a callable that SUDS invokes at the appropriate point. Requires SUDS to define a PHP API surface for hooks, requires the project to write PHP (and bootstrap something that can autoload it), and makes the hook inventory invisible from `suds.yml`.

2. **Drupal hooks.** A custom module in the consuming project implements a Drupal hook (e.g., `hook_suds_pre_sync()`). Requires SUDS to declare hookable events in Drupal's hook system. Ties hook execution to a full Drupal bootstrap, which is not always available (e.g., early in a `suds:deploy` run before the artifact is assembled).

3. **Shell command lists in `suds.yml`.** Pre and post hooks for each command are lists of shell command strings. SUDS executes them in order using the system shell, failing the workflow on any non-zero exit.

## Decision

Hooks are lists of shell command strings declared in `suds.yml` (or any layer of the configuration merge chain).

```yaml
sync:
  hooks:
    pre_sync:
      - composer install
    post_sync:
      - cp .env.example .env.local
      - drush config:set system.site name "Local Dev" --yes

deploy:
  hooks:
    post_deploy:
      - curl -X POST https://hooks.example.com/deploy-notification
```

## Consequences

- **Language-agnostic.** Hooks can invoke Drush, shell utilities, Node scripts, curl, or any other tool available in the execution environment. No PHP API to learn.
- **Visible and auditable.** The complete hook sequence for any workflow is readable in `suds.yml` alongside the rest of the project's SUDS configuration.
- **CI-compatible.** The same hook strings run identically locally and in CI pipelines without any adaptation.
- **No return value mechanism.** Hooks communicate success or failure via exit codes only. A hook that needs to pass data to a subsequent step must do so via the filesystem or environment variables set in a prior step — SUDS does not provide a structured data-passing mechanism between hooks.
- **Execution context varies by command.** `deploy.hooks.pre_deploy` and `deploy.hooks.post_deploy` run on the CI machine in the project root. `deploy.build_steps` run in the artifact directory. `update.hooks.*` run wherever `suds:update` executes — on the server when invoked via a remote Drush alias. Hook authors must be aware of which environment their commands target.
- **No isolation.** Each hook command is a separate shell invocation. Environment variables exported in one command are not available in the next unless passed explicitly (e.g., via a wrapper script). For complex multi-step hooks that need shared state, the recommended pattern is a script file invoked from the hook list.
