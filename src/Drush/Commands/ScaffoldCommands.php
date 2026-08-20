<?php

declare(strict_types=1);

namespace Bounteous\Suds\Drush\Commands;

use Bounteous\Suds\Config\ConfigLoaderAwareTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for scaffolding project configuration files.
 */
class ScaffoldCommands extends DrushCommands {

  use ConfigLoaderAwareTrait;
  use WorkingDirectoryTrait;

  /**
   * Directory containing quality tool stub files, relative to this file.
   */
  private const QUALITY_STUBS_DIR = __DIR__ . '/../../../stubs/quality';

  /**
   * Directory containing CI stub files, relative to this file.
   */
  private const CI_STUBS_DIR = __DIR__ . '/../../../stubs/ci';

  /**
   * Valid CI provider identifiers.
   *
   * @var list<string>
   */
  private const CI_PROVIDERS = ['github', 'gitlab', 'bitbucket'];

  /**
   * Maps each CI provider to its target path relative to the project root.
   *
   * @var array<string, string>
   */
  private const CI_TARGET_PATHS = [
    'github'    => '.github/workflows/ci.yml',
    'gitlab'    => '.gitlab-ci.yml',
    'bitbucket' => 'bitbucket-pipelines.yml',
  ];

  /**
   * Scaffold code quality configuration files for a SUDS-managed project.
   *
   * Creates grumphp.yml, phpcs.xml.dist, and phpstan.neon in the project
   * root, configured for a Drupal site with custom modules and themes under
   * the configured Drupal webroot. Existing files are left untouched unless
   * --force is passed.
   *
   * @param array $options
   *   An associative array of options.
   *
   * @phpstan-param array{'drupal-root': string, force: bool} $options
   *
   * @command suds:scaffold:quality
   * @aliases su-scaffold-quality
   * @bootstrap none
   * @option drupal-root Drupal webroot directory. Overrides drupal.root from suds.yml.
   * @option force Overwrite existing files.
   * @usage drush suds:scaffold:quality
   *   Scaffold quality tooling config using drupal.root from suds.yml.
   * @usage drush suds:scaffold:quality --drupal-root=docroot
   *   Scaffold using a specific Drupal webroot.
   * @usage drush suds:scaffold:quality --force
   *   Overwrite any existing quality tool config files.
   */
  public function scaffoldQuality(
    array $options = ['drupal-root' => '', 'force' => FALSE],
  ): void {
    $targetDir  = $this->getTargetDir();
    $force      = $options['force'];
    $drupalRoot = $options['drupal-root'];

    if ($drupalRoot === '') {
      $config     = $this->configLoader()->load();
      $drupalRoot = (string) ($config['drupal']['root'] ?? 'web');
    }

    $tokens = ['{{ drupal_root }}' => $drupalRoot];

    $this->io()->title('SUDS: Scaffolding Quality Tooling');

    $written = [];
    $skipped = [];

    foreach (['grumphp.yml', 'phpcs.xml.dist', 'phpstan.neon'] as $filename) {
      $this->writeStub(
        stubPath: self::QUALITY_STUBS_DIR . '/' . $filename,
        targetPath: $targetDir . '/' . $filename,
        displayName: $filename,
        tokens: $tokens,
        force: $force,
        written: $written,
        skipped: $skipped,
      );
    }

    $this->reportFiles($written, $skipped);

    if (empty($written)) {
      $this->io()->note('No files were written. All targets already exist.');
      return;
    }

    $this->io()->success('Quality tooling scaffolded.');
    $this->io()->text([
      'Next steps:',
      '  1. Require quality tooling dev dependencies:',
      '       composer require --dev phpro/grumphp squizlabs/php_codesniffer drupal/coder dealerdirect/phpcodesniffer-composer-installer phpstan/phpstan phpstan/extension-installer mglaman/phpstan-drupal phpstan/phpstan-deprecation-rules ergebnis/composer-normalize vincentlanglet/twig-cs-fixer',
      '  2. Review and customize the scaffolded configuration files.',
    ]);
  }

  /**
   * Scaffold a CI pipeline configuration for the given provider.
   *
   * Writes a pipeline file and suds.ci.yml to the project root. The
   * pipeline installs dependencies and runs GrumPHP quality checks.
   * A commented deploy block shows how to wire up suds:deploy for
   * automated artifact pushes. Existing files are left untouched unless
   * --force is passed.
   *
   * @param string $provider
   *   The CI provider: github, gitlab, or bitbucket.
   * @param array $options
   *   An associative array of options.
   *
   * @phpstan-param array{force: bool} $options
   *
   * @command suds:scaffold:ci
   * @aliases su-scaffold-ci
   * @argument provider CI provider: github, gitlab, or bitbucket.
   * @bootstrap none
   * @option force Overwrite existing files.
   * @usage drush suds:scaffold:ci github
   *   Scaffold a GitHub Actions workflow and suds.ci.yml.
   * @usage drush suds:scaffold:ci gitlab
   *   Scaffold a GitLab CI configuration and suds.ci.yml.
   * @usage drush suds:scaffold:ci bitbucket
   *   Scaffold a Bitbucket Pipelines configuration and suds.ci.yml.
   * @usage drush suds:scaffold:ci github --force
   *   Overwrite existing CI files.
   */
  public function scaffoldCi(
    string $provider,
    array $options = ['force' => FALSE],
  ): void {
    $valid = implode(', ', self::CI_PROVIDERS);
    if (!in_array($provider, self::CI_PROVIDERS, TRUE)) {
      throw new \InvalidArgumentException(
        sprintf(
          'Unknown CI provider "%s". Valid providers: %s.',
          $provider,
          $valid,
        ),
      );
    }

    $targetDir  = $this->getTargetDir();
    $force      = $options['force'];
    $phpVersion = $this->detectPhpVersion($targetDir);
    $tokens     = ['{{ php_version }}' => $phpVersion];

    $this->io()->title(
      sprintf('SUDS: Scaffolding CI for %s', ucfirst($provider)),
    );

    if (!file_exists($targetDir . '/grumphp.yml')) {
      if ($this->io()->confirm('grumphp.yml not found. Run suds:scaffold:quality now?', TRUE)) {
        $this->scaffoldQuality();
      }
      else {
        $this->logger()?->warning('grumphp.yml not found — quality checks will not run in CI.');
      }
    }

    $written = [];
    $skipped = [];

    // Write the provider-specific pipeline file.
    $relPath    = self::CI_TARGET_PATHS[$provider];
    $targetPath = $targetDir . '/' . $relPath;

    $parentDir = dirname($targetPath);
    if (!is_dir($parentDir) && !mkdir($parentDir, 0755, TRUE)) {
      throw new \RuntimeException(
        sprintf('Failed to create directory: %s', $parentDir),
      );
    }

    $this->writeStub(
      stubPath: self::CI_STUBS_DIR . '/' . $provider . '.yml',
      targetPath: $targetPath,
      displayName: $relPath,
      tokens: $tokens,
      force: $force,
      written: $written,
      skipped: $skipped,
    );

    // Write suds.ci.yml alongside the pipeline file.
    $this->writeStub(
      stubPath: self::CI_STUBS_DIR . '/suds.ci.yml',
      targetPath: $targetDir . '/suds.ci.yml',
      displayName: 'suds.ci.yml',
      tokens: [],
      force: $force,
      written: $written,
      skipped: $skipped,
    );

    $this->reportFiles($written, $skipped);

    if (empty($written)) {
      $this->io()->note('No files were written. All targets already exist.');
      return;
    }

    $this->io()->success(
      sprintf('CI scaffold complete for %s.', ucfirst($provider)),
    );
    $this->io()->text([
      'Next steps:',
      sprintf('  1. Review and customize %s.', $relPath),
      '  2. Review suds.ci.yml for CI-specific SUDS config overrides.',
      '  3. Commit both files to your repository.',
      '  4. To enable the commented deploy block, set deploy.repo.url in',
      '     suds.yml and replace @your-alias with your environment alias.',
    ]);
  }

  /**
   * Detects the PHP version from composer.json in the project root.
   *
   * Checks config.platform.php first (most precise), then require.php.
   * Falls back to '8.3' when neither is present or parseable.
   *
   * @param string $targetDir
   *   Absolute path to the project root containing composer.json.
   *
   * @return string
   *   A version string suitable for use in a CI pipeline (e.g. '8.3').
   */
  private function detectPhpVersion(string $targetDir): string {
    $composerJson = $targetDir . '/composer.json';
    if (!file_exists($composerJson)) {
      return '8.3';
    }

    $raw = file_get_contents($composerJson);
    if ($raw === FALSE) {
      return '8.3';
    }

    $data = json_decode($raw, TRUE);
    if (!is_array($data)) {
      return '8.3';
    }

    // config.platform.php (e.g. "8.2.0") is the most precise signal.
    $platform = $data['config']['platform']['php'] ?? NULL;
    if (is_string($platform) && preg_match('/^(\d+\.\d+)/', $platform, $m)) {
      return $m[1];
    }

    // require.php constraint (e.g. ">=8.1", "^8.3", "~8.2").
    $require = $data['require']['php'] ?? NULL;
    if (is_string($require) && preg_match('/(\d+\.\d+)/', $require, $m)) {
      return $m[1];
    }

    return '8.3';
  }

  /**
   * Writes a stub file with token substitution, tracking written/skipped.
   *
   * @param string $stubPath
   *   Absolute path to the source stub file.
   * @param string $targetPath
   *   Absolute path where the file should be written.
   * @param string $displayName
   *   Human-readable name shown in command output (e.g. relative path).
   * @param array<string, string> $tokens
   *   Token map for strtr() substitution.
   * @param bool $force
   *   When TRUE, overwrite existing files.
   * @param list<string> $written
   *   Accumulator for files that were written (passed by reference).
   * @param list<string> $skipped
   *   Accumulator for files that were skipped (passed by reference).
   *
   * @throws \RuntimeException
   *   When the stub cannot be read or the target cannot be written.
   */
  private function writeStub(
    string $stubPath,
    string $targetPath,
    string $displayName,
    array $tokens,
    bool $force,
    array &$written,
    array &$skipped,
  ): void {
    if (file_exists($targetPath) && !$force) {
      $skipped[] = $displayName;
      return;
    }

    $content = file_get_contents($stubPath);
    if ($content === FALSE) {
      throw new \RuntimeException(
        sprintf('Failed to read stub: %s', $stubPath),
      );
    }

    if (!empty($tokens)) {
      $content = strtr($content, $tokens);
    }

    if (file_put_contents($targetPath, $content) === FALSE) {
      throw new \RuntimeException(
        sprintf('Failed to write %s.', $targetPath),
      );
    }

    $written[] = $displayName;
  }

  /**
   * Outputs a "Created" or "Skipped" line for each file.
   *
   * @param list<string> $written
   *   Files that were written.
   * @param list<string> $skipped
   *   Files that were skipped.
   */
  private function reportFiles(array $written, array $skipped): void {
    foreach ($written as $file) {
      $this->io()->text(sprintf('  <info>Created</info> %s', $file));
    }
    foreach ($skipped as $file) {
      $this->io()->text(sprintf(
        '  <comment>Skipped</comment> %s (already exists; use --force to overwrite)',
        $file,
      ));
    }
  }

}
