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
  use ComposerManifestTrait;
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
    // Profile is passed as a positional argument; existing-config is re-added
    // below when set.
    $siOptions = $this->redispatchOptions(['profile', 'existing-config']);
    if ($options['existing-config']) {
      $siOptions['existing-config'] = TRUE;
    }
    $this->runDrushCommand($alias, 'site:install', [$profile], $siOptions);

    // Apply recipes. Three decisions worth knowing:
    //
    // 1. Recipe paths in suds.yml are relative to the project root (the
    //    directory holding suds.yml), and are made absolute here. Neither
    //    runner resolves a relative path against the cwd — both resolve it
    //    against the Drupal root — so passing a config value through
    //    unchanged would silently mean a different directory.
    // 2. The command runs from the Drupal root, because the
    //    core/scripts/drupal fallback loads core/core.services.yml relative
    //    to the cwd.
    // 3. The runner is resolved on first use, so a project that configures no
    //    recipes needs no recipe binary installed.
    $drupalRoot = $projectRoot . '/' . $config['drupal']['root'];
    $recipeRunner = NULL;
    foreach ($config['setup']['recipes'] as $recipePath) {
      $recipeRunner ??= $this->requireRecipeRunner($projectRoot, $drupalRoot);
      $this->io()->note(sprintf('Applying recipe: %s', $recipePath));
      $this->runShellCommand(
        sprintf(
          '%s recipe %s',
          $recipeRunner,
          escapeshellarg($projectRoot . '/' . $recipePath),
        ),
        $drupalRoot,
      );
    }

    // Post-setup hooks.
    foreach ($config['setup']['hooks']['post_setup'] as $cmd) {
      $this->io()->note(sprintf('Running: %s', $cmd));
      $this->runShellCommand($cmd, $projectRoot);
    }

    $this->io()->success('Drupal site setup complete.');
  }

  /**
   * Resolves a shell-safe invocation prefix for Drupal's recipe runner.
   *
   * Drupal core exposes the recipe command through two entry points:
   * `vendor/bin/dr`, the Composer bin registered from 11.4.0 onward, and
   * `<drupal-root>/core/scripts/drupal`, the only one available on 10.4
   * through 11.3.
   *
   * The ordering matters for more than preference. On 11.4+ the core script is
   * a deprecated shim that includes `scripts/dr`, and `dr` invoked that way
   * falls back to `<drupal-root>/vendor/autoload.php` — a path that only
   * exists when vendor/ is nested inside the Drupal root. That is the failure
   * behind https://github.com/Bounteous-Inc/suds/issues/25, and preferring
   * `dr` avoids both it and the deprecation notice. Since `dr` does not exist
   * before 11.4.0, the two candidates never collide.
   *
   * @param string $projectRoot
   *   Absolute path to the project root containing vendor/.
   * @param string $drupalRoot
   *   Absolute path to the Drupal root (the webroot).
   *
   * @return string
   *   A shell-escaped command prefix to which ` recipe <path>` is appended.
   *
   * @throws \RuntimeException
   *   When neither entry point is present.
   */
  protected function requireRecipeRunner(string $projectRoot, string $drupalRoot): string {
    $composerBin = $this->vendorDir($projectRoot) . '/bin/dr';
    if (is_file($composerBin)) {
      return escapeshellarg($composerBin);
    }

    $coreScript = $drupalRoot . '/core/scripts/drupal';
    if (is_file($coreScript)) {
      return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($coreScript);
    }

    throw new \RuntimeException(
      sprintf(
        'Could not find a Drupal recipe runner: no %s (Drupal 11.4+) and no %s (Drupal 10.4+). Ensure drupal/core is installed via Composer.',
        $composerBin,
        $coreScript,
      ),
    );
  }

  /**
   * Returns the absolute Composer vendor directory for a project.
   *
   * Composer's vendor directory is configurable via `config.vendor-dir`, so
   * assuming `<project-root>/vendor` breaks projects that relocate it. Reads
   * the project's own composer.json and falls back to Composer's default.
   *
   * Deliberately does not use Composer\InstalledVersions: that reports the
   * vendor tree of whichever autoloader loaded this code, which under a
   * global Drush install is Drush's own tree rather than the project's.
   *
   * @param string $projectRoot
   *   Absolute path to the project root.
   *
   * @return string
   *   Absolute path to the vendor directory, without a trailing slash.
   */
  protected function vendorDir(string $projectRoot): string {
    $configured = $this->readComposerManifest($projectRoot)['config']['vendor-dir'] ?? NULL;
    if (!is_string($configured) || $configured === '') {
      return $projectRoot . '/vendor';
    }
    return str_starts_with($configured, '/')
      ? rtrim($configured, '/')
      : $projectRoot . '/' . rtrim($configured, '/');
  }

}
