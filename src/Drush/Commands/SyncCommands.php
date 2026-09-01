<?php

declare(strict_types=1);

namespace Bounteous\Suds\Drush\Commands;

use Bounteous\Suds\Config\ConfigLoaderAwareTrait;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drush\Commands\DrushCommands;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;

/**
 * Drush commands for orchestrating a full environment sync.
 */
class SyncCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use SiteAliasManagerAwareTrait;
  use ConfigLoaderAwareTrait;
  use ProcessHelperTrait;

  /**
   * Sync database and/or files from a source environment.
   *
   * Runs sync.hooks.pre_sync commands, then orchestrates composer install,
   * suds:db:sync, suds:db:sanitize, suds:files:sync, and
   * suds:update in sequence, then runs sync.hooks.post_sync commands.
   * Each step can be individually skipped via options or config toggles.
   *
   * @param string $source
   *   The source environment alias (e.g. @prod). Falls back to
   *   sync.db.default_source / sync.files.default_source, then
   *   sync.default_source when omitted. Not required when --db-file is used.
   * @param array $options
   *   An associative array of options.
   *
   * @phpstan-param array{skip-sanitize: bool, 'force-files': bool, 'skip-files': bool, 'db-file': string, latest: bool} $options
   *
   * @command suds:sync
   * @aliases su-sync
   * @argument source The source environment alias (e.g. @prod).
   * @bootstrap none
   * @option skip-sanitize Skip database sanitization regardless of config.
   * @option force-files Force files sync even when sync.files.enabled is false.
   * @option skip-files Skip files sync even when sync.files.enabled is true.
   * @option db-file Path to a local SQL or gzipped SQL file to use as the database.
   * @option latest Use the most recent export from sync.db.export_dir as the db.
   * @usage drush suds:sync @prod
   *   Sync the database from @prod (sanitize per config, no files by default).
   * @usage drush suds:sync @prod --force-files
   *   Sync database and files from @prod.
   * @usage drush suds:sync @prod --skip-sanitize
   *   Sync the database without the sanitize step.
   * @usage drush suds:sync --db-file=/path/to/backup.sql.gz
   *   Import a local database backup and run post-sync update steps.
   * @usage drush suds:sync --latest
   *   Import the most recent export from the configured export directory.
   */
  public function sync(
    string $source = '',
    array $options = [
      'skip-sanitize' => FALSE,
      'force-files'   => FALSE,
      'skip-files'    => FALSE,
      'db-file'       => '',
      'latest'        => FALSE,
    ],
  ): void {
    $loader      = $this->configLoader();
    $config      = $loader->load();
    $alias       = $this->siteAliasManager()->getSelf();
    $projectRoot = $loader->getProjectRoot();

    if ($options['force-files'] && $options['skip-files']) {
      throw new \InvalidArgumentException(
        '--force-files and --skip-files are mutually exclusive.',
      );
    }

    $doSanitize = !$options['skip-sanitize'] && $config['sync']['db']['sanitize'];
    $doFiles    = !$options['skip-files'] && ($options['force-files'] || $config['sync']['files']['enabled']);
    $file       = $options['db-file'];
    $latest     = $options['latest'];

    // Resolve per-step source aliases:
    // CLI arg > section default > top-level default.
    $top         = $config['sync']['default_source'] ?? '';
    $dbSource    = $source ?: ($config['sync']['db']['default_source'] ?? $top) ?: '';
    $filesSource = $source ?: ($config['sync']['files']['default_source'] ?? $top) ?: '';

    if (!$latest && $file === '') {
      if ($dbSource === '') {
        throw new \InvalidArgumentException(
          'No source alias provided and sync.db.default_source / sync.default_source is not set.',
        );
      }
    }
    if ($doFiles && $filesSource === '') {
      throw new \InvalidArgumentException(
        'No source alias provided and sync.files.default_source / sync.default_source is not set.',
      );
    }

    $this->io()->title('SUDS: Syncing Environment');
    $this->warnConfigIssues();

    // Pre-sync hooks.
    foreach ($config['sync']['hooks']['pre_sync'] as $cmd) {
      $this->io()->note(sprintf('Running: %s', $cmd));
      $this->runShellCommand($cmd, $projectRoot);
    }

    // Install/update Composer dependencies.
    if ($this->findExecutable('composer') === NULL) {
      throw new \RuntimeException(
        'composer not found on PATH. Install Composer and re-run, or run `drush suds:doctor` to check your environment.',
      );
    }
    $this->io()->note('Installing Composer dependencies');
    $this->runShellCommand('composer install', $projectRoot);

    // Sync database.
    $dbOpts = $this->syncSubCommandOptions();
    if ($latest) {
      $dbOpts['latest'] = TRUE;
      $this->runDrushCommand($alias, 'suds:db:sync', [], $dbOpts);
    }
    elseif ($file !== '') {
      $dbOpts['db-file'] = $file;
      $this->runDrushCommand($alias, 'suds:db:sync', [], $dbOpts);
    }
    else {
      $this->runDrushCommand($alias, 'suds:db:sync', [$dbSource], $dbOpts);
    }

    // Sanitize database.
    if ($doSanitize) {
      $this->runDrushCommand($alias, 'suds:db:sanitize', [], $this->syncSubCommandOptions());
    }

    // Sync files.
    if ($doFiles) {
      $this->runDrushCommand($alias, 'suds:files:sync', [$filesSource], $this->syncSubCommandOptions());
    }

    // Apply code updates (cache rebuild, DB updates, config import).
    $this->runDrushCommand($alias, 'suds:update', [], $this->syncSubCommandOptions());

    // Post-sync hooks.
    foreach ($config['sync']['hooks']['post_sync'] as $cmd) {
      $this->io()->note(sprintf('Running: %s', $cmd));
      $this->runShellCommand($cmd, $projectRoot);
    }

    $this->io()->success('Environment sync complete.');
  }

  /**
   * Returns redispatch options with sync-specific flags removed.
   *
   * --db-file and --latest are re-added explicitly only when dispatching
   * suds:db:sync.
   *
   * @return array<string, mixed>
   *   Filtered options suitable for passing to child drush processes.
   */
  private function syncSubCommandOptions(): array {
    return $this->redispatchOptions([
      'skip-sanitize',
      'force-files',
      'skip-files',
      'db-file',
      'latest',
    ]);
  }

}
