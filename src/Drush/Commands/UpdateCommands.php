<?php

declare(strict_types=1);

namespace Bounteous\Suds\Drush\Commands;

use Bounteous\Suds\Config\ConfigLoaderAwareTrait;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drush\Commands\DrushCommands;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;

/**
 * Drush commands for applying code updates to a Drupal environment.
 */
class UpdateCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use SiteAliasManagerAwareTrait;
  use ConfigLoaderAwareTrait;
  use ProcessHelperTrait;

  /**
   * Apply code updates: rebuild caches, run DB updates, and import config.
   *
   * Runs update.hooks.pre_update commands, then cache:rebuild, updatedb, and
   * config:import twice (the second pass ensures modules enabled during the
   * first import have their config applied, which is required when using
   * config_split or similar contrib modules). Finally executes
   * update.hooks.post_update shell commands (e.g. frontend asset compilation).
   *
   * @command suds:update
   * @aliases su-update
   * @bootstrap none
   * @usage drush suds:update
   *   Rebuild caches, apply DB updates, and import configuration.
   */
  public function update(): void {
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

    // Rebuild caches.
    $this->runDrushCommand($alias, 'cache:rebuild', [], $this->redispatchOptions());

    // Run database updates.
    $this->runDrushCommand($alias, 'updatedb', [], $this->redispatchOptions());

    // Import configuration twice for config_split compatibility.
    // The first import may enable modules whose own config requires a second
    // pass to reach a fully consistent state.
    //
    // When the config sync directory is empty (e.g. immediately after
    // site:install before any config has been exported), Drush rejects
    // config:import as a safety guard. Treat this as a no-op and continue.
    $cimOptions = $this->redispatchOptions();
    for ($pass = 0; $pass < 2; $pass++) {
      try {
        $this->runDrushCommand($alias, 'config:import', [], $cimOptions);
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

}
