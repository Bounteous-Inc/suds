<?php

declare(strict_types=1);

namespace Bounteous\Suds\Drush\Commands;

use Bounteous\Suds\Config\ConfigLoaderAwareTrait;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drush\Commands\DrushCommands;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;

/**
 * Drush commands for installing and configuring a Drupal site.
 */
class SetupCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use SiteAliasManagerAwareTrait;
  use ConfigLoaderAwareTrait;
  use ProcessHelperTrait;

  /**
   * Install and configure a Drupal site.
   *
   * Runs drush site:install with the profile from config, applies any
   * setup.recipes in order, then executes setup.hooks.post_setup commands.
   *
   * @param array $options
   *   An associative array of options.
   *
   * @phpstan-param array{profile: string, existing-config: bool} $options
   *
   * @command suds:setup
   * @aliases su-setup
   * @bootstrap none
   * @option profile Drupal installation profile. Overrides drupal.profile in config.
   * @option existing-config Import configuration from the sync directory after install.
   * @usage drush suds:setup
   *   Install Drupal using the profile and options from suds.yml.
   * @usage drush suds:setup --profile=minimal
   *   Install using the minimal profile, overriding config.
   * @usage drush suds:setup --existing-config
   *   Install then immediately import configuration from the sync directory.
   */
  public function setup(array $options = ['profile' => '', 'existing-config' => FALSE]): void {
    $loader      = $this->configLoader();
    $config      = $loader->load();
    $profile     = $options['profile'] ?: $config['drupal']['profile'];
    $alias       = $this->siteAliasManager()->getSelf();
    $projectRoot = $loader->getProjectRoot();

    $this->io()->title('SUDS: Setting Up Drupal Site');
    $this->warnConfigIssues();
    $this->io()->note(sprintf('Installing profile: %s', $profile));

    // Pre-setup hooks.
    foreach ($config['setup']['hooks']['pre_setup'] as $cmd) {
      $this->io()->note(sprintf('Running: %s', $cmd));
      $this->runShellCommand($cmd, $projectRoot);
    }

    // Install Drupal.
    // Redispatch options forwards parent flags (e.g. --yes, --verbose) to the
    // child drush process so the user's intent propagates without extra
    // plumbing.
    // drush site:install already prompts before wiping an existing database, so
    // we do not need a separate guard here.
    //
    // Unset suds:setup-specific options so they are not forwarded to
    // site:install which does not define them. Profile is passed as a
    // positional argument; existing-config is re-added below when set.
    $siOptions = $this->redispatchOptions();
    unset($siOptions['profile'], $siOptions['existing-config']);
    if ($options['existing-config']) {
      $siOptions['existing-config'] = TRUE;
    }
    $this->runDrushCommand($alias, 'site:install', [$profile], $siOptions);

    // Apply recipes.
    $drupalRoot = $projectRoot . '/' . $config['drupal']['root'];
    foreach ($config['setup']['recipes'] as $recipePath) {
      $this->io()->note(sprintf('Applying recipe: %s', $recipePath));
      $this->runShellCommand(
        sprintf(
          'php %s/core/scripts/drupal recipe %s',
          escapeshellarg($drupalRoot),
          escapeshellarg($recipePath),
        ),
        $projectRoot,
      );
    }

    // Post-setup hooks.
    foreach ($config['setup']['hooks']['post_setup'] as $cmd) {
      $this->io()->note(sprintf('Running: %s', $cmd));
      $this->runShellCommand($cmd, $projectRoot);
    }

    $this->io()->success('Drupal site setup complete.');
  }

}
