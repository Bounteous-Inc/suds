<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Unit\Commands;

use Bounteous\Suds\Config\ConfigLoaderInterface;
use Bounteous\Suds\Drush\Commands\DbCommands;
use Bounteous\Suds\Tests\Support\TempDirectoryTrait;
use Consolidation\SiteAlias\SiteAlias;
use Consolidation\SiteAlias\SiteAliasManagerInterface;
use Drush\Style\DrushStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DbCommands.
 */
#[CoversClass(DbCommands::class)]
class DbCommandsTest extends TestCase {

  use TempDirectoryTrait;

  /**
   * Verifies suds:db:sync has the correct annotations.
   */
  public function testDbSyncAnnotations(): void {
    $doc = (new \ReflectionMethod(DbCommands::class, 'dbSync'))->getDocComment();
    $this->assertIsString($doc);
    $this->assertStringContainsString(' suds:db:sync', $doc);
    $this->assertStringContainsString(' su-db-sync', $doc);
    $this->assertStringContainsString('@bootstrap none', $doc);
    $this->assertStringContainsString('@option db-file', $doc);
  }

  /**
   * Verifies dbSync() throws when no source and no --db-file are provided.
   */
  public function testDbSyncThrowsWhenNoSourceAndNoFile(): void {
    $command = $this->buildCommand();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/sync\.db\.default_source/');

    $command->dbSync('');
  }

  /**
   * Verifies dbSync() uses sync.default_source from config when no source arg.
   *
   * When called with no source arg and no --db-file/--latest, dbSync() should
   * resolve the source from sync.default_source rather than throwing.
   */
  public function testDbSyncUsesTopLevelDefaultSourceFromConfig(): void {
    $loader = $this->createMock(ConfigLoaderInterface::class);
    $loader->method('load')->willReturn([
      'sync' => [
        'default_source' => '@prod',
        'db'             => ['default_source' => NULL],
      ],
    ]);

    $drushCommands = [];
    $drushArgs     = [];
    $command       = $this->buildCommand($loader, drushCommands: $drushCommands, drushArgs: $drushArgs);
    $command->dbSync('');

    $this->assertContains('sql:sync', $drushCommands);
    $this->assertSame(['@prod', '@self'], $drushArgs['sql:sync'] ?? []);
  }

  /**
   * Verifies dbSync() prefers sync.db.default_source over sync.default_source.
   *
   * When sync.db.default_source is set, it should be used instead of the
   * top-level sync.default_source.
   */
  public function testDbSyncPrefersDbDefaultSourceOverTopLevel(): void {
    $loader = $this->createMock(ConfigLoaderInterface::class);
    $loader->method('load')->willReturn([
      'sync' => [
        'default_source' => '@prod',
        'db'             => ['default_source' => '@staging'],
      ],
    ]);

    $drushCommands = [];
    $drushArgs     = [];
    $command       = $this->buildCommand($loader, drushCommands: $drushCommands, drushArgs: $drushArgs);
    $command->dbSync('');

    $this->assertContains('sql:sync', $drushCommands);
    $this->assertSame(['@staging', '@self'], $drushArgs['sql:sync'] ?? []);
  }

  /**
   * Verifies dbSync() dispatches sql:drop and imports when --db-file.
   *
   * When a --db-file option is provided, the command should drop the local
   * database via sql:drop and then import by dispatching sql:query with a
   * --file option, rather than dispatching sql:sync or shelling out.
   */
  public function testDbSyncFromFileDropsAndImports(): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'suds_db_test_') . '.sql';
    file_put_contents($tmpFile, '-- empty fixture');

    try {
      $drushCommands = [];
      $shellCommands = [];
      $drushOpts     = [];

      $command = $this->buildCommand(
        drushCommands: $drushCommands,
        shellCommands: $shellCommands,
        drushOpts: $drushOpts,
      );
      $command->dbSync('', ['db-file' => $tmpFile, 'latest' => FALSE]);

      $this->assertSame(['sql:drop', 'sql:query'], $drushCommands);
      $this->assertNotContains('sql:sync', $drushCommands);
      $this->assertArrayHasKey('file', $drushOpts['sql:query']);
      // The import must not depend on any binary resolved from PATH.
      $this->assertSame([], $shellCommands);
    }
    finally {
      @unlink($tmpFile);
    }
  }

  /**
   * Verifies the imported file has COLLATE NOCASE_UTF8 stripped.
   *
   * Drupal's SQLite driver registers that collation at connection time, but
   * the sqlite3 binary does not, so the dump must be rewritten before import.
   * The rewritten file is deleted once the import returns, so its contents are
   * captured from inside the dispatch callback.
   */
  public function testDbSyncStripsCollationFromImportedFile(): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'suds_db_collate_') . '.sql';
    file_put_contents(
      $tmpFile,
      "CREATE TABLE t (name VARCHAR(255) COLLATE NOCASE_UTF8 NOT NULL);\n",
    );

    try {
      $imported = NULL;
      $command  = $this->buildCommand();
      $command->method('runDrushCommand')
        ->willReturnCallback(
          static function (mixed $alias, string $cmd, array $args, array $opts = []) use (&$imported): void {
            if ($cmd === 'sql:query') {
              $imported = file_get_contents((string) $opts['file']);
            }
          }
        );

      $command->dbSync('', ['db-file' => $tmpFile, 'latest' => FALSE]);

      $this->assertSame(
        "CREATE TABLE t (name VARCHAR(255) NOT NULL);\n",
        $imported,
      );
    }
    finally {
      @unlink($tmpFile);
    }
  }

  /**
   * Verifies a gzipped dump is decompressed and stripped without gunzip.
   */
  public function testDbSyncStripsCollationFromGzippedFile(): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'suds_db_collate_gz_') . '.sql.gz';
    file_put_contents(
      $tmpFile,
      (string) gzencode("INSERT INTO t VALUES ('a' COLLATE NOCASE_UTF8);\n"),
    );

    try {
      $imported = NULL;
      $command  = $this->buildCommand();
      $command->method('runDrushCommand')
        ->willReturnCallback(
          static function (mixed $alias, string $cmd, array $args, array $opts = []) use (&$imported): void {
            if ($cmd === 'sql:query') {
              $imported = file_get_contents((string) $opts['file']);
            }
          }
        );

      $command->dbSync('', ['db-file' => $tmpFile, 'latest' => FALSE]);

      $this->assertSame("INSERT INTO t VALUES ('a');\n", $imported);
    }
    finally {
      @unlink($tmpFile);
    }
  }

  /**
   * Verifies an unreadable dump aborts before the database is dropped.
   *
   * Regression test: the import used to run after sql:drop, so a failure part
   * way through left the developer with an empty database and no restore.
   */
  public function testDbSyncDoesNotDropWhenDumpCannotBeRead(): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'suds_db_unreadable_') . '.sql';
    file_put_contents($tmpFile, '-- fixture');
    chmod($tmpFile, 0000);

    try {
      $drushCommands = [];
      $command = $this->buildCommand(drushCommands: $drushCommands);

      $this->expectException(\RuntimeException::class);
      try {
        $command->dbSync('', ['db-file' => $tmpFile, 'latest' => FALSE]);
      }
      finally {
        $this->assertNotContains('sql:drop', $drushCommands);
      }
    }
    finally {
      chmod($tmpFile, 0600);
      @unlink($tmpFile);
    }
  }

  /**
   * Verifies dbSync() throws when the --db-file path does not exist.
   */
  public function testDbSyncThrowsWhenFileNotFound(): void {
    $command = $this->buildCommand();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/SQL file not found/');

    $command->dbSync('', ['db-file' => '/nonexistent/path/backup.sql', 'latest' => FALSE]);
  }

  /**
   * Verifies suds:db:sanitize has the correct annotations.
   */
  public function testDbSanitizeAnnotations(): void {
    $doc = (new \ReflectionMethod(DbCommands::class, 'dbSanitize'))->getDocComment();
    $this->assertIsString($doc);
    $this->assertStringContainsString(' suds:db:sanitize', $doc);
    $this->assertStringContainsString(' su-db-sanitize', $doc);
    $this->assertStringContainsString('@bootstrap none', $doc);
  }

  /**
   * Verifies dbSync() dispatches drush sql:sync with the source and @self args.
   */
  public function testDbSyncDispatchesSqlSync(): void {
    $command = $this->buildCommand();

    $command->expects($this->once())
      ->method('runDrushCommand')
      ->with(
        $this->anything(),
        'sql:sync',
        $this->equalTo(['@prod', '@self']),
        $this->anything(),
      );

    $command->dbSync('@prod');
  }

  /**
   * Verifies dbSanitize() dispatches the correct TRUNCATE statement per table.
   */
  public function testDbSanitizeTruncatesConfiguredTables(): void {
    $command = $this->buildCommand($this->makeConfigLoader(
      truncateTables: ['cache_default', 'watchdog'],
    ));

    $capturedArgs = [];
    $command->method('runDrushCommand')
      ->willReturnCallback(
        static function (mixed $alias, string $cmd, array $args) use (&$capturedArgs): void {
          if ($cmd === 'sql:query') {
            $capturedArgs[] = $args[0];
          }
        }
      );

    $command->dbSanitize();

    $this->assertSame(
      ['TRUNCATE TABLE cache_default', 'TRUNCATE TABLE watchdog'],
      $capturedArgs,
    );
  }

  /**
   * Verifies dbSanitize() preserves the order of tables in config.
   */
  public function testDbSanitizeTruncatesInConfiguredOrder(): void {
    $command = $this->buildCommand($this->makeConfigLoader(
      truncateTables: ['table_b', 'table_a'],
    ));

    $capturedArgs = [];
    $command->method('runDrushCommand')
      ->willReturnCallback(
        static function (mixed $alias, string $cmd, array $args) use (&$capturedArgs): void {
          if ($cmd === 'sql:query') {
            $capturedArgs[] = $args[0];
          }
        }
      );

    $command->dbSanitize();

    $this->assertSame(
      ['TRUNCATE TABLE table_b', 'TRUNCATE TABLE table_a'],
      $capturedArgs,
    );
  }

  /**
   * Verifies dbSanitize() passes email and password options to sql:sanitize.
   */
  public function testDbSanitizePassesSanitizeOptionsToSqlSanitize(): void {
    $command = $this->buildCommand($this->makeConfigLoader(
      email: 'clean+%uid@example.com',
      password: 'secret',
    ));

    $sanitizeOptions = NULL;
    $command->method('runDrushCommand')
      ->willReturnCallback(
        static function (mixed $alias, string $cmd, array $args, array $opts) use (&$sanitizeOptions): void {
          if ($cmd === 'sql:sanitize') {
            $sanitizeOptions = $opts;
          }
        }
      );

    $command->dbSanitize();

    $this->assertIsArray($sanitizeOptions);
    $this->assertSame('clean+%uid@example.com', $sanitizeOptions['sanitize-email'] ?? NULL);
    $this->assertSame('secret', $sanitizeOptions['sanitize-password'] ?? NULL);
  }

  /**
   * Verifies dbSanitize() only dispatches sql:sanitize when table list empty.
   */
  public function testDbSanitizeSkipsTruncateWhenTableListIsEmpty(): void {
    $command = $this->buildCommand($this->makeConfigLoader(truncateTables: []));

    $command->expects($this->once())
      ->method('runDrushCommand')
      ->with($this->anything(), 'sql:sanitize', $this->anything(), $this->anything());

    $command->dbSanitize();
  }

  /**
   * Verifies dbSanitize() throws on an invalid table name in config.
   */
  public function testDbSanitizeThrowsOnInvalidTableName(): void {
    $command = $this->buildCommand($this->makeConfigLoader(
      truncateTables: ['valid_table', 'bad-table-name!'],
    ));

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/bad-table-name!/');

    $command->dbSanitize();
  }

  /**
   * Verifies suds:db:export has the correct annotations.
   */
  public function testDbExportAnnotations(): void {
    $doc = (new \ReflectionMethod(DbCommands::class, 'dbExport'))->getDocComment();
    $this->assertIsString($doc);
    $this->assertStringContainsString(' suds:db:export', $doc);
    $this->assertStringContainsString(' su-db-export', $doc);
    $this->assertStringContainsString('@bootstrap none', $doc);
  }

  /**
   * Verifies dbExport() creates the export directory when absent.
   */
  public function testDbExportCreatesExportDirectory(): void {
    $tmpDir    = $this->createTempDir('suds_db_export_unit_');
    $exportDir = $tmpDir . '/db-exports';

    try {
      $command = $this->buildCommand($this->makeExportConfigLoader($tmpDir));
      $command->dbExport();
      $this->assertDirectoryExists($exportDir);
    }
    finally {
      $this->removeDirectory($tmpDir);
    }
  }

  /**
   * Verifies dbExport() dispatches sql:dump with gzip and result-file options.
   */
  public function testDbExportDispatchesSqlDump(): void {
    $tmpDir = $this->createTempDir('suds_db_export_unit_');

    try {
      $drushCommands = [];
      $drushOpts     = [];
      $command       = $this->buildCommand($this->makeExportConfigLoader($tmpDir));

      $command->method('runDrushCommand')
        ->willReturnCallback(
          static function (mixed $alias, string $cmd, array $args, array $opts = []) use (&$drushCommands, &$drushOpts): void {
            $drushCommands[] = $cmd;
            $drushOpts[$cmd] = $opts;
          }
        );

      $command->dbExport();

      $this->assertContains('sql:dump', $drushCommands);
      $this->assertTrue($drushOpts['sql:dump']['gzip'] ?? FALSE);
      $this->assertArrayHasKey('result-file', $drushOpts['sql:dump']);
    }
    finally {
      $this->removeDirectory($tmpDir);
    }
  }

  /**
   * Verifies dbExport() forwards dump_extra_flags as sql:dump options.
   */
  public function testDbExportIncludesExtraFlags(): void {
    $tmpDir = $this->createTempDir('suds_db_export_unit_');

    try {
      $shellCommands = [];
      $drushOpts     = [];
      $command       = $this->buildCommand(
        $this->makeExportConfigLoader(
          $tmpDir,
          dumpExtraFlags: '--extra-dump=--single-transaction --structure-tables-list=cache_* --ordered-dump',
        ),
        shellCommands: $shellCommands,
        drushOpts: $drushOpts,
      );
      $command->dbExport();

      $this->assertSame('--single-transaction', $drushOpts['sql:dump']['extra-dump'] ?? NULL);
      $this->assertSame('cache_*', $drushOpts['sql:dump']['structure-tables-list'] ?? NULL);
      $this->assertTrue($drushOpts['sql:dump']['ordered-dump'] ?? FALSE);
      // The export must not depend on any binary resolved from PATH.
      $this->assertSame([], $shellCommands);
    }
    finally {
      $this->removeDirectory($tmpDir);
    }
  }

  /**
   * Verifies dump_extra_flags rejects anything that is not a flag.
   *
   * The value used to be interpolated into a shell string, so shell syntax
   * silently worked. It is now parsed into Drush options, and a bad token has
   * to fail loudly rather than be dropped or forwarded as a literal option.
   */
  public function testDbExportThrowsOnMalformedExtraFlags(): void {
    $tmpDir = $this->createTempDir('suds_db_export_unit_');

    try {
      $command = $this->buildCommand(
        $this->makeExportConfigLoader($tmpDir, dumpExtraFlags: '--gzip | tee /tmp/pwned'),
      );

      $this->expectException(\InvalidArgumentException::class);
      $this->expectExceptionMessage('|');
      $command->dbExport();
    }
    finally {
      $this->removeDirectory($tmpDir);
    }
  }

  /**
   * Verifies dbExport() adds the export directory to .gitignore when absent.
   */
  public function testDbExportAddsExportDirToGitignore(): void {
    $tmpDir = $this->createTempDir('suds_db_export_gitignore_');

    try {
      $command = $this->buildCommand($this->makeExportConfigLoader($tmpDir));
      $command->dbExport();

      $gitignore = file_get_contents($tmpDir . '/.gitignore');
      $this->assertIsString($gitignore);
      $this->assertStringContainsString('db-exports/', $gitignore);
    }
    finally {
      $this->removeDirectory($tmpDir);
    }
  }

  /**
   * Verifies dbExport() does not duplicate the gitignore entry on re-run.
   */
  public function testDbExportDoesNotDuplicateGitignoreEntry(): void {
    $tmpDir    = $this->createTempDir('suds_db_export_gitignore_dup_');
    $exportDir = $tmpDir . '/db-exports';
    mkdir($exportDir, 0755);
    file_put_contents($tmpDir . '/.gitignore', "db-exports/\n");

    try {
      $command = $this->buildCommand($this->makeExportConfigLoader($tmpDir));
      $command->dbExport();

      $gitignore = file_get_contents($tmpDir . '/.gitignore');
      $this->assertIsString($gitignore);
      $this->assertSame(1, substr_count($gitignore, 'db-exports/'));
    }
    finally {
      $this->removeDirectory($tmpDir);
    }
  }

  /**
   * Verifies suds:db:sync has @option latest annotation.
   */
  public function testDbSyncAnnotationsIncludeLatestOption(): void {
    $doc = (new \ReflectionMethod(DbCommands::class, 'dbSync'))->getDocComment();
    $this->assertIsString($doc);
    $this->assertStringContainsString('@option latest', $doc);
  }

  /**
   * Verifies dbSync() defaults latest to false.
   */
  public function testDbSyncDefaultOptionsIncludeLatest(): void {
    $params  = (new \ReflectionMethod(DbCommands::class, 'dbSync'))->getParameters();
    $default = $params[1]->getDefaultValue();
    $this->assertIsArray($default);
    $this->assertFalse($default['latest']);
  }

  /**
   * Verifies dbSync() throws when both --db-file and --latest are provided.
   */
  public function testDbSyncThrowsWhenBothFileAndLatestSet(): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'suds_db_test_') . '.sql';
    file_put_contents($tmpFile, '-- fixture');

    try {
      $command = $this->buildCommand();

      $this->expectException(\InvalidArgumentException::class);
      $this->expectExceptionMessageMatches('/mutually exclusive/');

      $command->dbSync('', ['db-file' => $tmpFile, 'latest' => TRUE]);
    }
    finally {
      @unlink($tmpFile);
    }
  }

  /**
   * Verifies dbSync() throws when --latest is set and the export dir is empty.
   */
  public function testDbSyncWithLatestThrowsWhenNoDumpsFound(): void {
    $tmpDir = $this->createTempDir('suds_db_latest_unit_');

    try {
      $command = $this->buildCommand($this->makeExportConfigLoader($tmpDir));

      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessageMatches('/No database exports found/');

      $command->dbSync('', ['db-file' => '', 'latest' => TRUE]);
    }
    finally {
      $this->removeDirectory($tmpDir);
    }
  }

  /**
   * Verifies dbSync() with --latest imports the most recently modified file.
   *
   * Creates two export files with distinct modification times and verifies
   * that the imported contents come from the newer one.
   */
  public function testDbSyncWithLatestResolvesNewestExport(): void {
    $tmpDir    = $this->createTempDir('suds_db_latest_unit_');
    $exportDir = $tmpDir . '/db-exports';
    mkdir($exportDir, 0755);

    $older = $exportDir . '/2024-01-01-12-00.sql.gz';
    $newer = $exportDir . '/2024-06-01-12-00.sql.gz';
    file_put_contents($older, (string) gzencode('-- older'));
    file_put_contents($newer, (string) gzencode('-- newer'));
    touch($older, time() - 3600);
    touch($newer, time());

    try {
      $imported = NULL;
      $command  = $this->buildCommand($this->makeExportConfigLoader($tmpDir));
      $command->method('runDrushCommand')
        ->willReturnCallback(
          static function (mixed $alias, string $cmd, array $args, array $opts = []) use (&$imported): void {
            if ($cmd === 'sql:query') {
              $imported = file_get_contents((string) $opts['file']);
            }
          }
        );

      $command->dbSync('', ['db-file' => '', 'latest' => TRUE]);

      $this->assertSame('-- newer', $imported);
    }
    finally {
      $this->removeDirectory($tmpDir);
    }
  }

  /**
   * Builds a DbCommands mock with standard dependencies wired up.
   *
   * @param \Bounteous\Suds\Config\ConfigLoaderInterface|null $loader
   *   Config loader; defaults to a no-op loader.
   * @param list<string> $drushCommands
   *   Receives each drush command name in call order.
   * @param array<string, list<mixed>> $drushArgs
   *   Receives positional args keyed by command name.
   * @param list<string> $shellCommands
   *   Receives each shell command string in call order.
   * @param array<string, array<string, mixed>> $drushOpts
   *   Receives options keyed by command name.
   *
   * @return \Bounteous\Suds\Drush\Commands\DbCommands&\PHPUnit\Framework\MockObject\MockObject
   *   A configured mock.
   */
  private function buildCommand(
    ?ConfigLoaderInterface $loader = NULL,
    array &$drushCommands = [],
    array &$drushArgs = [],
    array &$shellCommands = [],
    array &$drushOpts = [],
  ): DbCommands&MockObject {
    $mockAlias = $this->createMock(SiteAlias::class);
    $mockAliasManager = $this->createMock(SiteAliasManagerInterface::class);
    $mockAliasManager->method('getSelf')->willReturn($mockAlias);

    $command = $this->getMockBuilder(DbCommands::class)
      ->onlyMethods(['io', 'siteAliasManager', 'redispatchOptions', 'runDrushCommand', 'runShellCommand'])
      ->getMock();
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->method('siteAliasManager')->willReturn($mockAliasManager);
    $command->method('redispatchOptions')->willReturn([]);
    $command->method('runDrushCommand')
      ->willReturnCallback(
        static function (mixed $alias, string $cmd, array $args = [], array $opts = []) use (&$drushCommands, &$drushArgs, &$drushOpts): void {
          $drushCommands[] = $cmd;
          $drushArgs[$cmd] = $args;
          $drushOpts[$cmd] = $opts;
        }
      );
    $command->method('runShellCommand')
      ->willReturnCallback(
        static function (string $cmd) use (&$shellCommands): void {
          $shellCommands[] = $cmd;
        }
      );

    $command->setConfigLoader($loader ?? $this->makeDefaultSyncLoader());

    return $command;
  }

  /**
   * Returns a minimal config loader with null sync default sources.
   *
   * Used as the default in buildCommand() so that dbSync() always has a
   * loader available and cannot accidentally read the real suds.yml.
   *
   * @return \Bounteous\Suds\Config\ConfigLoaderInterface
   *   A mock config loader.
   */
  private function makeDefaultSyncLoader(): ConfigLoaderInterface {
    $loader = $this->createMock(ConfigLoaderInterface::class);
    $loader->method('getProjectRoot')->willReturn('/tmp');
    $loader->method('load')->willReturn([
      'sync' => [
        'default_source' => NULL,
        'db'             => [
          'default_source'   => NULL,
          'export_dir'       => 'db-exports',
          'dump_extra_flags' => '',
        ],
      ],
    ]);
    return $loader;
  }

  /**
   * Returns a config array for dbSanitize() tests.
   *
   * @param list<string> $truncateTables
   *   Table names to truncate.
   * @param string $email
   *   Email pattern for sanitization.
   * @param string $password
   *   Password value for sanitization.
   *
   * @return \Bounteous\Suds\Config\ConfigLoaderInterface
   *   A mock config loader.
   */
  private function makeConfigLoader(
    array $truncateTables = [],
    string $email = 'user+%uid@localhost',
    string $password = 'password',
  ): ConfigLoaderInterface {
    $loader = $this->createMock(ConfigLoaderInterface::class);
    $loader->method('load')->willReturn([
      'sync' => [
        'db' => [
          'truncate_tables'   => $truncateTables,
          'sanitize_email'    => $email,
          'sanitize_password' => $password,
        ],
      ],
    ]);
    return $loader;
  }

  /**
   * Returns a config loader for dbExport() and --latest tests.
   *
   * @param string $projectRoot
   *   The project root directory.
   * @param string $exportDir
   *   Relative path for the export directory.
   * @param string $dumpExtraFlags
   *   Extra flags to pass to sql:dump.
   *
   * @return \Bounteous\Suds\Config\ConfigLoaderInterface
   *   A mock config loader.
   */
  private function makeExportConfigLoader(
    string $projectRoot,
    string $exportDir = 'db-exports',
    string $dumpExtraFlags = '',
  ): ConfigLoaderInterface {
    $loader = $this->createMock(ConfigLoaderInterface::class);
    $loader->method('getProjectRoot')->willReturn($projectRoot);
    $loader->method('load')->willReturn([
      'sync' => [
        'db' => [
          'export_dir'       => $exportDir,
          'dump_extra_flags' => $dumpExtraFlags,
        ],
      ],
    ]);
    return $loader;
  }

}
