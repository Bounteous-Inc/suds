<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Integration\Commands;

use Bounteous\Suds\Config\ConfigLoaderInterface;
use Bounteous\Suds\Drush\Commands\DepsUpdateCommands;
use Bounteous\Suds\Tests\Support\IntegrationTestCase;
use Consolidation\SiteAlias\SiteAlias;
use Consolidation\SiteAlias\SiteAliasManager;
use Consolidation\SiteProcess\ProcessBase;
use Drush\SiteAlias\ProcessManager;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Integration tests for DepsUpdateCommands.
 *
 * Commands are invoked through the Symfony Application API via
 * AnnotatedCommandFactory — no subprocess is spawned and no Drupal bootstrap
 * is required. Mocked ProcessManager and ConfigLoader are injected so that
 * drush sub-commands and composer are never actually executed.
 */
#[CoversClass(DepsUpdateCommands::class)]
class DepsUpdateCommandsIntegrationTest extends IntegrationTestCase {

  /**
   * The application tester.
   *
   * @var \Symfony\Component\Console\Tester\ApplicationTester
   */
  private ApplicationTester $tester;

  /**
   * The command instance under test.
   *
   * @var \Bounteous\Suds\Tests\Integration\Commands\TestableDepsUpdateCommands
   */
  private TestableDepsUpdateCommands $commandInstance;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->commandInstance = new TestableDepsUpdateCommands();
    $this->injectDepsUpdateMocks($this->commandInstance);
    $this->commandInstance->setConfigLoader($this->makeConfigLoader());
    $this->tester = $this->buildTester($this->commandInstance);
  }

  /**
   * Builds a command that records every dispatched Drush sub-command name.
   *
   * @return \Bounteous\Suds\Tests\Integration\Commands\TestableDepsUpdateCommands
   *   The configured command instance. Dispatched command names are
   *   available via getDispatchedCommands() once the command has run.
   */
  private function buildDispatchRecordingCommand(): TestableDepsUpdateCommands {
    $command = new TestableDepsUpdateCommands();
    $this->injectDepsUpdateMocks($command);
    $command->setConfigLoader($this->makeConfigLoader());
    return $command;
  }

  /**
   * Tests that suds:deps-update exits cleanly.
   */
  public function testDepsUpdateExitsCleanly(): void {
    $exitCode = $this->tester->run(['command' => 'suds:deps-update']);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that suds:deps-update is reachable via its su-deps-update alias.
   */
  public function testDepsUpdateAliasExitsCleanly(): void {
    $exitCode = $this->tester->run(['command' => 'su-deps-update']);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests the composer/updatedb/cache-rebuild/config-export step order.
   */
  public function testDepsUpdateRunsExpectedStepsInOrder(): void {
    $command = $this->buildDispatchRecordingCommand();

    $tester = $this->buildTester($command);
    $tester->run(['command' => 'suds:deps-update']);

    $this->assertSame(
      ['updatedb', 'cache:rebuild', 'config:export'],
      $command->getDispatchedCommands(),
    );
    $this->assertSame(['composer update'], $command->getRanShellCommands());
  }

  /**
   * Tests that --skip-cex omits config:export.
   */
  public function testDepsUpdateSkipsConfigExportWhenFlagPassed(): void {
    $command = $this->buildDispatchRecordingCommand();

    $tester = $this->buildTester($command);
    $exitCode = $tester->run([
      'command' => 'suds:deps-update',
      '--skip-cex' => TRUE,
    ]);

    $this->assertSame(0, $exitCode);
    $this->assertSame(['updatedb', 'cache:rebuild'], $command->getDispatchedCommands());
  }

  /**
   * Tests that a missing composer binary fails the command cleanly.
   */
  public function testDepsUpdateFailsWhenComposerMissing(): void {
    $command = $this->buildDispatchRecordingCommand();
    $command->setComposerAvailable(FALSE);

    $tester = $this->buildTester($command);
    $exitCode = $tester->run(['command' => 'suds:deps-update']);

    $this->assertNotSame(0, $exitCode);
    $this->assertSame([], $command->getDispatchedCommands());
  }

  /**
   * Tests that pre/post hooks run as shell commands around the core steps.
   */
  public function testDepsUpdateRunsHooks(): void {
    $command = new TestableDepsUpdateCommands();
    $this->injectDepsUpdateMocks($command);
    $command->setConfigLoader($this->makeConfigLoader(preDepsUpdate: ['echo pre'], postDepsUpdate: ['echo post']));

    $tester = $this->buildTester($command);
    $tester->run(['command' => 'suds:deps-update']);

    $this->assertSame(
      ['echo pre', 'composer update', 'echo post'],
      $command->getRanShellCommands(),
    );
  }

  /**
   * Tests that configured groups each run as their own composer update pass.
   */
  public function testDepsUpdateRunsOneComposerUpdatePassPerConfiguredGroup(): void {
    $command = new TestableDepsUpdateCommands();
    $this->injectDepsUpdateMocks($command);
    $command->setConfigLoader($this->makeConfigLoader(groups: [['drupal/core-recommended'], ['drupal/*']]));

    $tester = $this->buildTester($command);
    $tester->run(['command' => 'suds:deps-update']);

    $this->assertSame(
      ['composer update drupal/core-recommended', 'composer update drupal/*'],
      $command->getRanShellCommands(),
    );
  }

  /**
   * Tests that a packages argument overrides configured groups entirely.
   */
  public function testDepsUpdatePackagesArgumentOverridesConfiguredGroups(): void {
    $command = new TestableDepsUpdateCommands();
    $this->injectDepsUpdateMocks($command);
    $command->setConfigLoader($this->makeConfigLoader(groups: [['drupal/core-recommended'], ['drupal/*']]));

    $tester = $this->buildTester($command);
    $tester->run(['command' => 'suds:deps-update', 'packages' => 'drupal/foo,drupal/bar']);

    $this->assertSame(
      ['composer update drupal/foo drupal/bar'],
      $command->getRanShellCommands(),
    );
  }

  /**
   * Builds a mock ConfigLoaderInterface with deps_update config.
   *
   * @param list<list<string>> $groups
   *   Value for deps_update.composer.groups.
   * @param list<string> $preDepsUpdate
   *   Commands for deps_update.hooks.pre_deps_update.
   * @param list<string> $postDepsUpdate
   *   Commands for deps_update.hooks.post_deps_update.
   *
   * @return \Bounteous\Suds\Config\ConfigLoaderInterface
   *   A mock config loader.
   */
  private function makeConfigLoader(array $groups = [], array $preDepsUpdate = [], array $postDepsUpdate = []): ConfigLoaderInterface {
    $mockLoader = $this->createMock(ConfigLoaderInterface::class);
    $mockLoader->method('getProjectRoot')->willReturn('/tmp');
    $mockLoader->method('load')->willReturn([
      'deps_update' => [
        'composer' => ['groups' => $groups],
        'skip_config_export' => FALSE,
        'hooks' => ['pre_deps_update' => $preDepsUpdate, 'post_deps_update' => $postDepsUpdate],
      ],
    ]);
    return $mockLoader;
  }

  /**
   * Injects mocked ProcessManager and SiteAliasManager into a command instance.
   *
   * @param \Bounteous\Suds\Tests\Integration\Commands\TestableDepsUpdateCommands $command
   *   The command instance to configure. Dispatched Drush sub-command names
   *   are recorded onto it, in call order, and readable via
   *   getDispatchedCommands().
   */
  private function injectDepsUpdateMocks(TestableDepsUpdateCommands $command): void {
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
        static function (mixed $alias, string $cmd) use ($mockProcess, $command): ProcessBase {
          $command->recordDispatchedCommand($cmd);
          return $mockProcess;
        }
      );

    $command->setProcessManager($mockProcessManager);
    $command->setSiteAliasManager($mockAliasManager);
  }

}

/**
 * Testable subclass of DepsUpdateCommands for integration tests.
 *
 * Overrides redispatchOptions() to return an empty array so the Drush DI
 * container is not required, findExecutable() to simulate composer
 * availability, and runShellCommand() to record calls so that composer/hook
 * execution can be asserted without spawning real subprocesses.
 */
class TestableDepsUpdateCommands extends DepsUpdateCommands {

  /**
   * Shell commands passed to runShellCommand(), recorded in call order.
   *
   * @var list<string>
   */
  private array $ranShellCommands = [];

  /**
   * Drush sub-commands dispatched via the mocked ProcessManager.
   *
   * @var list<string>
   */
  private array $dispatchedCommands = [];

  /**
   * Whether findExecutable('composer') should resolve to a path.
   *
   * @var bool
   */
  private bool $composerAvailable = TRUE;

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
   * Records a Drush sub-command dispatched via the mocked ProcessManager.
   *
   * @param string $cmd
   *   The dispatched Drush command name.
   */
  public function recordDispatchedCommand(string $cmd): void {
    $this->dispatchedCommands[] = $cmd;
  }

  /**
   * Returns Drush sub-command names dispatched via the mocked ProcessManager.
   *
   * @return list<string>
   *   Command names in the order they were dispatched.
   */
  public function getDispatchedCommands(): array {
    return $this->dispatchedCommands;
  }

  /**
   * Sets whether the composer binary should appear available.
   *
   * @param bool $available
   *   Whether findExecutable('composer') resolves to a path.
   */
  public function setComposerAvailable(bool $available): void {
    $this->composerAvailable = $available;
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
   * Returns a fake path/NULL instead of shelling out to `command -v`.
   */
  protected function findExecutable(string $binary): ?string {
    return $this->composerAvailable ? '/usr/bin/composer' : NULL;
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
