<?php

declare(strict_types=1);

namespace Bounteous\Suds\Drush\Commands;

use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drush\Commands\DrushCommands;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;

/**
 * Drush commands for initializing a new SUDS-managed Drupal project.
 */
class InitCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use SiteAliasManagerAwareTrait;
  use ProcessHelperTrait;
  use WorkingDirectoryTrait;

  /**
   * Initialize a new SUDS project by creating suds.yml.
   *
   * Creates a minimal suds.yml in the current working directory and appends
   * suds.local.yml to .gitignore when a .gitignore file is present. Supply
   * --name, --drupal-root, and --skip-ci (or --ci-provider) to run
   * non-interactively (suitable for CI).
   *
   * @param array $options
   *   An associative array of options.
   *
   * @phpstan-param array{name: string, 'drupal-root': string, 'skip-quality': bool, 'skip-ci': bool, 'ci-provider': string} $options
   *
   * @command suds:init
   * @aliases su-init
   * @bootstrap none
   * @option name The project name. Skips the interactive name prompt when provided.
   * @option drupal-root The Drupal webroot directory. Skips detection and prompt.
   * @option skip-quality Skip scaffolding quality tool config files.
   * @option skip-ci Skip scaffolding CI configuration. Mutually exclusive with --ci-provider.
   * @option ci-provider CI provider to scaffold (github, gitlab, bitbucket). Skips the interactive prompt when provided. Mutually exclusive with --skip-ci.
   * @usage drush suds:init
   *   Interactively create suds.yml, quality tooling, and CI config.
   * @usage drush suds:init --name="My Project" --drupal-root=docroot --skip-quality --skip-ci
   *   Create suds.yml non-interactively, skipping all scaffolding.
   * @usage drush suds:init --skip-quality --ci-provider=github
   *   Create suds.yml and GitHub Actions CI config only.
   */
  public function init(
    array $options = [
      'name'         => '',
      'drupal-root'  => '',
      'skip-quality' => FALSE,
      'skip-ci'      => FALSE,
      'ci-provider'  => '',
    ],
  ): void {
    $targetDir = $this->getTargetDir();
    $configFile = $targetDir . '/suds.yml';
    $gitignoreFile = $targetDir . '/.gitignore';

    if (file_exists($configFile)) {
      throw new \RuntimeException(sprintf(
        'suds.yml already exists at %s. Remove it first to re-initialize.',
        $configFile,
      ));
    }

    $projectName = $options['name'];
    if ($projectName === '') {
      $projectName = $this->io()->ask('Project name');
      if (!is_string($projectName) || trim($projectName) === '') {
        throw new \RuntimeException('Project name cannot be empty.');
      }
      $projectName = trim($projectName);
    }
    else {
      $projectName = trim($projectName);
      if ($projectName === '') {
        throw new \RuntimeException(
          'Project name cannot be empty or whitespace-only.',
        );
      }
    }

    $drupalRoot = $options['drupal-root'];
    if ($drupalRoot === '') {
      $detected = $this->detectDrupalRoot($targetDir);
      if ($detected !== NULL) {
        $this->io()->note(sprintf('Detected Drupal webroot: %s', $detected));
        $drupalRoot = $detected;
      }
      else {
        $drupalRoot = $this->io()->ask('Drupal webroot', 'web');
      }
    }

    $yaml = <<<YAML
      # suds.yml - SUDS project configuration.
      #
      # This file contains only your project-specific overrides.
      # All available options and their defaults are documented in SUDS's
      # built-in defaults. Run the following to view them:
      #
      #   drush suds:config:dump             # show merged result for this project
      #   drush suds:config:dump --defaults  # show built-in defaults only
      project:
        name: {$projectName}

      drupal:
        root: {$drupalRoot}

      sync:
        # The Drush site alias to pull from when running suds:sync.
        # Set this once your aliases are configured (e.g. @prod, @myproject.prod).
        # default_source: '@prod'
      YAML;

    // Strip the heredoc indentation added for PHP readability.
    $yaml = preg_replace('/^      /m', '', $yaml);

    if (file_put_contents($configFile, $yaml) === FALSE) {
      throw new \RuntimeException(sprintf('Failed to write %s.', $configFile));
    }

    $sudsMark = "# SUDS local overrides (developer-specific, never commit).\nsuds.local.yml\n";
    if (file_exists($gitignoreFile)) {
      $gitignoreContents = file_get_contents($gitignoreFile);
      if ($gitignoreContents === FALSE) {
        throw new \RuntimeException(sprintf('Failed to read %s.', $gitignoreFile));
      }
      if (!str_contains($gitignoreContents, 'suds.local.yml')) {
        if (file_put_contents($gitignoreFile, "\n" . $sudsMark, FILE_APPEND) === FALSE) {
          throw new \RuntimeException(sprintf('Failed to update %s.', $gitignoreFile));
        }
      }
    }
    else {
      if (file_put_contents($gitignoreFile, $sudsMark) === FALSE) {
        throw new \RuntimeException(sprintf('Failed to create %s.', $gitignoreFile));
      }
      $this->io()->note('Created .gitignore with suds.local.yml.');
    }

    if ($options['skip-ci'] && $options['ci-provider'] !== '') {
      throw new \InvalidArgumentException(
        '--skip-ci and --ci-provider are mutually exclusive.',
      );
    }

    if (!$options['skip-quality']) {
      $this->dispatchQualityScaffold();
    }

    if (!$options['skip-ci']) {
      $ciProvider = $options['ci-provider'];
      if ($ciProvider === '') {
        $ciProvider = $this->io()->choice(
          'Scaffold CI configuration? Choose a provider or skip.',
          ['github', 'gitlab', 'bitbucket', 'none'],
          'none',
        );
      }
      if ($ciProvider !== 'none') {
        $this->dispatchCiScaffold($ciProvider);
      }
    }

    $this->io()->success(sprintf('Initialized project "%s".', $projectName));
    $nextSteps = [
      'Next steps:',
      '  1. Review and customize suds.yml.',
      '  2. Create suds.local.yml for developer-specific overrides (e.g. a local source alias).',
      '  3. Run <info>drush suds:config:dump</info> to verify your configuration.',
    ];
    $this->io()->text($nextSteps);
  }

  /**
   * Detects the Drupal webroot by scanning for known subdirectory names.
   *
   * Looks for web/, docroot/, and html/ inside $targetDir. Returns the
   * directory name when exactly one candidate is found, or null when zero
   * or multiple candidates exist (ambiguous or absent).
   *
   * @param string $targetDir
   *   Absolute path to the directory to scan.
   *
   * @return string|null
   *   The subdirectory name (e.g. 'web'), or null if indeterminate.
   */
  protected function detectDrupalRoot(string $targetDir): ?string {
    $found = [];
    foreach (['web', 'docroot', 'html'] as $candidate) {
      if (is_dir($targetDir . '/' . $candidate)) {
        $found[] = $candidate;
      }
    }
    return count($found) === 1 ? $found[0] : NULL;
  }

  /**
   * Dispatches suds:scaffold:quality as a Drush sub-command.
   *
   * Extracted as a protected method so integration-test subclasses can
   * override it as a no-op without requiring a live Drush DI container.
   */
  protected function dispatchQualityScaffold(): void {
    $this->runDrushCommand(
      $this->siteAliasManager()->getSelf(),
      'suds:scaffold:quality',
      [],
      $this->initSubCommandOptions(),
    );
  }

  /**
   * Dispatches suds:scaffold:ci as a Drush sub-command.
   *
   * Extracted as a protected method so integration-test subclasses can
   * override it as a no-op without requiring a live Drush DI container.
   *
   * @param string $provider
   *   The CI provider identifier (e.g. 'github').
   */
  protected function dispatchCiScaffold(string $provider): void {
    $this->runDrushCommand(
      $this->siteAliasManager()->getSelf(),
      'suds:scaffold:ci',
      [$provider],
      $this->initSubCommandOptions(),
    );
  }

  /**
   * Returns redispatch options with suds:init-specific flags removed.
   *
   * Prevents suds:init's own options (--name, --drupal-root, --skip-quality,
   * --skip-ci, --ci-provider) from being forwarded via redispatchOptions() to
   * child commands that do not define them.
   *
   * @return array<string, mixed>
   *   Filtered options suitable for passing to child drush processes.
   */
  private function initSubCommandOptions(): array {
    $opts = $this->redispatchOptions();
    unset($opts['name'], $opts['drupal-root'], $opts['skip-quality'], $opts['skip-ci'], $opts['ci-provider']);
    return $opts;
  }

}
