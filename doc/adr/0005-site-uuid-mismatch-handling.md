# 5. Site UUID mismatch handling

Date: 2026-09-01

## Status

Accepted

## Context

Drupal's `config:import` refuses to run when the active site's `system.site.uuid` differs from the one recorded in `system.site.yml` in the config sync directory. Drupal assigns a fresh UUID at install time, so any environment provisioned independently of the source database — installed rather than restored from a dump — diverges from committed configuration and cannot import it.

This affects SUDS directly. `suds:setup` defaults to a plain `site:install` (`--existing-config` is opt-in), so the documented setup path produces exactly this state, and `suds:update` then runs `config:import` against it.

Two approaches were considered.

1. **Reconcile automatically.** Before importing, read the UUID from config sync and overwrite the site's UUID with it. This is what Acquia BLT does in `drupal:config:import`, citing [drupal.org#1613424](https://www.drupal.org/project/drupal/issues/1613424). It makes the failure disappear without operator involvement, and SUDS is explicitly inspired by BLT, so it is the path of least surprise for teams migrating from it.

2. **Fail, with reconciliation opt-in.** Compare the two UUIDs and stop with an explanation when they differ, unless the project has declared that config sync is authoritative.

The deciding factor is what Drupal's check is *for*. `SystemConfigSubscriber` registers it on `ConfigEvents::IMPORT_VALIDATE` at priority 256, immediately below the priority-512 guard that rejects an empty import because it "would delete all of your configuration". They are siblings: both are destructive-import guards. The UUID check exists to stop one site's configuration being imported into another, and a full `config:import` deletes configuration absent from the source.

That makes the two failure modes asymmetric. Auto-reconciling when the mismatch is meaningful — a wrong sync directory, config exported from a scratch install, a database restored from a different site — defeats the guard, imports foreign configuration, and is detected only after the damage. Failing when the mismatch is benign costs one configuration key and a re-run.

Automatic reconciliation was implemented first and reverted in favour of the second option. Note also that this is not a gap Drush intends to fill: [drush-ops/drush#5946](https://github.com/drush-ops/drush/pull/5946) proposed an `--override-site-uuid` option for `config:import` and was declined, on the grounds that installing from configuration is the better answer.

## Decision

`suds:update` compares the active site UUID against config sync before importing, and fails on a mismatch by default. The failure names the likely causes and the two ways forward.

Reconciliation is opt-in, for projects where config sync genuinely is authoritative:

```yaml
# suds.yml
update:
  reconcile_site_uuid: true
```

```bash
# or for a single run
drush suds:update --reconcile-site-uuid
```

The comparison runs after `cache:rebuild` but before `updatedb`. It is a pure read, so aborting leaves the database untouched rather than half-updated; it stays after `cache:rebuild` because reading configuration needs a container matching the newly deployed code.

The UUID is read from `system.site.yml` in the sync directory directly, rather than via `drush config:get --source=sync`. That option is declared on the command but never read by `ConfigCommands::get()`, which always returns active storage.

## Consequences

- **The guard is preserved.** A mismatch caused by a wrong sync directory or a foreign database surfaces before `config:import` can delete configuration.
- **The recommended fix is upstream of the failure.** The error points at `drush suds:setup --existing-config`, which never diverges in the first place, rather than treating reconciliation as the normal path.
- **Stricter than BLT.** Teams migrating from BLT will hit a hard failure where BLT silently continued. This is the intended trade, but it is a real migration cost and the most likely source of "why doesn't SUDS just handle this?" — see [drush-ops/drush#5946](https://github.com/drush-ops/drush/pull/5946) for the same argument playing out upstream.
- **Ephemeral environments need configuration.** CI that installs fresh and then imports config must set `update.reconcile_site_uuid: true` once per project. Without it, every pipeline fails on the first update.
- **No write when UUIDs match.** The previous automatic approach issued a `config:set` on every update regardless; the comparison now skips it entirely in the common case.
- **Reconciliation is announced.** When it does run it logs the UUID it is writing, because under a remote dispatch (`drush @prod suds:update`) it rewrites production's site identity.
- **Two extra Drush subprocesses per update.** One `status` to locate the sync directory, one `config:get` for the active UUID. `drush status` carries no site UUID, so these cannot be collapsed without an inline `php:eval`, which was judged not worth the loss of clarity.
