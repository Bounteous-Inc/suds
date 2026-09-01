<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Integration\Commands;

use Bounteous\Suds\Config\ConfigLoaderInterface;
use Bounteous\Suds\Drush\Commands\DbCommands;
use Bounteous\Suds\Tests\Support\IntegrationTestCase;
use Consolidation\SiteAlias\SiteAlias;
use Consolidation\SiteAlias\SiteAliasManager;
use Consolidation\SiteProcess\ProcessBase;
use Drush\SiteAlias\ProcessManager;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Integration tests for DbCommands.
 *
 * Commands are invoked through the Symfony Application API via
 * AnnotatedCommandFactory — no subprocess is spawned and no Drupal bootstrap
 * is required. Mocked ProcessManager and ConfigLoader are injected so that
 * sub-command dispatch and shell truncations are never actually executed.
 */
#[CoversClass(DbCommands::class)]
class DbCommandsIntegrationTest extends IntegrationTestCase {

  /**
   * The application tester.
   *
   * @var \Symfony\Component\Console\Tester\ApplicationTester
   */
  private ApplicationTester $tester;

  /**
   * The command instance under test.
   *
   * @var \Bounteous\Suds\Tests\Integration\Commands\TestableDbCommands
   */
  private TestableDbCommands $commandInstance;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->commandInstance = new TestableDbCommands();
    $this->injectDbMocks($this->commandInstance);
    $this->tester = $this->buildTester($this->commandInstance);
  }

  /**
   * Tests that suds:db:sync exits cleanly when a source alias is provided.
   */
  public function testDbSyncExitsCleanlyWithSourceArg(): void {
    $exitCode = $this->tester->run([
      'command' => 'suds:db:sync',
      'source'  => '@prod',
    ]);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests suds:db:sync exits non-zero when no source or --db-file given.
   */
  public function testDbSyncExitsNonZeroWithNoSourceAndNoFile(): void {
    $exitCode = $this->tester->run(['command' => 'suds:db:sync']);
    $this->assertNotSame(0, $exitCode);
  }

  /**
   * Tests that suds:db:sync --latest exits cleanly with an export present.
   *
   * Injects a config loader pointing to a temp directory that contains a
   * fixture .sql.gz file, so resolveLatestExport() finds it successfully.
   * runShellCommand() is stubbed so no real import runs.
   */
  public function testDbSyncWithLatestExitsCleanly(): void {
    $tmpDir    = $this->createTempDir('suds_db_latest_int_');
    $exportDir = $tmpDir . '/db-exports';
    mkdir($exportDir, 0755);
    file_put_contents($exportDir . '/2024-01-01-12-00.sql.gz', '-- fixture');

    try {
      $mockLoader = $this->createMock(ConfigLoaderInterface::class);
      $mockLoader->method('getProjectRoot')->willReturn($tmpDir);
      $mockLoader->method('load')->willReturn([
        'sync' => ['db' => ['export_dir' => 'db-exports']],
      ]);

      $this->commandInstance->setConfigLoader($mockLoader);
      $exitCode = $this->tester->run([
        'command'  => 'suds:db:sync',
        '--latest' => TRUE,
      ]);
      $this->assertSame(0, $exitCode);
    }
    finally {
      $this->removeDirectory($tmpDir);
    }
  }

  /**
   * Tests that suds:db:export exits cleanly and creates the export dir.
   *
   * RunShellCommand() is stubbed so no real sql:dump runs; we only verify
   * that the export directory is created and the command exits cleanly.
   */
  public function testDbExportExitsCleanly(): void {
    $tmpDir = $this->createTempDir('suds_db_export_int_');

    try {
      $mockLoader = $this->createMock(ConfigLoaderInterface::class);
      $mockLoader->method('getProjectRoot')->willReturn($tmpDir);
      $mockLoader->method('load')->willReturn([
        'sync' => [
          'db' => [
            'export_dir'       => 'db-exports',
            'dump_extra_flags' => '',
          ],
        ],
      ]);

      $this->commandInstance->setConfigLoader($mockLoader);
      $exitCode = $this->tester->run(['command' => 'suds:db:export']);

      $this->assertSame(0, $exitCode);
      $this->assertDirectoryExists($tmpDir . '/db-exports');
    }
    finally {
      $this->removeDirectory($tmpDir);
    }
  }

  /**
   * Tests suds:db:sync exits cleanly when --db-file points to a SQL file.
   *
   * The TestableDbCommands subclass stubs runShellCommand so no real import
   * is executed; we only verify exit code and command registration.
   */
  public function testDbSyncFromFileExitsCleanly(): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'suds_db_int_') . '.sql';
    file_put_contents($tmpFile, '-- empty fixture');

    try {
      $exitCode = $this->tester->run([
        'command' => 'suds:db:sync',
        '--db-file'  => $tmpFile,
      ]);
      $this->assertSame(0, $exitCode);
    }
    finally {
      @unlink($tmpFile);
    }
  }

  /**
   * Tests that suds:db:sync dispatches drush sql:sync.
   *
   * Verifies that processManager()->drush() is invoked with 'sql:sync' and the
   * correct [@source, @self] argument list through the Symfony console layer.
   */
  public function testDbSyncDispatchesSqlSync(): void {
    $mockProcess = $this->buildMockProcess();

    $mockAlias = $this->createMock(SiteAlias::class);
    $mockAliasManager = $this->getMockBuilder(SiteAliasManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getSelf'])
      ->getMock();
    $mockAliasManager->method('getSelf')->willReturn($mockAlias);

    $dispatchedArgs = [];
    $mockProcessManager = $this->getMockBuilder(ProcessManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['drush'])
      ->getMock();
    $mockProcessManager->method('drush')
      ->willReturnCallback(
        static function (
          mixed $a,
          string $cmd,
          array $args,
        ) use ($mockProcess, &$dispatchedArgs): ProcessBase {
          $dispatchedArgs[$cmd] = $args;
          return $mockProcess;
        }
      );

    $command = new TestableDbCommands();
    $command->setProcessManager($mockProcessManager);
    $command->setSiteAliasManager($mockAliasManager);

    $tester = $this->buildTester($command);
    $tester->run(['command' => 'suds:db:sync', 'source' => '@prod']);

    $this->assertSame(['@prod', '@self'], $dispatchedArgs['sql:sync'] ?? []);
  }

  /**
   * Tests that suds:db:sync uses sync.default_source from config.
   *
   * Injects a config loader with sync.default_source set. The command should
   * dispatch sql:sync rather than throw when called with no source arg.
   */
  public function testDbSyncUsesDefaultSourceFromConfig(): void {
    $mockLoader = $this->createMock(ConfigLoaderInterface::class);
    $mockLoader->method('load')->willReturn([
      'sync' => [
        'default_source' => '@prod',
        'db'             => ['default_source' => NULL],
      ],
    ]);

    $this->commandInstance->setConfigLoader($mockLoader);
    $exitCode = $this->tester->run(['command' => 'suds:db:sync']);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that suds:db:sanitize exits cleanly with mocked config.
   */
  public function testDbSanitizeExitsCleanly(): void {
    $this->commandInstance->setConfigLoader($this->makeConfigLoader());
    $exitCode = $this->tester->run(['command' => 'suds:db:sanitize']);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that suds:db:sanitize dispatches drush sql:sanitize.
   *
   * Verifies that processManager()->drush() is invoked with 'sql:sanitize'
   * through the Symfony console layer.
   */
  public function testDbSanitizeDispatchesSqlSanitize(): void {
    $mockProcess = $this->buildMockProcess();

    $mockAlias = $this->createMock(SiteAlias::class);
    $mockAliasManager = $this->getMockBuilder(SiteAliasManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getSelf'])
      ->getMock();
    $mockAliasManager->method('getSelf')->willReturn($mockAlias);

    $dispatchedCmds = [];
    $mockProcessManager = $this->getMockBuilder(ProcessManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['drush'])
      ->getMock();
    $mockProcessManager->method('drush')
      ->willReturnCallback(
        static function (
          mixed $a,
          string $cmd,
        ) use ($mockProcess, &$dispatchedCmds): ProcessBase {
          $dispatchedCmds[] = $cmd;
          return $mockProcess;
        }
      );

    $command = new TestableDbCommands();
    $command->setProcessManager($mockProcessManager);
    $command->setSiteAliasManager($mockAliasManager);
    $command->setConfigLoader($this->makeConfigLoader());

    $tester = $this->buildTester($command);
    $tester->run(['command' => 'suds:db:sanitize']);

    $this->assertContains('sql:sanitize', $dispatchedCmds);
  }

  /**
   * Builds a mock ConfigLoaderInterface with sanitize configuration.
   *
   * @return \Bounteous\Suds\Config\ConfigLoaderInterface
   *   A mock config loader.
   */
  private function makeConfigLoader(): ConfigLoaderInterface {
    $mockLoader = $this->createMock(ConfigLoaderInterface::class);
    $mockLoader->method('getProjectRoot')->willReturn('/tmp');
    $mockLoader->method('load')->willReturn([
      'sync' => [
        'db' => [
          'truncate_tables'   => [],
          'sanitize_email'    => 'user+%uid@localhost',
          'sanitize_password' => 'password',
        ],
      ],
    ]);
    return $mockLoader;
  }

  /**
   * Injects mocked ProcessManager and SiteAliasManager into a command instance.
   *
   * @param \Bounteous\Suds\Drush\Commands\DbCommands $command
   *   The command instance to configure.
   */
  private function injectDbMocks(DbCommands $command): void {
    $mockProcess = $this->buildMockProcess();

    $mockAlias = $this->createMock(SiteAlias::class);
    $mockAliasManager = $this->getMockBuilder(SiteAliasManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getSelf'])
      ->getMock();
    $mockAliasManager->method('getSelf')->willReturn($mockAlias);

    $mockProcessManager = $this->getMockBuilder(ProcessManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['drush'])
      ->getMock();
    $mockProcessManager->method('drush')->willReturn($mockProcess);

    $command->setProcessManager($mockProcessManager);
    $command->setSiteAliasManager($mockAliasManager);
  }

}

/**
 * Testable subclass of DbCommands for integration tests.
 *
 * Overrides redispatchOptions() to return an empty array so the Drush DI
 * container is not required. Overrides runShellCommand() to a no-op so
 * --db-file import pipelines are not executed against a real database.
 */
class TestableDbCommands extends DbCommands {

  /**
   * {@inheritdoc}
   *
   * Returns empty array in integration tests — no Drush container is present.
   */
  protected function redispatchOptions(array $except = []): array {
    return [];
  }

  /**
   * {@inheritdoc}
   *
   * No-op in integration tests — shell import is not executed.
   */
  protected function runShellCommand(string $cmd, string $cwd): void {
    // Intentionally empty: integration tests do not spawn real subprocesses.
  }

}
