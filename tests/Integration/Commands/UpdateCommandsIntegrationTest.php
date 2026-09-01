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
    $dispatched = [];
    $command = $this->buildDispatchRecordingCommand($dispatched);

    $tester = $this->buildTester($command);
    $tester->run(['command' => 'suds:update']);

    $this->assertSame(
      ['cache:rebuild', 'updatedb', 'config:import', 'config:import'],
      $dispatched,
    );
  }

  /**
   * Tests that a mismatched site UUID fails the command by default.
   */
  public function testUpdateFailsOnMismatchedSiteUuidByDefault(): void {
    $dispatched = [];
    $command = $this->buildDispatchRecordingCommand($dispatched);
    $command->setSiteUuids('sync-uuid', 'active-uuid');

    $tester = $this->buildTester($command);
    $exitCode = $tester->run(['command' => 'suds:update']);

    $this->assertNotSame(0, $exitCode);
    // The check runs before updatedb, so the database is never touched.
    $this->assertSame(['cache:rebuild'], $dispatched);
  }

  /**
   * Tests that --reconcile-site-uuid dispatches config:set before importing.
   */
  public function testUpdateReconcilesSiteUuidWhenFlagPassed(): void {
    $dispatched = [];
    $command = $this->buildDispatchRecordingCommand($dispatched);
    $command->setSiteUuids('sync-uuid', 'active-uuid');

    $tester = $this->buildTester($command);
    $exitCode = $tester->run([
      'command' => 'suds:update',
      '--reconcile-site-uuid' => TRUE,
    ]);

    $this->assertSame(0, $exitCode);
    $this->assertSame(
      ['cache:rebuild', 'config:set', 'updatedb', 'config:import', 'config:import'],
      $dispatched,
    );
  }

  /**
   * Builds a command that records every dispatched Drush sub-command name.
   *
   * @param list<string> $dispatched
   *   Reference populated with dispatched command names, in call order.
   *
   * @return \Bounteous\Suds\Tests\Integration\Commands\TestableUpdateCommands
   *   The configured command instance.
   */
  private function buildDispatchRecordingCommand(array &$dispatched): TestableUpdateCommands {
    $command = new TestableUpdateCommands();
    $this->injectUpdateMocks($command, $dispatched);
    return $command;
  }

  /**
   * Builds a mock ConfigLoaderInterface with update hook config.
   *
   * @param list<string> $preUpdate
   *   Commands for update.hooks.pre_update.
   * @param list<string> $postUpdate
   *   Commands for update.hooks.post_update.
   *
   * @return \Bounteous\Suds\Config\ConfigLoaderInterface
   *   A mock config loader.
   */
  private function makeConfigLoader(array $preUpdate = [], array $postUpdate = []): ConfigLoaderInterface {
    $mockLoader = $this->createMock(ConfigLoaderInterface::class);
    $mockLoader->method('getProjectRoot')->willReturn('/tmp');
    $mockLoader->method('load')->willReturn([
      'update' => [
        'reconcile_site_uuid' => FALSE,
        'hooks' => ['pre_update' => $preUpdate, 'post_update' => $postUpdate],
      ],
    ]);
    return $mockLoader;
  }

  /**
   * Tests that pre_update hooks run as shell commands before sub-commands.
   *
   * Verifies that commands listed in update.hooks.pre_update are passed
   * to runShellCommand() in order.
   */
  public function testUpdateRunsPreUpdateHooks(): void {
    $command = new TestableUpdateCommands();
    $this->injectUpdateMocks($command);
    $command->setConfigLoader($this->makeConfigLoader(['echo before-1', 'echo before-2']));

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
    $command = new TestableUpdateCommands();
    $this->injectUpdateMocks($command);
    $command->setConfigLoader($this->makeConfigLoader([], ['echo after-1', 'echo after-2']));

    $tester = $this->buildTester($command);
    $tester->run(['command' => 'suds:update']);

    $this->assertSame(['echo after-1', 'echo after-2'], $command->getRanShellCommands());
  }

  /**
   * Injects mocked ProcessManager and SiteAliasManager into a command instance.
   *
   * @param \Bounteous\Suds\Drush\Commands\UpdateCommands $command
   *   The command instance to configure.
   * @param list<string>|null $dispatched
   *   When passed, populated with each dispatched Drush sub-command name in
   *   call order.
   *
   * @param-out list<string> $dispatched
   */
  private function injectUpdateMocks(UpdateCommands $command, ?array &$dispatched = NULL): void {
    $dispatched ??= [];
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
    $mockProcessManager->method('drush')
      ->willReturnCallback(
        static function (mixed $alias, string $cmd) use ($mockProcess, &$dispatched): ProcessBase {
          $dispatched[] = $cmd;
          return $mockProcess;
        }
      );

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
   * UUID returned from getConfigSyncUuid(); NULL skips the UUID check.
   *
   * @var string|null
   */
  private ?string $syncUuid = NULL;

  /**
   * UUID returned from getActiveSiteUuid().
   *
   * @var string
   */
  private string $activeUuid = 'active-uuid';

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
   * Sets the UUIDs the overridden getters return.
   *
   * @param string|null $syncUuid
   *   Config sync UUID, or NULL to simulate nothing exported.
   * @param string $activeUuid
   *   Active site UUID.
   */
  public function setSiteUuids(?string $syncUuid, string $activeUuid): void {
    $this->syncUuid   = $syncUuid;
    $this->activeUuid = $activeUuid;
  }

  /**
   * {@inheritdoc}
   *
   * Returns the injected UUID instead of dispatching `drush status` and
   * reading system.site.yml off disk.
   */
  protected function getConfigSyncUuid(SiteAlias $alias, array $opts): ?string {
    return $this->syncUuid;
  }

  /**
   * {@inheritdoc}
   *
   * Returns the injected UUID instead of dispatching `drush config:get`.
   */
  protected function getActiveSiteUuid(SiteAlias $alias, array $opts): string {
    return $this->activeUuid;
  }

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
   * Records the command instead of spawning a real subprocess.
   */
  protected function runShellCommand(string $cmd, string $cwd): void {
    $this->ranShellCommands[] = $cmd;
  }

}
