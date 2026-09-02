<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Unit\Commands;

use Bounteous\Suds\Config\ConfigLoaderInterface;
use Bounteous\Suds\Drush\Commands\DepsUpdateCommands;
use Consolidation\SiteAlias\SiteAlias;
use Consolidation\SiteAlias\SiteAliasManagerInterface;
use Drush\Style\DrushStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DepsUpdateCommands.
 */
#[CoversClass(DepsUpdateCommands::class)]
class DepsUpdateCommandsTest extends TestCase {

  /**
   * Verifies suds:deps-update has the correct annotations.
   */
  public function testDepsUpdateAnnotations(): void {
    $doc = (new \ReflectionMethod(DepsUpdateCommands::class, 'depsUpdate'))->getDocComment();
    $this->assertIsString($doc);
    $this->assertStringContainsString(' suds:deps-update', $doc);
    $this->assertStringContainsString(' su-deps-update', $doc);
    $this->assertStringContainsString('@bootstrap none', $doc);
    $this->assertStringContainsString('@option skip-cex', $doc);
    $this->assertStringContainsString('@argument packages', $doc);
  }

  /**
   * Verifies the composer/updatedb/cache-rebuild/config-export step order.
   *
   * Runs one composer update pass per configured group, then updatedb,
   * cache:rebuild, and config:export.
   */
  public function testDepsUpdateRunsOneComposerUpdatePassPerConfiguredGroup(): void {
    [$shellCalls, $drushCalls] = [[], []];
    $command = $this->buildCommand($this->makeConfigLoader(groups: [['drupal/core-recommended'], ['drupal/*']]));
    $this->recordCalls($command, $shellCalls, $drushCalls);

    $command->depsUpdate();

    $this->assertSame(
      ['composer update drupal/core-recommended', 'composer update drupal/*'],
      $shellCalls,
    );
    $this->assertSame(['updatedb', 'cache:rebuild', 'config:export'], $drushCalls);
  }

  /**
   * Verifies an unconfigured groups list runs an unrestricted composer update.
   */
  public function testDepsUpdateRunsUnrestrictedComposerUpdateWhenNoGroupsConfigured(): void {
    [$shellCalls, $drushCalls] = [[], []];
    $command = $this->buildCommand();
    $this->recordCalls($command, $shellCalls, $drushCalls);

    $command->depsUpdate();

    $this->assertSame(['composer update'], $shellCalls);
    $this->assertSame(['updatedb', 'cache:rebuild', 'config:export'], $drushCalls);
  }

  /**
   * Verifies the packages argument overrides configured groups.
   *
   * The override runs as a single composer update pass.
   */
  public function testDepsUpdatePackagesArgumentOverridesConfiguredGroups(): void {
    [$shellCalls, $drushCalls] = [[], []];
    $command = $this->buildCommand($this->makeConfigLoader(groups: [['drupal/core-recommended'], ['drupal/*']]));
    $this->recordCalls($command, $shellCalls, $drushCalls);

    $command->depsUpdate('drupal/foo,drupal/bar');

    $this->assertSame(['composer update drupal/foo drupal/bar'], $shellCalls);
    $this->assertCount(3, $drushCalls);
  }

  /**
   * Verifies --skip-cex omits the config:export step.
   */
  public function testDepsUpdateSkipsConfigExportWhenFlagPassed(): void {
    [$shellCalls, $drushCalls] = [[], []];
    $command = $this->buildCommand();
    $this->recordCalls($command, $shellCalls, $drushCalls);

    $command->depsUpdate('', ['skip-cex' => TRUE]);

    $this->assertSame(['composer update'], $shellCalls);
    $this->assertSame(['updatedb', 'cache:rebuild'], $drushCalls);
  }

  /**
   * Verifies deps_update.skip_config_export: true skips config export too.
   *
   * Has the same effect as --skip-cex, without passing the flag.
   */
  public function testDepsUpdateSkipsConfigExportWhenConfiguredOff(): void {
    [$shellCalls, $drushCalls] = [[], []];
    $command = $this->buildCommand($this->makeConfigLoader(skipConfigExport: TRUE));
    $this->recordCalls($command, $shellCalls, $drushCalls);

    $command->depsUpdate();

    $this->assertNotContains('config:export', $drushCalls);
  }

  /**
   * Verifies a missing composer binary aborts before any drush command runs.
   */
  public function testDepsUpdateThrowsWhenComposerMissing(): void {
    [$shellCalls, $drushCalls] = [[], []];
    $command = $this->buildCommand(NULL, FALSE);
    $this->recordCalls($command, $shellCalls, $drushCalls);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/composer/i');

    try {
      $command->depsUpdate();
    }
    finally {
      $this->assertSame([], $shellCalls);
      $this->assertSame([], $drushCalls);
    }
  }

  /**
   * Verifies pre_deps_update hooks run before any composer update pass.
   */
  public function testDepsUpdateRunsPreDepsUpdateHooks(): void {
    [$shellCalls, $drushCalls] = [[], []];
    $command = $this->buildCommand($this->makeConfigLoader(preDepsUpdate: ['echo pre-a', 'echo pre-b']));
    $this->recordCalls($command, $shellCalls, $drushCalls);

    $command->depsUpdate();

    $this->assertSame(['echo pre-a', 'echo pre-b', 'composer update'], $shellCalls);
  }

  /**
   * Verifies post_deps_update hooks run after config:export.
   */
  public function testDepsUpdateRunsPostDepsUpdateHooks(): void {
    [$shellCalls, $drushCalls] = [[], []];
    $command = $this->buildCommand($this->makeConfigLoader(postDepsUpdate: ['npm run build', 'drush cr']));
    $this->recordCalls($command, $shellCalls, $drushCalls);

    $command->depsUpdate();

    $this->assertSame(['composer update', 'npm run build', 'drush cr'], $shellCalls);
  }

  /**
   * Verifies no hook commands run when both hook lists are empty.
   */
  public function testDepsUpdateSkipsHooksWhenEmpty(): void {
    [$shellCalls, $drushCalls] = [[], []];
    $command = $this->buildCommand();
    $this->recordCalls($command, $shellCalls, $drushCalls);

    $command->depsUpdate();

    $this->assertSame(['composer update'], $shellCalls);
  }

  /**
   * Wires runShellCommand()/runDrushCommand() to record calls in call order.
   *
   * @param \Bounteous\Suds\Drush\Commands\DepsUpdateCommands&\PHPUnit\Framework\MockObject\MockObject $command
   *   The mocked command instance.
   * @param list<string> $shellCalls
   *   Reference populated with commands passed to runShellCommand().
   * @param list<string> $drushCalls
   *   Reference populated with commands passed to runDrushCommand().
   */
  private function recordCalls(DepsUpdateCommands&MockObject $command, array &$shellCalls, array &$drushCalls): void {
    $command->method('runShellCommand')
      ->willReturnCallback(
        static function (string $cmd) use (&$shellCalls): void {
          $shellCalls[] = $cmd;
        }
      );
    $command->method('runDrushCommand')
      ->willReturnCallback(
        static function (mixed $alias, string $cmd) use (&$drushCalls): void {
          $drushCalls[] = $cmd;
        }
      );
  }

  /**
   * Builds a DepsUpdateCommands mock with standard dependencies wired up.
   *
   * @param \Bounteous\Suds\Config\ConfigLoaderInterface|null $loader
   *   Config loader; defaults to a no-op loader.
   * @param bool $composerFound
   *   Whether findExecutable('composer') resolves to a path. PHPUnit honours
   *   the first registered return for a mocked method, so this must be
   *   fixed at mock construction rather than re-stubbed per test.
   *
   * @return \Bounteous\Suds\Drush\Commands\DepsUpdateCommands&\PHPUnit\Framework\MockObject\MockObject
   *   A configured mock.
   */
  private function buildCommand(
    ?ConfigLoaderInterface $loader = NULL,
    bool $composerFound = TRUE,
  ): DepsUpdateCommands&MockObject {
    $mockAlias = $this->createMock(SiteAlias::class);
    $mockAliasManager = $this->createMock(SiteAliasManagerInterface::class);
    $mockAliasManager->method('getSelf')->willReturn($mockAlias);

    $command = $this->getMockBuilder(DepsUpdateCommands::class)
      ->onlyMethods([
        'io',
        'siteAliasManager',
        'redispatchOptions',
        'runDrushCommand',
        'runShellCommand',
        'findExecutable',
      ])
      ->getMock();
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->method('siteAliasManager')->willReturn($mockAliasManager);
    $command->method('redispatchOptions')->willReturn([]);
    $command->method('findExecutable')->willReturn($composerFound ? '/usr/bin/composer' : NULL);
    $command->setConfigLoader($loader ?? $this->makeConfigLoader());

    return $command;
  }

  /**
   * Builds a mock ConfigLoaderInterface with deps_update config.
   *
   * @param list<list<string>> $groups
   *   Value for deps_update.composer.groups.
   * @param bool $skipConfigExport
   *   Value for deps_update.skip_config_export.
   * @param list<string> $preDepsUpdate
   *   Commands for deps_update.hooks.pre_deps_update.
   * @param list<string> $postDepsUpdate
   *   Commands for deps_update.hooks.post_deps_update.
   *
   * @return \Bounteous\Suds\Config\ConfigLoaderInterface
   *   A mock config loader.
   */
  private function makeConfigLoader(
    array $groups = [],
    bool $skipConfigExport = FALSE,
    array $preDepsUpdate = [],
    array $postDepsUpdate = [],
  ): ConfigLoaderInterface {
    $loader = $this->createMock(ConfigLoaderInterface::class);
    $loader->method('getProjectRoot')->willReturn('/tmp');
    $loader->method('load')->willReturn([
      'deps_update' => [
        'composer' => ['groups' => $groups],
        'skip_config_export' => $skipConfigExport,
        'hooks' => ['pre_deps_update' => $preDepsUpdate, 'post_deps_update' => $postDepsUpdate],
      ],
    ]);
    return $loader;
  }

}
