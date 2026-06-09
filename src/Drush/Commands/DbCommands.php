<?php

declare(strict_types=1);

namespace Bounteous\Suds\Drush\Commands;

use Bounteous\Suds\Config\ConfigLoaderAwareTrait;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drush\Commands\DrushCommands;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;

/**
 * Drush commands for database export, sync, and sanitization.
 */
class DbCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use SiteAliasManagerAwareTrait;
  use ConfigLoaderAwareTrait;
  use ProcessHelperTrait;

  /**
   * Export the local database to a timestamped file.
   *
   * Writes a gzipped SQL dump to the directory configured by
   * sync.db.export_dir (default: db-exports/). Additional flags for the
   * underlying sql:dump call can be set once in suds.yml via
   * sync.db.dump_extra_flags, removing the need for every developer to
   * remember project-specific dump options.
   *
   * @command suds:db:export
   * @aliases su-db-export
   * @bootstrap none
   * @usage drush suds:db:export
   *   Export the local database to the configured export directory.
   */
  public function dbExport(): void {
    $loader      = $this->configLoader();
    $config      = $loader->load();
    $alias       = $this->siteAliasManager()->getSelf();
    $projectRoot = $loader->getProjectRoot();
    $exportDir   = $projectRoot . '/' . trim((string) $config['sync']['db']['export_dir'], '/');
    $extraFlags  = trim((string) ($config['sync']['db']['dump_extra_flags'] ?? ''));

    $this->io()->title('SUDS: Exporting Database');
    $this->warnConfigIssues();

    if (!is_dir($exportDir)) {
      if (!mkdir($exportDir, 0755, TRUE)) {
        throw new \RuntimeException(
          sprintf('Failed to create export directory: %s', $exportDir),
        );
      }
      $this->io()->note(sprintf('Created export directory: %s', $exportDir));
      $this->addExportDirToGitignore(
        $projectRoot,
        trim((string) $config['sync']['db']['export_dir'], '/'),
      );
    }

    // Drush appends .gz when --gzip is set, so pass the base path without the
    // suffix; the file on disk will be $outputBase . '.gz'.
    $outputBase = $exportDir . '/' . date('Y-m-d-H-i') . '.sql';

    if ($extraFlags !== '') {
      // Extra flags is a raw string (e.g. --structure-tables-list=cache_*);
      // fall back to a shell invocation so arbitrary flags can be forwarded
      // without parsing. Requires drush to be on PATH.
      $cmd = sprintf(
        'drush sql:dump --gzip --result-file=%s %s',
        escapeshellarg($outputBase),
        $extraFlags,
      );
      $this->runShellCommand($cmd, $projectRoot);
    }
    else {
      $this->runDrushCommand($alias, 'sql:dump', [], array_merge(
        $this->redispatchOptions(),
        ['gzip' => TRUE, 'result-file' => $outputBase],
      ));
    }

    $this->io()->success(sprintf('Database exported to %s', $outputBase . '.gz'));
  }

  /**
   * Sync the database from a source alias, a local file, or the latest export.
   *
   * @param string $source
   *   The source environment alias (e.g. @prod). Required unless --db-file or
   *   --latest is set.
   * @param array $options
   *   An associative array of options.
   *
   * @phpstan-param array{'db-file': string, latest: bool} $options
   *
   * @command suds:db:sync
   * @aliases su-db-sync
   * @argument source The source environment alias (e.g. @prod).
   * @bootstrap none
   * @option db-file Path to a local SQL or gzipped SQL (.sql.gz) file to import.
   * @option latest Import the most recent export from sync.db.export_dir.
   * @usage drush suds:db:sync @prod
   *   Sync the database from @prod to @self.
   * @usage drush suds:db:sync --db-file=/path/to/backup.sql.gz
   *   Drop the local database and import from a gzipped SQL backup.
   * @usage drush suds:db:sync --latest
   *   Import the most recent export from the configured export directory.
   */
  public function dbSync(
    string $source = '',
    array $options = ['db-file' => '', 'latest' => FALSE],
  ): void {
    $alias  = $this->siteAliasManager()->getSelf();
    $file   = $options['db-file'];
    $latest = $options['latest'];
    $loader = $this->configLoader();
    $config = $loader->load();

    $this->io()->title('SUDS: Syncing Database');
    $this->warnConfigIssues();

    if ($file !== '' && $latest) {
      throw new \InvalidArgumentException('--db-file and --latest are mutually exclusive.');
    }

    if ($latest) {
      $exportDir = $loader->getProjectRoot()
        . '/' . trim((string) $config['sync']['db']['export_dir'], '/');
      $file      = $this->resolveLatestExport($exportDir);
    }

    if ($file !== '') {
      if (!file_exists($file)) {
        throw new \InvalidArgumentException(sprintf('SQL file not found: %s', $file));
      }
      // Strip suds:db:sync-specific options before forwarding to sql:drop;
      // sql:drop does not accept --db-file or --latest.
      $dropOpts = array_merge(
        array_diff_key($this->redispatchOptions(), array_flip(['db-file', 'latest'])),
        ['yes' => TRUE],
      );
      $this->runDrushCommand($alias, 'sql:drop', [], $dropOpts);
      // Use sql:connect rather than sql:cli: it returns a direct connection
      // command string (e.g. `sqlite3 /path/db`) so large files are fed
      // straight to the database binary rather than going through Drush's
      // stdin buffering.
      //
      // Strip COLLATE NOCASE_UTF8 before importing. Drupal's SQLite PDO driver
      // registers this collation at connection time, but the underlying
      // database binary (sqlite3) does not, so re-importing a Drupal SQLite
      // dump fails without this substitution. Stripping is safe: all data is
      // preserved and the column sorts under SQLite's default binary collation.
      $importCmd = str_ends_with($file, '.gz')
        ? sprintf('gunzip -c %s | sed "s/ COLLATE NOCASE_UTF8//g" | $(drush sql:connect)', escapeshellarg($file))
        : sprintf('sed "s/ COLLATE NOCASE_UTF8//g" %s | $(drush sql:connect)', escapeshellarg($file));
      // Run the import from the Drupal root so that $(drush sql:connect)
      // can auto-detect the site root and any relative database paths
      // (e.g. SQLite's "default/files/.ht.sqlite") resolve correctly.
      $importCwd = $alias->hasRoot() ? $alias->root() : (string) getcwd();
      $this->runShellCommand($importCmd, $importCwd);
    }
    elseif ($source !== '') {
      $this->runDrushCommand($alias, 'sql:sync', [$source, '@self'], $this->redispatchOptions());
    }
    else {
      $top      = (string) ($config['sync']['default_source'] ?? '');
      $resolved = (string) (($config['sync']['db']['default_source'] ?? '') ?: $top);
      if ($resolved !== '') {
        $this->runDrushCommand($alias, 'sql:sync', [$resolved, '@self'], $this->redispatchOptions());
      }
      else {
        throw new \InvalidArgumentException(
          'No source alias provided and sync.db.default_source / sync.default_source is not set. '
          . 'Pass a source alias (e.g. @prod), use --db-file, or use --latest.',
        );
      }
    }

    $this->io()->success('Database synced.');
  }

  /**
   * Sanitize the local database: truncate cache tables, then scrub PII.
   *
   * @command suds:db:sanitize
   * @aliases su-db-sanitize
   * @bootstrap none
   * @usage drush suds:db:sanitize
   *   Truncate cache/flood tables and scrub PII from the local database.
   */
  public function dbSanitize(): void {
    $loader = $this->configLoader();
    $config = $loader->load();
    $alias  = $this->siteAliasManager()->getSelf();

    $this->io()->title('SUDS: Sanitizing Database');
    $this->warnConfigIssues();

    foreach ($config['sync']['db']['truncate_tables'] as $table) {
      if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $table)) {
        throw new \InvalidArgumentException(
          sprintf('Invalid table name in sync.db.truncate_tables: "%s"', $table),
        );
      }
      $this->io()->note(sprintf('Truncating table: %s', $table));
      $this->runDrushCommand(
        $alias,
        'sql:query',
        [sprintf('TRUNCATE TABLE %s', $table)],
        $this->redispatchOptions(),
      );
    }

    $sanitizeOptions = array_merge($this->redispatchOptions(), [
      'sanitize-email'    => $config['sync']['db']['sanitize_email'],
      'sanitize-password' => $config['sync']['db']['sanitize_password'],
    ]);
    $this->runDrushCommand($alias, 'sql:sanitize', [], $sanitizeOptions);

    $this->io()->success('Database sanitized.');
  }

  /**
   * Adds the export directory to .gitignore if not already present.
   *
   * Only appends when the directory is first created so that projects which
   * never use suds:db:export, or which use a custom export path, do not
   * get an unwanted .gitignore entry. Creates .gitignore if absent.
   *
   * @param string $projectRoot
   *   Absolute path to the project root.
   * @param string $relativePath
   *   The export directory path relative to the project root.
   */
  private function addExportDirToGitignore(string $projectRoot, string $relativePath): void {
    $entry         = $relativePath . '/';
    $gitignorePath = $projectRoot . '/.gitignore';
    $mark          = "\n# SUDS database exports — do not commit dump files.\n{$entry}\n";

    if (file_exists($gitignorePath)) {
      $contents = file_get_contents($gitignorePath);
      if ($contents !== FALSE && !str_contains($contents, $entry)) {
        file_put_contents($gitignorePath, $mark, FILE_APPEND);
        $this->io()->note(sprintf('Added %s to .gitignore', $entry));
      }
    }
    else {
      file_put_contents($gitignorePath, ltrim($mark));
      $this->io()->note(sprintf('Created .gitignore with %s', $entry));
    }
  }

  /**
   * Returns the path of the most recent export file in the given directory.
   *
   * @param string $exportDir
   *   Absolute path to the directory containing export files.
   *
   * @return string
   *   Absolute path to the most recently modified export file.
   *
   * @throws \RuntimeException
   *   When no export files are found in the directory.
   */
  private function resolveLatestExport(string $exportDir): string {
    $files = array_merge(
      glob($exportDir . '/*.sql.gz') ?: [],
      glob($exportDir . '/*.sql') ?: [],
    );
    if (empty($files)) {
      throw new \RuntimeException(sprintf(
        'No database exports found in %s. Run `drush suds:db:export` first.',
        $exportDir,
      ));
    }
    usort(
      $files,
      static fn (string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a),
    );
    return $files[0];
  }

}
