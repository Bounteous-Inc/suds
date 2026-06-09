<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Integration\Commands;

use Bounteous\Suds\Config\ConfigLoaderInterface;
use Bounteous\Suds\Drush\Commands\FilesCommands;
use Bounteous\Suds\Tests\Support\IntegrationTestCase;
use Consolidation\SiteAlias\SiteAlias;
use Consolidation\SiteAlias\SiteAliasManager;
use Consolidation\SiteProcess\ProcessBase;
use Drush\SiteAlias\ProcessManager;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Integration tests for FilesCommands.
 *
 * Commands are invoked through the Symfony Application API via
 * AnnotatedCommandFactory — no subprocess is spawned and no Drupal bootstrap
 * is required. Mocked ProcessManager and ConfigLoader are injected so that
 * rsync dispatches are never actually executed.
 */
#[CoversClass(FilesCommands::class)]
class FilesCommandsIntegrationTest extends IntegrationTestCase {

  /**
   * The application tester.
   *
   * @var \Symfony\Component\Console\Tester\ApplicationTester
   */
  private ApplicationTester $tester;

  /**
   * The command instance under test.
   *
   * @var \Bounteous\Suds\Tests\Integration\Commands\TestableFilesCommands
   */
  private TestableFilesCommands $commandInstance;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->commandInstance = new TestableFilesCommands();
    $this->injectFilesMocks($this->commandInstance);
    $this->tester = $this->buildTester($this->commandInstance);
  }

  /**
   * Tests that suds:files:sync exits cleanly when a source alias is given.
   */
  public function testFilesSyncExitsCleanlyWithSourceArg(): void {
    $this->commandInstance->setConfigLoader($this->makeConfigLoader());
    $exitCode = $this->tester->run([
      'command' => 'suds:files:sync',
      'source'  => '@prod',
    ]);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that suds:files:sync dispatches drush rsync for each path.
   *
   * Verifies that processManager()->drush() is invoked with 'rsync' once per
   * path in sync.files.paths through the Symfony console layer.
   */
  public function testFilesSyncDispatchesRsyncPerPath(): void {
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

    $command = new TestableFilesCommands();
    $command->setProcessManager($mockProcessManager);
    $command->setSiteAliasManager($mockAliasManager);
    // Two paths → two rsync dispatches.
    $command->setConfigLoader($this->makeConfigLoader([
      'sites/default/files',
      'sites/default/private',
    ]));

    $tester = $this->buildTester($command);
    $tester->run(['command' => 'suds:files:sync', 'source' => '@prod']);

    $this->assertCount(2, $dispatchedCmds);
    $this->assertSame(['rsync', 'rsync'], $dispatchedCmds);
  }

  /**
   * Tests that suds:files:sync dispatches no rsync calls for an empty list.
   */
  public function testFilesSyncSkipsDispatchWhenPathsEmpty(): void {
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
    $mockProcessManager->expects($this->never())->method('drush');

    $command = new TestableFilesCommands();
    $command->setProcessManager($mockProcessManager);
    $command->setSiteAliasManager($mockAliasManager);
    $command->setConfigLoader($this->makeConfigLoader([]));

    $tester = $this->buildTester($command);
    $exitCode = $tester->run([
      'command' => 'suds:files:sync',
      'source'  => '@prod',
    ]);

    $this->assertSame(0, $exitCode);
  }

  /**
   * Builds a mock ConfigLoaderInterface with a files paths list.
   *
   * @param list<string> $paths
   *   The sync.files.paths value.
   *
   * @return \Bounteous\Suds\Config\ConfigLoaderInterface
   *   A mock config loader.
   */
  private function makeConfigLoader(
    array $paths = ['sites/default/files'],
  ): ConfigLoaderInterface {
    $mockLoader = $this->createMock(ConfigLoaderInterface::class);
    $mockLoader->method('load')->willReturn([
      'sync' => ['files' => ['paths' => $paths]],
    ]);
    return $mockLoader;
  }

  /**
   * Injects mocked ProcessManager and SiteAliasManager into a command instance.
   *
   * @param \Bounteous\Suds\Drush\Commands\FilesCommands $command
   *   The command instance to configure.
   */
  private function injectFilesMocks(FilesCommands $command): void {
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
 * Testable subclass of FilesCommands for integration tests.
 *
 * Overrides redispatchOptions() to return an empty array so the Drush DI
 * container is not required.
 */
class TestableFilesCommands extends FilesCommands {

  /**
   * {@inheritdoc}
   *
   * Returns empty array in integration tests — no Drush container is present.
   */
  protected function redispatchOptions(): array {
    return [];
  }

}
