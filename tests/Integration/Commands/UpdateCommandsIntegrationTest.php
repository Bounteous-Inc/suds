<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Integration\Commands;

use Bounteous\Suds\Config\ConfigLoaderInterface;
use Bounteous\Suds\Drush\Commands\UpdateCommands;
use Bounteous\Suds\Tests\Support\IntegrationTestCase;
use Consolidation\SiteAlias\SiteAlias;
use Consolidation\SiteAlias\SiteAliasManager;
use Consolidation\SiteProcess\ProcessBase;
use Drush\SiteAlias\ProcessManager;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Integration tests for UpdateCommands.
 *
 * Commands are invoked through the Symfony Application API via
 * AnnotatedCommandFactory — no subprocess is spawned and no Drupal bootstrap
 * is required. Mocked ProcessManager and ConfigLoader are injected so that
 * drush sub-commands are never actually executed.
 */
#[CoversClass(UpdateCommands::class)]
class UpdateCommandsIntegrationTest extends IntegrationTestCase {

  /**
   * The application tester.
   *
   * @var \Symfony\Component\Console\Tester\ApplicationTester
   */
  private ApplicationTester $tester;

  /**
   * The command instance under test.
   *
   * @var \Bounteous\Suds\Tests\Integration\Commands\TestableUpdateCommands
   */
  private TestableUpdateCommands $commandInstance;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->commandInstance = new TestableUpdateCommands();
    $this->injectUpdateMocks($this->commandInstance);
    $this->tester = $this->buildTester($this->commandInstance);
  }

  /**
   * Tests that suds:update exits cleanly.
   */
  public function testUpdateExitsCleanly(): void {
    $exitCode = $this->tester->run(['command' => 'suds:update']);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that suds:update is reachable via its su-update alias.
   */
  public function testUpdateAliasExitsCleanly(): void {
    $exitCode = $this->tester->run(['command' => 'su-update']);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that suds:update dispatches the expected sub-commands in order.
   *
   * Verifies that processManager()->drush() is invoked with cache:rebuild,
   * updatedb, and config:import twice, in that order.
   */
  public function testUpdateDispatchesExpectedSubcommandsInOrder(): void {
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

    $command = new TestableUpdateCommands();
    $command->setProcessManager($mockProcessManager);
    $command->setSiteAliasManager($mockAliasManager);

    $tester = $this->buildTester($command);
    $tester->run(['command' => 'suds:update']);

    $this->assertSame(
      ['cache:rebuild', 'updatedb', 'config:import', 'config:import'],
      $dispatchedCommands,
    );
  }

  /**
   * Tests that pre_update hooks run as shell commands before sub-commands.
   *
   * Verifies that commands listed in update.hooks.pre_update are passed
   * to runShellCommand() in order.
   */
  public function testUpdateRunsPreUpdateHooks(): void {
    $mockLoader = $this->createMock(ConfigLoaderInterface::class);
    $mockLoader->method('getProjectRoot')->willReturn('/tmp');
    $mockLoader->method('load')->willReturn([
      'update' => [
        'hooks' => [
          'pre_update'  => ['echo before-1', 'echo before-2'],
          'post_update' => [],
        ],
      ],
    ]);

    $command = new TestableUpdateCommands();
    $this->injectUpdateMocks($command);
    $command->setConfigLoader($mockLoader);

    $tester = $this->buildTester($command);
    $tester->run(['command' => 'suds:update']);

    $this->assertSame(['echo before-1', 'echo before-2'], $command->getRanShellCommands());
  }

  /**
   * Tests that post_update hooks run as shell commands after sub-commands.
   *
   * Verifies that commands listed in update.hooks.post_update are passed
   * to runShellCommand() in order.
   */
  public function testUpdateRunsPostUpdateHooks(): void {
    $mockLoader = $this->createMock(ConfigLoaderInterface::class);
    $mockLoader->method('getProjectRoot')->willReturn('/tmp');
    $mockLoader->method('load')->willReturn([
      'update' => [
        'hooks' => [
          'pre_update'  => [],
          'post_update' => ['echo after-1', 'echo after-2'],
        ],
      ],
    ]);

    $command = new TestableUpdateCommands();
    $this->injectUpdateMocks($command);
    $command->setConfigLoader($mockLoader);

    $tester = $this->buildTester($command);
    $tester->run(['command' => 'suds:update']);

    $this->assertSame(['echo after-1', 'echo after-2'], $command->getRanShellCommands());
  }

  /**
   * Injects mocked ProcessManager and SiteAliasManager into a command instance.
   *
   * @param \Bounteous\Suds\Drush\Commands\UpdateCommands $command
   *   The command instance to configure.
   */
  private function injectUpdateMocks(UpdateCommands $command): void {
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
 * Testable subclass of UpdateCommands for integration tests.
 *
 * Overrides redispatchOptions() to return an empty array so the Drush DI
 * container is not required, and runShellCommand() to record calls so that
 * hook execution can be asserted without spawning real subprocesses.
 */
class TestableUpdateCommands extends UpdateCommands {

  /**
   * Shell commands passed to runShellCommand(), recorded in call order.
   *
   * @var list<string>
   */
  private array $ranShellCommands = [];

  /**
   * Returns shell commands that were passed to runShellCommand().
   *
   * @return list<string>
   *   Commands in the order they were called.
   */
  public function getRanShellCommands(): array {
    return $this->ranShellCommands;
  }

  /**
   * {@inheritdoc}
   *
   * Returns empty array in integration tests — no Drush container is present.
   */
  protected function redispatchOptions(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   *
   * Records the command instead of spawning a real subprocess.
   */
  protected function runShellCommand(string $cmd, string $cwd): void {
    $this->ranShellCommands[] = $cmd;
  }

}
