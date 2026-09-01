<?php

declare(strict_types=1);

namespace Bounteous\Suds\Drush\Commands;

use Bounteous\Suds\Config\ConfigLoaderAwareTrait;
use Consolidation\SiteAlias\SiteAlias;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drush\Commands\DrushCommands;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Drush commands for applying code updates to a Drupal environment.
 */
class UpdateCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use SiteAliasManagerAwareTrait;
  use ConfigLoaderAwareTrait;
  use ProcessHelperTrait;

  /**
   * Operator guidance shown when the site UUID diverges from config sync.
   *
   * Takes the active UUID and the config sync UUID, in that order.
   */
  private const UUID_MISMATCH_MESSAGE = <<<'TXT'
  Site UUID does not match config sync, so config:import will be rejected.

    active site: %s
    config sync: %s

  Drupal blocks this because a mismatch normally means the configuration
  was exported from a different site. Common causes:
    - The site was installed fresh instead of from existing config.
      Reinstall with: drush suds:setup --existing-config
    - The config sync directory holds another site's configuration.
    - The database was restored from a different site than this config.

  If config sync is authoritative for this project, set
  update.reconcile_site_uuid: true in suds.yml, or pass
  --reconcile-site-uuid to overwrite the site UUID for a single run.
  TXT;

  /**
   * Apply code updates: rebuild caches, run DB updates, and import config.
   *
   * Runs update.hooks.pre_update commands, then cache:rebuild, updatedb, and
   * config:import twice (the second pass ensures modules enabled during the
   * first import have their config applied, which is required when using
   * config_split or similar contrib modules). Finally executes
   * update.hooks.post_update shell commands (e.g. frontend asset compilation).
   *
   * @param array $options
   *   An associative array of options.
   *
   * @phpstan-param array{reconcile-site-uuid: bool} $options
   *
   * @command suds:update
   * @aliases su-update
   * @bootstrap none
   * @option reconcile-site-uuid Overwrite the site UUID with the config sync value when the two differ, instead of failing. Overrides update.reconcile_site_uuid.
   * @usage drush suds:update
   *   Rebuild caches, apply DB updates, and import configuration.
   * @usage drush suds:update --reconcile-site-uuid
   *   Also overwrite a mismatched site UUID with the value from config sync.
   */
  public function update(array $options = ['reconcile-site-uuid' => FALSE]): void {
    $loader      = $this->configLoader();
    $config      = $loader->load();
    $alias       = $this->siteAliasManager()->getSelf();
    $projectRoot = $loader->getProjectRoot();

    $this->io()->title('SUDS: Updating Environment');
    $this->warnConfigIssues();

    // Pre-update hooks.
    foreach ($config['update']['hooks']['pre_update'] as $cmd) {
      $this->io()->note(sprintf('Running: %s', $cmd));
      $this->runShellCommand($cmd, $projectRoot);
    }

    $drushOptions = $this->redispatchOptions(['reconcile-site-uuid']);

    // Rebuild caches.
    $this->runDrushCommand($alias, 'cache:rebuild', [], $drushOptions);

    // Sits between the two deliberately: reading config needs the container
    // cache:rebuild just built, and failing before updatedb leaves the database
    // untouched rather than half-updated.
    $this->ensureSiteUuidMatchesConfigSync(
      $alias,
      $drushOptions,
      $options['reconcile-site-uuid'] || $config['update']['reconcile_site_uuid'],
    );

    // Run database updates.
    $this->runDrushCommand($alias, 'updatedb', [], $drushOptions);

    // Import configuration twice for config_split compatibility.
    // The first import may enable modules whose own config requires a second
    // pass to reach a fully consistent state.
    //
    // When the config sync directory is empty (e.g. immediately after
    // site:install before any config has been exported), Drush rejects
    // config:import as a safety guard. Treat this as a no-op and continue.
    for ($pass = 0; $pass < 2; $pass++) {
      try {
        $this->runDrushCommand($alias, 'config:import', [], $drushOptions);
      }
      catch (\Exception $e) {
        if (str_contains($e->getMessage(), 'import is empty') || str_contains($e->getMessage(), 'delete all of your configuration')) {
          $this->io()->note('Config sync directory has no pending changes — config:import skipped.');
          break;
        }
        throw $e;
      }
    }

    // Post-update hooks.
    foreach ($config['update']['hooks']['post_update'] as $cmd) {
      $this->io()->note(sprintf('Running: %s', $cmd));
      $this->runShellCommand($cmd, $projectRoot);
    }

    $this->io()->success('Environment update complete.');
  }

  /**
   * Fails when the site UUID diverges from config sync, unless reconciling.
   *
   * Why failing is the default rather than reconciling: see
   * doc/adr/0005-site-uuid-mismatch-handling.md.
   *
   * @param \Consolidation\SiteAlias\SiteAlias $alias
   *   The site alias to inspect.
   * @param array<string, mixed> $opts
   *   Options to forward to child Drush commands.
   * @param bool $reconcile
   *   When TRUE, overwrite the site UUID with the config sync value instead of
   *   throwing.
   *
   * @throws \RuntimeException
   *   When the UUIDs differ and reconciliation is not enabled.
   */
  private function ensureSiteUuidMatchesConfigSync(SiteAlias $alias, array $opts, bool $reconcile): void {
    $syncUuid = $this->getConfigSyncUuid($alias, $opts);
    if ($syncUuid === NULL) {
      // Nothing exported to compare against; config:import guards that itself.
      return;
    }

    $activeUuid = $this->getActiveSiteUuid($alias, $opts);
    if ($activeUuid === $syncUuid) {
      return;
    }

    if (!$reconcile) {
      throw new \RuntimeException(
        sprintf(self::UUID_MISMATCH_MESSAGE, $activeUuid, $syncUuid),
      );
    }

    // Announced because under a remote dispatch (drush @prod suds:update) this
    // rewrites production's site identity.
    $this->io()->note(sprintf('Reconciling site UUID with config sync: %s', $syncUuid));
    $this->runDrushCommand(
      $alias,
      'config:set',
      ['system.site', 'uuid', $syncUuid],
      $opts + ['yes' => TRUE],
    );
  }

  /**
   * Returns the site UUID recorded in the config sync directory.
   *
   * Reads system.site.yml directly rather than via `config:get --source=sync`:
   * that option is declared on the Drush command but never read, so it always
   * returns active storage.
   *
   * Declared protected so tests can stub it.
   *
   * @param \Consolidation\SiteAlias\SiteAlias $alias
   *   The site alias to inspect.
   * @param array<string, mixed> $opts
   *   Options to forward to the child Drush command, so that overrides such as
   *   --uri resolve the sync directory for the intended multisite.
   *
   * @return string|null
   *   The UUID from config sync, or NULL if it cannot be determined.
   */
  protected function getConfigSyncUuid(SiteAlias $alias, array $opts): ?string {
    $status = json_decode(
      $this->runDrushCommandCapture($alias, 'status', [], ['format' => 'json'] + $opts),
      TRUE,
    );
    $syncDir = is_array($status) ? ($status['config'] ?? NULL) : NULL;
    if (!is_string($syncDir) || $syncDir === '') {
      return NULL;
    }

    // `config` may be reported relative to the Drupal root.
    $root = $status['root'] ?? NULL;
    if (!str_starts_with($syncDir, '/') && is_string($root) && $root !== '') {
      $syncDir = $root . '/' . $syncDir;
    }

    $siteYml = $syncDir . '/system.site.yml';
    if (!file_exists($siteYml)) {
      return NULL;
    }

    $syncConfig = Yaml::parseFile($siteYml);
    $uuid       = is_array($syncConfig) ? ($syncConfig['uuid'] ?? NULL) : NULL;
    return is_string($uuid) && $uuid !== '' ? $uuid : NULL;
  }

  /**
   * Returns the site UUID stored in active configuration.
   *
   * Declared protected so tests can stub it without a real Drush process.
   *
   * @param \Consolidation\SiteAlias\SiteAlias $alias
   *   The site alias to inspect.
   * @param array<string, mixed> $opts
   *   Options to forward to the child Drush command.
   *
   * @return string
   *   The active site UUID.
   */
  protected function getActiveSiteUuid(SiteAlias $alias, array $opts): string {
    return $this->runDrushCommandCapture(
      $alias,
      'config:get',
      ['system.site', 'uuid'],
      ['format' => 'string'] + $opts,
    );
  }

}
