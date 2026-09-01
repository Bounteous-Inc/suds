<?php

declare(strict_types=1);

namespace Bounteous\Suds\Drush\Commands;

use Bounteous\Suds\Config\ConfigLoaderAwareTrait;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drush\Commands\DrushCommands;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;
use Drush\Utils\StringUtils;

/**
 * Drush commands for routine dependency maintenance updates.
 */
class DepsUpdateCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use SiteAliasManagerAwareTrait;
  use ConfigLoaderAwareTrait;
  use ProcessHelperTrait;

  /**
   * Update dependencies and capture any resulting config drift.
   *
   * Runs deps_update.hooks.pre_deps_update commands, then one `composer
   * update` pass per group in deps_update.composer.groups (or a single
   * unrestricted pass when no groups are configured), then updatedb,
   * cache:rebuild, and config:export, then deps_update.hooks.post_deps_update
   * commands. Intended to run outward from a dev environment to prepare a
   * commit (composer.lock + config changes) for review — distinct from
   * suds:update, which imports config into a deployed environment.
   *
   * @param string $packages
   *   Comma-separated packages to update, overriding
   *   deps_update.composer.groups with a single composer update pass
   *   restricted to these packages.
   * @param array $options
   *   An associative array of options.
   *
   * @phpstan-param array{'skip-cex': bool} $options
   *
   * @command suds:deps-update
   * @aliases su-deps-update
   * @bootstrap none
   * @argument packages Comma-separated packages to update, overriding configured groups.
   * @option skip-cex Skip config export regardless of config.
   * @usage drush suds:deps-update
   *   Update dependencies per configured groups and export any config drift.
   * @usage drush suds:deps-update drupal/core-recommended,drupal/core-dev
   *   Update only the given packages, ignoring configured groups.
   * @usage drush suds:deps-update --skip-cex
   *   Update dependencies without exporting configuration.
   */
  public function depsUpdate(string $packages = '', array $options = ['skip-cex' => FALSE]): void {
    $loader      = $this->configLoader();
    $config      = $loader->load();
    $alias       = $this->siteAliasManager()->getSelf();
    $projectRoot = $loader->getProjectRoot();

    $this->io()->title('SUDS: Updating Dependencies');
    $this->warnConfigIssues();

    // Pre-deps-update hooks.
    foreach ($config['deps_update']['hooks']['pre_deps_update'] as $cmd) {
      $this->io()->note(sprintf('Running: %s', $cmd));
      $this->runShellCommand($cmd, $projectRoot);
    }

    $this->requireExecutable('composer');

    $requestedPackages = StringUtils::csvToArray($packages);
    $groups = $requestedPackages !== [] ? [$requestedPackages] : $config['deps_update']['composer']['groups'];
    if ($groups === []) {
      $groups = [[]];
    }
    foreach ($groups as $group) {
      $cmd = $group === [] ? 'composer update' : 'composer update ' . implode(' ', $group);
      $this->io()->note(sprintf('Running: %s', $cmd));
      $this->runShellCommand($cmd, $projectRoot);
    }

    $drushOptions = $this->redispatchOptions(['skip-cex']);

    // Apply database updates and rebuild caches.
    $this->runDrushCommand($alias, 'updatedb', [], $drushOptions);
    $this->runDrushCommand($alias, 'cache:rebuild', [], $drushOptions);

    // Capture config drift introduced by update hooks.
    if (!$options['skip-cex'] && !$config['deps_update']['skip_config_export']) {
      $this->runDrushCommand($alias, 'config:export', [], $drushOptions);
    }

    // Post-deps-update hooks.
    foreach ($config['deps_update']['hooks']['post_deps_update'] as $cmd) {
      $this->io()->note(sprintf('Running: %s', $cmd));
      $this->runShellCommand($cmd, $projectRoot);
    }

    $this->io()->success('Dependency update complete.');
  }

}
