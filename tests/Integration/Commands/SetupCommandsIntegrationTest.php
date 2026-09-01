<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Integration\Commands;

use Bounteous\Suds\Config\ConfigLoaderInterface;
use Bounteous\Suds\Drush\Commands\SetupCommands;
use Bounteous\Suds\Tests\Support\IntegrationTestCase;
use Consolidation\SiteAlias\SiteAlias;
use Consolidation\SiteAlias\SiteAliasManager;
use Consolidation\SiteProcess\ProcessBase;
use Drush\SiteAlias\ProcessManager;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Integration tests for SetupCommands.
 *
 * Commands are invoked through the Symfony Application API via
 * AnnotatedCommandFactory — no subprocess is spawned and no Drupal bootstrap
 * is required. setup() tests inject mocked ProcessManager and ConfigLoader so
 * that the site:install subprocess call is never actually executed.
 */
#[CoversClass(SetupCommands::class)]
class SetupCommandsIntegrationTest extends IntegrationTestCase {

  /**
   * The application tester.
   *
   * @var \Symfony\Component\Console\Tester\ApplicationTester
   */
  private ApplicationTester $tester;

  /**
   * The command instance under test.
   *
   * @var \Bounteous\Suds\Tests\Integration\Commands\TestableSetupCommands
   */
  private TestableSetupCommands $commandInstance;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->commandInstance = new TestableSetupCommands();
    $this->injectSetupMocks($this->commandInstance);
    $this->tester = $this->buildTester($this->commandInstance);
  }

  /**
   * Tests that suds:setup exits cleanly with a mocked process manager.
   */
  public function testSetupExitsCleanlyWithMocks(): void {
    $this->commandInstance->setConfigLoader($this->makeConfigLoader());
    $exitCode = $this->tester->run(['command' => 'suds:setup']);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that suds:setup resolves the profile from config.
   */
  public function testSetupUsesProfileFromConfig(): void {
    $this->commandInstance->setConfigLoader($this->makeConfigLoader('standard'));
    $this->tester->run(['command' => 'suds:setup']);
    $this->assertStringContainsString('standard', $this->tester->getDisplay());
  }

  /**
   * Tests that --profile overrides the profile defined in config.
   */
  public function testSetupOptionOverridesConfigProfile(): void {
    $this->commandInstance->setConfigLoader($this->makeConfigLoader('standard'));
    $this->tester->run(['command' => 'suds:setup', '--profile' => 'minimal']);
    $this->assertStringContainsString('minimal', $this->tester->getDisplay());
  }

  /**
   * Tests that suds:setup dispatches the site:install sub-command.
   *
   * Verifies that processManager()->drush() is invoked with 'site:install'
   * when suds:setup runs, confirming the install step is wired correctly
   * through the Symfony console layer.
   */
  public function testSetupDispatchesSiteInstall(): void {
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

    $command = new TestableSetupCommands();
    $command->setProcessManager($mockProcessManager);
    $command->setSiteAliasManager($mockAliasManager);
    $command->setConfigLoader($this->makeConfigLoader());

    $tester = $this->buildTester($command);
    $tester->run(['command' => 'suds:setup']);

    $this->assertContains('site:install', $dispatchedCommands);
  }

  /**
   * Builds a mock ConfigLoaderInterface returning a basic config array.
   *
   * @param string $profile
   *   The Drupal profile to return in the config.
   *
   * @return \Bounteous\Suds\Config\ConfigLoaderInterface
   *   A mock config loader.
   */
  private function makeConfigLoader(string $profile = 'minimal'): ConfigLoaderInterface {
    $mockLoader = $this->createMock(ConfigLoaderInterface::class);
    $mockLoader->method('getProjectRoot')->willReturn('/tmp');
    $mockLoader->method('load')->willReturn([
      'drupal' => ['profile' => $profile, 'root' => 'web'],
      'setup'  => ['recipes' => [], 'hooks' => ['pre_setup' => [], 'post_setup' => []]],
    ]);
    return $mockLoader;
  }

  /**
   * Injects mocked ProcessManager and SiteAliasManager into a command instance.
   *
   * @param \Bounteous\Suds\Drush\Commands\SetupCommands $command
   *   The command instance to configure.
   */
  private function injectSetupMocks(SetupCommands $command): void {
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
 * Testable subclass of SetupCommands for integration tests.
 *
 * Overrides runShellCommand() to a no-op so recipes and post_setup hooks do
 * not spawn real subprocesses, and redispatchOptions() to return an empty
 * array so the Drush DI container is not required.
 */
class TestableSetupCommands extends SetupCommands {

  /**
   * {@inheritdoc}
   *
   * No-op in integration tests — recipes and hooks are not executed.
   */
  protected function runShellCommand(string $cmd, string $cwd): void {
    // Intentionally empty: integration tests do not spawn real subprocesses.
  }

  /**
   * {@inheritdoc}
   *
   * Returns empty array in integration tests — no Drush container is present.
   */
  protected function redispatchOptions(array $except = []): array {
    return [];
  }

}
