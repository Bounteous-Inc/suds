<?php

declare(strict_types=1);

namespace Bounteous\Suds\Drush\Commands;

use Bounteous\Suds\Config\ConfigLoaderAwareTrait;
use Bounteous\Suds\Config\ConfigLoaderInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drush\Commands\DrushCommands;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;

/**
 * Drush commands for validating a SUDS-managed environment.
 */
class DoctorCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use SiteAliasManagerAwareTrait;
  use ConfigLoaderAwareTrait;
  use ProcessHelperTrait;

  private const PASS = 'OK';
  private const WARN = 'WARN';
  private const FAIL = 'FAIL';

  /**
   * Validate the local environment for use with SUDS.
   *
   * Checks required tools, configuration, and project structure. Exits with
   * a non-zero status code if any required check fails.
   *
   * @command suds:doctor
   * @aliases su-doctor
   * @bootstrap none
   * @usage drush suds:doctor
   *   Check the local environment for common configuration problems.
   */
  public function doctor(): void {
    $this->io()->title('SUDS: Checking Environment');

    $results = [
      ...$this->checkBinaries(),
      ...$this->checkPhpVersion(),
      ...$this->checkProjectConfig(),
    ];

    $this->renderResults($results);

    $failures = array_filter($results, static fn (array $r) => $r['status'] === self::FAIL);
    if ($failures) {
      throw new \RuntimeException(
        sprintf('%d check(s) failed. See above for details.', count($failures)),
      );
    }
  }

  /**
   * Checks that required and recommended CLI tools are available.
   *
   * @return array<int, array{status: string, label: string, message: string, hint?: string}>
   *   Check results for each binary.
   */
  private function checkBinaries(): array {
    $results = [];

    // Composer is required for suds:sync and general operation.
    $composerPath = $this->findExecutable('composer');
    $results[] = $composerPath
      ? ['status' => self::PASS, 'label' => 'composer', 'message' => "Found at $composerPath"]
      : [
        'status'  => self::FAIL,
        'label'   => 'composer',
        'message' => 'Not found — required for suds:sync',
        'hint'    => 'Install from https://getcomposer.org',
      ];

    // Rsync is required for suds:files:sync.
    $rsyncPath = $this->findExecutable('rsync');
    $results[] = $rsyncPath
      ? ['status' => self::PASS, 'label' => 'rsync', 'message' => "Found at $rsyncPath"]
      : [
        'status'  => self::WARN,
        'label'   => 'rsync',
        'message' => 'Not found — required for suds:files:sync',
        'hint'    => 'Install via your package manager (e.g. apt/brew install rsync)',
      ];

    // Git is required for suds:deploy.
    $gitPath = $this->findExecutable('git');
    $results[] = $gitPath
      ? ['status' => self::PASS, 'label' => 'git', 'message' => "Found at $gitPath"]
      : [
        'status'  => self::WARN,
        'label'   => 'git',
        'message' => 'Not found — required for suds:deploy',
        'hint'    => 'Install via your package manager (e.g. apt/brew install git)',
      ];

    return $results;
  }

  /**
   * Checks that the running PHP version meets SUDS's minimum requirement.
   *
   * @return array<int, array{status: string, label: string, message: string, hint?: string}>
   *   A single-element array containing the PHP version check result.
   */
  private function checkPhpVersion(): array {
    if ($this->getPhpVersionId() < 80300) {
      return [[
        'status'  => self::FAIL,
        'label'   => 'PHP version',
        'message' => 'Running ' . PHP_VERSION . ' — 8.3 or higher required',
        'hint'    => 'Upgrade to PHP 8.3 or higher',
      ],
      ];
    }

    return [[
      'status'  => self::PASS,
      'label'   => 'PHP version',
      'message' => PHP_VERSION,
    ],
    ];
  }

  /**
   * Returns the current PHP_VERSION_ID integer.
   *
   * Extracted for testability.
   *
   * @return int
   *   The PHP_VERSION_ID constant value.
   */
  protected function getPhpVersionId(): int {
    return PHP_VERSION_ID;
  }

  /**
   * Checks suds.yml presence and validates key configuration values.
   *
   * @return array<int, array{status: string, label: string, message: string, hint?: string}>
   *   Check results for suds.yml, project.name, drupal.root, and more.
   */
  private function checkProjectConfig(): array {
    $loader  = $this->configLoader();
    $results = [];

    $results[] = $this->checkSudsYmlExists($loader);
    if (!$loader->hasProjectConfig()) {
      return $results;
    }

    $config      = $loader->load();
    $projectRoot = $loader->getProjectRoot();

    $results[] = $this->checkProjectName($config);
    $results[] = $this->checkDrupalRoot($config, $projectRoot);
    $results[] = $this->checkDrupalCoreDir($config, $projectRoot);
    $results[] = $this->checkDeployRepoUrl($config);
    $results[] = $this->checkSyncDefaultSource($config);
    $results[] = $this->checkGitRepository($config, $projectRoot);
    array_push($results, ...$this->checkUnknownConfigKeys($loader));
    array_push($results, ...$this->checkConfigTypes($loader));
    array_push($results, ...$this->checkSyncAliases($config));
    array_push($results, ...$this->checkQualityTooling($projectRoot));

    return $results;
  }

  /**
   * Checks that suds.yml exists in the project root.
   *
   * @param \Bounteous\Suds\Config\ConfigLoaderInterface $loader
   *   The config loader.
   *
   * @return array{status: string, label: string, message: string, hint?: string}
   *   The check result.
   */
  private function checkSudsYmlExists(ConfigLoaderInterface $loader): array {
    if ($loader->hasProjectConfig()) {
      return ['status' => self::PASS, 'label' => 'suds.yml', 'message' => 'Found'];
    }
    return [
      'status'  => self::WARN,
      'label'   => 'suds.yml',
      'message' => 'Not found',
      'hint'    => 'Run `drush suds:init` to create one',
    ];
  }

  /**
   * Checks that project.name is set in configuration.
   *
   * @param array<string, mixed> $config
   *   The resolved project configuration.
   *
   * @return array{status: string, label: string, message: string, hint?: string}
   *   The check result.
   */
  private function checkProjectName(array $config): array {
    return empty($config['project']['name'])
      ? [
        'status'  => self::WARN,
        'label'   => 'project.name',
        'message' => 'Not set in suds.yml',
        'hint'    => 'Add `project: {name: My Project}` to suds.yml',
      ]
      : ['status' => self::PASS, 'label' => 'project.name', 'message' => $config['project']['name']];
  }

  /**
   * Checks that the configured drupal.root directory exists on disk.
   *
   * @param array<string, mixed> $config
   *   The resolved project configuration.
   * @param string $projectRoot
   *   Absolute path to the project root directory.
   *
   * @return array{status: string, label: string, message: string, hint?: string}
   *   The check result.
   */
  private function checkDrupalRoot(array $config, string $projectRoot): array {
    $configured = $config['drupal']['root'];
    $absolute   = $projectRoot . '/' . $configured;

    return is_dir($absolute)
      ? ['status' => self::PASS, 'label' => 'drupal.root', 'message' => $configured]
      : [
        'status'  => self::FAIL,
        'label'   => 'drupal.root',
        'message' => sprintf('Directory "%s" does not exist (configured as "%s")', $absolute, $configured),
        'hint'    => 'Run `composer install` to install dependencies, or check `drupal.root` in suds.yml',
      ];
  }

  /**
   * Checks that the Drupal core directory exists inside the configured root.
   *
   * Only produces a FAIL when the root directory exists but core/ is absent,
   * which indicates a directory that is not a Drupal installation. When the
   * root itself is missing, checkDrupalRoot() already reports the failure and
   * this check passes silently to avoid duplicate noise.
   *
   * @param array<string, mixed> $config
   *   The resolved project configuration.
   * @param string $projectRoot
   *   Absolute path to the project root directory.
   *
   * @return array{status: string, label: string, message: string, hint?: string}
   *   The check result.
   */
  private function checkDrupalCoreDir(array $config, string $projectRoot): array {
    $root = $projectRoot . '/' . $config['drupal']['root'];
    if (!is_dir($root)) {
      return ['status' => self::PASS, 'label' => 'drupal.root/core', 'message' => 'Skipped (drupal.root missing)'];
    }

    $coreDir = $root . '/core';
    return is_dir($coreDir)
      ? ['status' => self::PASS, 'label' => 'drupal.root/core', 'message' => 'Found']
      : [
        'status'  => self::FAIL,
        'label'   => 'drupal.root/core',
        'message' => sprintf('"%s" exists but "%s/core" does not — not a Drupal installation', $root, $root),
        'hint'    => 'Run `composer install` to install Drupal, or check that `drupal.root` points to the correct directory',
      ];
  }

  /**
   * Checks that deploy.repo.url is set when git is available.
   *
   * Only warns when git is found on the system, suggesting deploy is intended
   * but not yet configured. Skips the check silently when git is absent.
   *
   * @param array<string, mixed> $config
   *   The resolved project configuration.
   *
   * @return array{status: string, label: string, message: string, hint?: string}
   *   The check result.
   */
  private function checkDeployRepoUrl(array $config): array {
    $gitPath = $this->findExecutable('git');
    if (!$gitPath) {
      return ['status' => self::PASS, 'label' => 'deploy.repo.url', 'message' => 'Skipped (git not found)'];
    }

    $url = $config['deploy']['repo']['url'] ?? '';
    return !empty($url)
      ? ['status' => self::PASS, 'label' => 'deploy.repo.url', 'message' => $url]
      : [
        'status'  => self::WARN,
        'label'   => 'deploy.repo.url',
        'message' => 'Not set — required for suds:deploy',
        'hint'    => 'Set `deploy.repo.url` in suds.yml',
      ];
  }

  /**
   * Checks that at least one sync source default is configured.
   *
   * Warns when all three source defaults (sync.default_source,
   * sync.db.default_source, sync.files.default_source) are null, meaning
   * suds:sync will require an explicit source alias on every invocation.
   *
   * @param array<string, mixed> $config
   *   The resolved project configuration.
   *
   * @return array{status: string, label: string, message: string, hint?: string}
   *   The check result.
   */
  private function checkSyncDefaultSource(array $config): array {
    $global = $config['sync']['default_source'] ?? NULL;
    $db     = $config['sync']['db']['default_source'] ?? NULL;
    $files  = $config['sync']['files']['default_source'] ?? NULL;

    if (empty($global) && empty($db) && empty($files)) {
      return [
        'status'  => self::WARN,
        'label'   => 'sync.default_source',
        'message' => 'Not set — suds:sync will require an explicit source alias on every call',
        'hint'    => "Set `sync.default_source: '@prod'` in suds.yml",
      ];
    }

    $source = $global ?? ($db ?? $files);
    return ['status' => self::PASS, 'label' => 'sync.default_source', 'message' => (string) $source];
  }

  /**
   * Returns TRUE when the given Drush alias is defined in alias files.
   *
   * Resolves the alias through Drush's own SiteAliasManager, which reads the
   * same local alias files `drush site:alias` would. No subprocess and no
   * network connection.
   *
   * Uses get() rather than getAlias(): both resolve '@name' identically, but
   * get() is the one documented to return FALSE for an unknown alias.
   *
   * @param string $alias
   *   The Drush alias to check, e.g. '@prod'.
   *
   * @return bool
   *   TRUE if the alias is defined, FALSE otherwise.
   */
  protected function drushAliasExists(string $alias): bool {
    return $this->siteAliasManager()->get($alias) !== FALSE;
  }

  /**
   * Checks that each configured Drush sync alias is defined in alias files.
   *
   * Collects unique non-null values from sync.default_source,
   * sync.db.default_source, and sync.files.default_source. Returns one
   * result per alias: PASS when defined, WARN when not.
   *
   * @param array<string, mixed> $config
   *   The resolved project configuration.
   *
   * @return array<int, array{status: string, label: string, message: string, hint?: string}>
   *   One result per unique configured alias, or empty when none are set.
   */
  private function checkSyncAliases(array $config): array {
    $sources = array_values(array_filter(
      array_unique([
        $config['sync']['default_source'] ?? NULL,
        $config['sync']['db']['default_source'] ?? NULL,
        $config['sync']['files']['default_source'] ?? NULL,
      ]),
      static fn (mixed $v) => $v !== NULL && $v !== '',
    ));

    if (empty($sources)) {
      return [];
    }

    $results = [];
    foreach ($sources as $alias) {
      if ($this->drushAliasExists((string) $alias)) {
        $results[] = ['status' => self::PASS, 'label' => (string) $alias, 'message' => 'Alias defined'];
      }
      else {
        $results[] = [
          'status'  => self::WARN,
          'label'   => (string) $alias,
          'message' => sprintf('Alias "%s" is not defined', $alias),
          'hint'    => 'Run `drush site:alias` to list available aliases',
        ];
      }
    }
    return $results;
  }

  /**
   * Returns check results for config value type errors.
   *
   * Produces a single PASS when all types are correct, or one WARN entry per
   * type mismatch.
   *
   * @param \Bounteous\Suds\Config\ConfigLoaderInterface $loader
   *   The config loader.
   *
   * @return array<int, array{status: string, label: string, message: string, hint?: string}>
   *   One WARN entry per type error, or a single PASS when all are correct.
   */
  private function checkConfigTypes(ConfigLoaderInterface $loader): array {
    $errors = $loader->getTypeErrors();
    if (empty($errors)) {
      return [['status' => self::PASS, 'label' => 'config types', 'message' => 'All correct']];
    }
    $results = [];
    foreach ($errors as $entry) {
      $results[] = [
        'status'  => self::WARN,
        'label'   => 'config types',
        'message' => sprintf(
          'Key "%s" in %s: expected %s, got %s',
          $entry['key'],
          $entry['file'],
          $entry['expected'],
          $entry['actual'],
        ),
        'hint'    => 'Run `drush suds:config:dump --defaults` to see expected types',
      ];
    }
    return $results;
  }

  /**
   * Returns check results for unknown keys found in user config files.
   *
   * Produces a single PASS when all keys are recognised, or one WARN entry
   * per unrecognised key so users can spot typos before running commands.
   *
   * @param \Bounteous\Suds\Config\ConfigLoaderInterface $loader
   *   The config loader.
   *
   * @return array<int, array{status: string, label: string, message: string, hint?: string}>
   *   One WARN entry per unrecognised key, or a single PASS when all valid.
   */
  private function checkUnknownConfigKeys(ConfigLoaderInterface $loader): array {
    $unknown = $loader->getUnknownKeys();
    if (empty($unknown)) {
      return [['status' => self::PASS, 'label' => 'config keys', 'message' => 'All recognized']];
    }
    $results = [];
    foreach ($unknown as $entry) {
      $results[] = [
        'status'  => self::WARN,
        'label'   => 'config keys',
        'message' => sprintf('Unknown key "%s" in %s', $entry['key'], $entry['file']),
        'hint'    => 'Run `drush suds:config:dump --defaults` to see all valid keys',
      ];
    }
    return $results;
  }

  /**
   * Returns TRUE when the given directory is inside a git repository.
   *
   * Walks up the directory tree from $dir looking for a .git directory,
   * mirroring git's own behaviour. Returns FALSE when none is found.
   *
   * @param string $dir
   *   The directory to check.
   *
   * @return bool
   *   TRUE if inside a git repository, FALSE otherwise.
   */
  protected function isGitRepository(string $dir): bool {
    return $this->findGitDir($dir) !== NULL;
  }

  /**
   * Checks that the project root is inside a git repository when deploy is set.
   *
   * Only runs when deploy.repo.url is configured and git is available. A
   * missing .git directory would cause suds:deploy to fail when trying to
   * read the current branch name and HEAD hash.
   *
   * @param array<string, mixed> $config
   *   The resolved project configuration.
   * @param string $projectRoot
   *   Absolute path to the project root directory.
   *
   * @return array{status: string, label: string, message: string, hint?: string}
   *   The check result.
   */
  private function checkGitRepository(array $config, string $projectRoot): array {
    $url     = $config['deploy']['repo']['url'] ?? '';
    $gitPath = $this->findExecutable('git');

    if (empty($url) || !$gitPath) {
      return [
        'status'  => self::PASS,
        'label'   => 'git repository',
        'message' => empty($url) ? 'Skipped (no deploy repo configured)' : 'Skipped (git not found)',
      ];
    }

    if ($this->isGitRepository($projectRoot)) {
      return ['status' => self::PASS, 'label' => 'git repository', 'message' => 'Found'];
    }

    return [
      'status'  => self::WARN,
      'label'   => 'git repository',
      'message' => 'Project root is not inside a git repository — suds:deploy will fail',
      'hint'    => 'Run `git init` to initialise a repository, or check that you are in the correct directory',
    ];
  }

  /**
   * Checks whether quality tooling config files are present.
   *
   * Warns when any of grumphp.yml, phpcs.xml.dist, or phpstan.neon are absent
   * and when grumphp.yml is present but the GrumPHP pre-commit hook has not
   * been installed in the project's git repository.
   *
   * @param string $projectRoot
   *   Absolute path to the project root directory.
   *
   * @return array<int, array{status: string, label: string, message: string, hint?: string}>
   *   One result per quality file, plus an optional hook check result.
   */
  private function checkQualityTooling(string $projectRoot): array {
    $results = [];

    foreach (['grumphp.yml', 'phpcs.xml.dist', 'phpstan.neon'] as $file) {
      $results[] = file_exists($projectRoot . '/' . $file)
        ? ['status' => self::PASS, 'label' => $file, 'message' => 'Found']
        : [
          'status'  => self::WARN,
          'label'   => $file,
          'message' => 'Not found',
          'hint'    => 'Run `drush suds:scaffold:quality` to create it',
        ];
    }

    // Only check the GrumPHP hook when grumphp.yml exists and we are in a git
    // repository. When either condition is absent the hook cannot be installed.
    if (!file_exists($projectRoot . '/grumphp.yml')) {
      return $results;
    }

    $gitDir = $this->findGitDir($projectRoot);
    if ($gitDir === NULL) {
      return $results;
    }

    $hookFile = $gitDir . '/hooks/pre-commit';
    $results[] = file_exists($hookFile)
      ? ['status' => self::PASS, 'label' => 'grumphp hook', 'message' => 'pre-commit hook installed']
      : [
        'status'  => self::WARN,
        'label'   => 'grumphp hook',
        'message' => 'pre-commit hook not installed',
        'hint'    => 'Run `composer install` to let GrumPHP auto-install hooks',
      ];

    return $results;
  }

  /**
   * Returns the path to the nearest .git directory, walking up from $dir.
   *
   * Extracted as a protected method so integration-test subclasses can
   * override it to inject a controlled git directory path.
   *
   * @param string $dir
   *   The directory to start searching from.
   *
   * @return string|null
   *   Absolute path to the .git directory, or NULL if none is found.
   */
  protected function findGitDir(string $dir): ?string {
    $current = $dir;
    while ($current !== dirname($current)) {
      if (is_dir($current . '/.git')) {
        return $current . '/.git';
      }
      $current = dirname($current);
    }
    return NULL;
  }

  /**
   * Renders check results to the console.
   *
   * @param array<int, array{status: string, label: string, message: string, hint?: string}> $results
   *   Check results to render.
   */
  private function renderResults(array $results): void {
    foreach ($results as $result) {
      $line = sprintf('[%-4s] %-20s %s', $result['status'], $result['label'], $result['message']);
      match ($result['status']) {
        self::FAIL => $this->io()->error($line),
        self::WARN => $this->io()->warning($line),
        default    => $this->io()->writeln(" <info>$line</info>"),
      };
      if (!empty($result['hint'])) {
        $this->io()->writeln(sprintf('         <comment>→ %s</comment>', $result['hint']));
      }
    }
    $this->io()->newLine();
  }

}
