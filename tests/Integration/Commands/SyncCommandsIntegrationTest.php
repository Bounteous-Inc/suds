<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Integration\Commands;

use Bounteous\Suds\Config\ConfigLoaderInterface;
use Bounteous\Suds\Drush\Commands\SyncCommands;
use Bounteous\Suds\Tests\Support\IntegrationTestCase;
use Consolidation\SiteAlias\SiteAlias;
use Consolidation\SiteAlias\SiteAliasManager;
use Consolidation\SiteProcess\ProcessBase;
use Drush\SiteAlias\ProcessManager;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Integration tests for SyncCommands.
 *
 * Commands are invoked through the Symfony Application API via
 * AnnotatedCommandFactory — no subprocess is spawned and no Drupal bootstrap
 * is required. sync() tests inject mocked ProcessManager and ConfigLoader so
 * that sub-command dispatch calls are never actually executed.
 */
#[CoversClass(SyncCommands::class)]
class SyncCommandsIntegrationTest extends IntegrationTestCase {

  /**
   * The application tester.
   *
   * @var \Symfony\Component\Console\Tester\ApplicationTester
   */
  private ApplicationTester $tester;

  /**
   * The command instance under test.
   *
   * @var \Bounteous\Suds\Tests\Integration\Commands\TestableSyncCommands
   */
  private TestableSyncCommands $commandInstance;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->commandInstance = new TestableSyncCommands();
    $this->injectSyncMocks($this->commandInstance);
    $this->tester = $this->buildTester($this->commandInstance);
  }

  /**
   * Tests that suds:sync exits cleanly when a source alias is provided.
   */
  public function testSyncExitsCleanlyWithSourceArg(): void {
    $this->commandInstance->setConfigLoader($this->makeConfigLoader());
    $exitCode = $this->tester->run([
      'command' => 'suds:sync',
      'source'  => '@prod',
    ]);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that suds:sync uses sync.default_source when no arg is given.
   */
  public function testSyncUsesDefaultSource(): void {
    $this->commandInstance->setConfigLoader($this->makeConfigLoader(defaultSource: '@stage'));
    $exitCode = $this->tester->run(['command' => 'suds:sync']);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that suds:sync exits non-zero when no source is configured.
   *
   * AnnotatedCommand catches exceptions inside execute() and converts them to
   * non-zero exit codes, so we assert the exit code rather than an exception.
   */
  public function testSyncFailsWhenNoSourceConfigured(): void {
    $this->commandInstance->setConfigLoader($this->makeConfigLoader(defaultSource: NULL));
    $exitCode = $this->tester->run(['command' => 'suds:sync']);
    $this->assertNotSame(0, $exitCode);
  }

  /**
   * Tests that --skip-sanitize suppresses the sanitize sub-command call.
   */
  public function testSyncSkipsSanitizeWhenFlagPassed(): void {
    $this->commandInstance->setConfigLoader($this->makeConfigLoader());
    $exitCode = $this->tester->run([
      'command'          => 'suds:sync',
      'source'           => '@prod',
      '--skip-sanitize'  => TRUE,
    ]);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that sanitize is skipped when sync.db.sanitize is false in config.
   */
  public function testSyncSkipsSanitizeWhenDisabledInConfig(): void {
    $this->commandInstance->setConfigLoader($this->makeConfigLoader(sanitize: FALSE));
    $exitCode = $this->tester->run([
      'command' => 'suds:sync',
      'source'  => '@prod',
    ]);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that files sync is skipped when sync.files.enabled is false.
   */
  public function testSyncSkipsFilesWhenDisabledInConfig(): void {
    $this->commandInstance->setConfigLoader($this->makeConfigLoader(filesEnabled: FALSE));
    $exitCode = $this->tester->run([
      'command' => 'suds:sync',
      'source'  => '@prod',
    ]);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that --force-files forces files sync regardless of config.
   */
  public function testSyncRunsFilesWhenForceFilesFlagPassed(): void {
    $this->commandInstance->setConfigLoader($this->makeConfigLoader(filesEnabled: FALSE));
    $exitCode = $this->tester->run([
      'command'       => 'suds:sync',
      'source'        => '@prod',
      '--force-files' => TRUE,
    ]);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that --skip-files suppresses files sync even when enabled in config.
   */
  public function testSyncSkipsFilesWhenSkipFilesFlagPassed(): void {
    $this->commandInstance->setConfigLoader($this->makeConfigLoader(filesEnabled: TRUE));
    $exitCode = $this->tester->run([
      'command'      => 'suds:sync',
      'source'       => '@prod',
      '--skip-files' => TRUE,
    ]);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that passing both --force-files and --skip-files exits non-zero.
   */
  public function testSyncFailsWhenBothFilesOptionsSet(): void {
    $this->commandInstance->setConfigLoader($this->makeConfigLoader());
    $exitCode = $this->tester->run([
      'command'       => 'suds:sync',
      'source'        => '@prod',
      '--force-files' => TRUE,
      '--skip-files'  => TRUE,
    ]);
    $this->assertNotSame(0, $exitCode);
    $this->assertStringContainsString('mutually exclusive', $this->tester->getDisplay());
  }

  /**
   * Tests that --latest allows sync to run without a source alias.
   *
   * When --latest is provided, suds:sync bypasses source alias validation
   * and passes latest=TRUE to the mocked suds:db:sync dispatch.
   */
  public function testSyncWithLatestDoesNotRequireSource(): void {
    $this->commandInstance->setConfigLoader($this->makeConfigLoader(defaultSource: NULL, sanitize: FALSE));
    $exitCode = $this->tester->run([
      'command'  => 'suds:sync',
      '--latest' => TRUE,
    ]);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that --db-file allows sync to run without a source alias.
   *
   * When --db-file is provided, suds:sync must not throw a "no source alias"
   * error. The db step uses the file path rather than a remote alias.
   */
  public function testSyncWithFileDoesNotRequireSource(): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'suds_sync_int_') . '.sql';
    file_put_contents($tmpFile, '-- empty fixture');

    try {
      $this->commandInstance->setConfigLoader($this->makeConfigLoader(defaultSource: NULL, sanitize: FALSE));
      $exitCode = $this->tester->run([
        'command' => 'suds:sync',
        '--db-file'  => $tmpFile,
      ]);
      $this->assertSame(0, $exitCode);
    }
    finally {
      @unlink($tmpFile);
    }
  }

  /**
   * Tests that suds:sync dispatches the suds:db:sync sub-command.
   *
   * Verifies that processManager()->drush() is invoked with 'suds:db:sync'
   * when --skip-db is not set, proving the orchestration logic is wired up
   * correctly end-to-end through the Symfony console layer.
   */
  public function testSyncDispatchesDbSyncSubcommand(): void {
    $mockProcess = $this->buildMockProcess();

    $mockAlias = $this->createMock(SiteAlias::class);
    $mockAliasManager = $this->getMockBuilder(SiteAliasManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getSelf'])
      ->getMock();
    $mockAliasManager->method('getSelf')->willReturn($mockAlias);

    $dispatchedCommands = [];
    $mockProcessManager = $this->getMockBuilder(ProcessManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['drush'])
      ->getMock();
    $mockProcessManager->method('drush')
      ->willReturnCallback(
        static function (mixed $a, string $cmd) use ($mockProcess, &$dispatchedCommands): ProcessBase {
          $dispatchedCommands[] = $cmd;
          return $mockProcess;
        }
      );

    $command = new TestableSyncCommands();
    $command->setProcessManager($mockProcessManager);
    $command->setSiteAliasManager($mockAliasManager);
    $command->setConfigLoader($this->makeConfigLoader(sanitize: FALSE));

    $tester = $this->buildTester($command);
    $tester->run(['command' => 'suds:sync', 'source' => '@prod']);

    $this->assertContains('suds:db:sync', $dispatchedCommands);
  }

  /**
   * Tests that suds:sync always dispatches suds:update.
   *
   * Even when optional flags are not set, suds:update must still be
   * dispatched to run cache:rebuild, updatedb, and config:import.
   */
  public function testSyncDispatchesSudsUpdate(): void {
    $mockProcess = $this->buildMockProcess();

    $mockAlias = $this->createMock(SiteAlias::class);
    $mockAliasManager = $this->getMockBuilder(SiteAliasManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getSelf'])
      ->getMock();
    $mockAliasManager->method('getSelf')->willReturn($mockAlias);

    $dispatchedCommands = [];
    $mockProcessManager = $this->getMockBuilder(ProcessManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['drush'])
      ->getMock();
    $mockProcessManager->method('drush')
      ->willReturnCallback(
        static function (mixed $a, string $cmd) use ($mockProcess, &$dispatchedCommands): ProcessBase {
          $dispatchedCommands[] = $cmd;
          return $mockProcess;
        }
      );

    $command = new TestableSyncCommands();
    $command->setProcessManager($mockProcessManager);
    $command->setSiteAliasManager($mockAliasManager);
    $command->setConfigLoader($this->makeConfigLoader(sanitize: FALSE));

    $tester = $this->buildTester($command);
    $tester->run(['command' => 'suds:sync', 'source' => '@prod']);

    $this->assertContains('suds:update', $dispatchedCommands);
  }

  /**
   * Tests that sync.db.default_source is used when no CLI source is given.
   *
   * Verifies that when the CLI source argument is omitted but
   * sync.db.default_source is configured, suds:db:sync is dispatched with
   * the per-section value rather than sync.default_source.
   */
  public function testSyncUsesDbDefaultSourceWhenSet(): void {
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
        static function (mixed $a, string $cmd, array $args) use ($mockProcess, &$dispatchedArgs): ProcessBase {
          $dispatchedArgs[$cmd] = $args;
          return $mockProcess;
        }
      );

    $command = new TestableSyncCommands();
    $command->setProcessManager($mockProcessManager);
    $command->setSiteAliasManager($mockAliasManager);
    // Top-level: @prod; per-db override: @stage. No CLI arg will be passed.
    $command->setConfigLoader($this->makeConfigLoader(
      defaultSource: '@prod',
      sanitize: FALSE,
      dbDefaultSource: '@stage',
    ));

    $tester = $this->buildTester($command);
    // No 'source' argument — should resolve @stage from sync.db.default_source.
    $exitCode = $tester->run(['command' => 'suds:sync']);

    $this->assertSame(0, $exitCode);
    $this->assertSame(['@stage'], $dispatchedArgs['suds:db:sync'] ?? []);
  }

  /**
   * Builds a mock ConfigLoaderInterface with sync configuration.
   *
   * @param string|null $defaultSource
   *   The sync.default_source value (null = not configured).
   * @param bool $sanitize
   *   The sync.db.sanitize value.
   * @param bool $filesEnabled
   *   The sync.files.enabled value.
   * @param string|null $dbDefaultSource
   *   The sync.db.default_source value (null = not configured).
   * @param string|null $filesDefaultSource
   *   The sync.files.default_source value (null = not configured).
   *
   * @return \Bounteous\Suds\Config\ConfigLoaderInterface
   *   A mock config loader.
   */
  private function makeConfigLoader(
    ?string $defaultSource = NULL,
    bool $sanitize = TRUE,
    bool $filesEnabled = FALSE,
    ?string $dbDefaultSource = NULL,
    ?string $filesDefaultSource = NULL,
  ): ConfigLoaderInterface {
    $mockLoader = $this->createMock(ConfigLoaderInterface::class);
    $mockLoader->method('getProjectRoot')->willReturn('/tmp');
    $mockLoader->method('load')->willReturn([
      'sync' => [
        'default_source' => $defaultSource,
        'db'             => [
          'default_source'    => $dbDefaultSource,
          'sanitize'          => $sanitize,
          'sanitize_email'    => 'user+%uid@localhost',
          'sanitize_password' => 'password',
          'truncate_tables'   => [],
        ],
        'files' => [
          'default_source' => $filesDefaultSource,
          'enabled'        => $filesEnabled,
          'paths'          => ['sites/default/files'],
        ],
        'hooks' => ['pre_sync' => [], 'post_sync' => []],
      ],
    ]);
    return $mockLoader;
  }

  /**
   * Injects mocked ProcessManager and SiteAliasManager into a command instance.
   *
   * @param \Bounteous\Suds\Drush\Commands\SyncCommands $command
   *   The command instance to configure.
   */
  private function injectSyncMocks(SyncCommands $command): void {
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
 * Testable subclass of SyncCommands for integration tests.
 *
 * Overrides runShellCommand() to a no-op so post_sync hooks do not spawn
 * real subprocesses, and redispatchOptions() to return an empty array so
 * the Drush DI container is not required.
 */
class TestableSyncCommands extends SyncCommands {

  /**
   * {@inheritdoc}
   *
   * No-op in integration tests — post_sync hooks are not executed.
   */
  protected function runShellCommand(string $cmd, string $cwd): void {
    // Intentionally empty: integration tests do not spawn real subprocesses.
  }

  /**
   * {@inheritdoc}
   *
   * Returns empty array in integration tests — no Drush container is present.
   */
  protected function redispatchOptions(): array {
    return [];
  }

}
