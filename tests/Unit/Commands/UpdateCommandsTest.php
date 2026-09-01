<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Unit\Commands;

use Bounteous\Suds\Config\ConfigLoaderInterface;
use Bounteous\Suds\Drush\Commands\UpdateCommands;
use Consolidation\SiteAlias\SiteAlias;
use Consolidation\SiteAlias\SiteAliasManagerInterface;
use Drush\Style\DrushStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UpdateCommands.
 */
#[CoversClass(UpdateCommands::class)]
class UpdateCommandsTest extends TestCase {

  /**
   * Verifies suds:update has the correct @command and @aliases annotations.
   */
  public function testUpdateAnnotations(): void {
    $doc = (new \ReflectionMethod(UpdateCommands::class, 'update'))->getDocComment();
    $this->assertIsString($doc);
    $this->assertStringContainsString(' suds:update', $doc);
    $this->assertStringContainsString(' su-update', $doc);
    $this->assertStringContainsString('@bootstrap none', $doc);
    $this->assertStringContainsString('@option reconcile-site-uuid', $doc);
  }

  /**
   * Verifies update() dispatches cache:rebuild, updatedb, and config:import.
   *
   * The expected sequence is: cache:rebuild → updatedb → config:import →
   * config:import (twice for config_split compatibility).
   */
  public function testUpdateDispatchesExpectedSubcommands(): void {
    $dispatchedCommands = [];
    $command = $this->buildCommand();
    $command->method('runDrushCommand')
      ->willReturnCallback(
        static function (mixed $alias, string $cmd) use (&$dispatchedCommands): void {
          $dispatchedCommands[] = $cmd;
        }
      );

    $command->update();

    $this->assertSame(
      ['cache:rebuild', 'updatedb', 'config:import', 'config:import'],
      $dispatchedCommands,
    );
  }

  /**
   * Verifies update() imports configuration exactly twice.
   */
  public function testUpdateImportsConfigTwice(): void {
    $configImportCount = 0;
    $command = $this->buildCommand();
    $command->method('runDrushCommand')
      ->willReturnCallback(
        static function (mixed $alias, string $cmd) use (&$configImportCount): void {
          if ($cmd === 'config:import') {
            $configImportCount++;
          }
        }
      );

    $command->update();

    $this->assertSame(2, $configImportCount);
  }

  /**
   * Verifies a mismatched site UUID aborts the update by default.
   *
   * The failure must happen before config:import runs, and the message must
   * name both UUIDs and the way to opt into reconciliation.
   */
  public function testUpdateFailsWhenSiteUuidMismatched(): void {
    $dispatchedCommands = [];
    $command = $this->buildCommand(NULL, 'sync-uuid', 'active-uuid');
    $command->method('runDrushCommand')
      ->willReturnCallback(
        static function (mixed $alias, string $cmd) use (&$dispatchedCommands): void {
          $dispatchedCommands[] = $cmd;
        }
      );

    try {
      $command->update();
      $this->fail('Expected a RuntimeException for the mismatched site UUID.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('sync-uuid', $e->getMessage());
      $this->assertStringContainsString('active-uuid', $e->getMessage());
      $this->assertStringContainsString('reconcile_site_uuid', $e->getMessage());
      $this->assertStringContainsString('--existing-config', $e->getMessage());
    }

    // The check runs before updatedb, so an abort leaves the database
    // untouched rather than half-updated.
    $this->assertSame(['cache:rebuild'], $dispatchedCommands);
  }

  /**
   * Verifies --reconcile-site-uuid overwrites the UUID instead of failing.
   */
  public function testUpdateReconcilesSiteUuidWhenFlagPassed(): void {
    $dispatched = [];
    $command = $this->buildCommand(NULL, 'sync-uuid', 'active-uuid');
    $command->method('runDrushCommand')
      ->willReturnCallback(
        static function (mixed $alias, string $cmd, array $args = []) use (&$dispatched): void {
          $dispatched[] = [$cmd, $args];
        }
      );

    $command->update(['reconcile-site-uuid' => TRUE]);

    $this->assertSame(
      [
        ['cache:rebuild', []],
        ['config:set', ['system.site', 'uuid', 'sync-uuid']],
        ['updatedb', []],
        ['config:import', []],
        ['config:import', []],
      ],
      $dispatched,
    );
  }

  /**
   * Verifies update.reconcile_site_uuid: true enables reconciliation.
   */
  public function testUpdateReconcilesSiteUuidWhenEnabledInConfig(): void {
    $dispatched = [];
    $command = $this->buildCommand(
      $this->makeConfigLoader(reconcileSiteUuid: TRUE),
      'sync-uuid',
      'active-uuid',
    );
    $command->method('runDrushCommand')
      ->willReturnCallback(
        static function (mixed $alias, string $cmd, array $args = []) use (&$dispatched): void {
          $dispatched[] = [$cmd, $args];
        }
      );

    $command->update();

    $this->assertContains(['config:set', ['system.site', 'uuid', 'sync-uuid']], $dispatched);
  }

  /**
   * Verifies a matching site UUID dispatches no config:set at all.
   *
   * Guards against reintroducing an unconditional write on every update.
   */
  public function testUpdateSkipsConfigSetWhenUuidsMatch(): void {
    $dispatchedCommands = [];
    $command = $this->buildCommand(NULL, 'same-uuid', 'same-uuid');
    $command->method('runDrushCommand')
      ->willReturnCallback(
        static function (mixed $alias, string $cmd) use (&$dispatchedCommands): void {
          $dispatchedCommands[] = $cmd;
        }
      );

    $command->update();

    $this->assertSame(
      ['cache:rebuild', 'updatedb', 'config:import', 'config:import'],
      $dispatchedCommands,
    );
  }

  /**
   * Verifies an unexported config sync directory skips the UUID check.
   *
   * Immediately after site:install there is no system.site.yml to compare
   * against, so the update must proceed rather than fail.
   */
  public function testUpdateSkipsUuidCheckWhenSyncHasNoSiteConfig(): void {
    $dispatchedCommands = [];
    $command = $this->buildCommand(NULL, NULL, 'active-uuid');
    $command->method('runDrushCommand')
      ->willReturnCallback(
        static function (mixed $alias, string $cmd) use (&$dispatchedCommands): void {
          $dispatchedCommands[] = $cmd;
        }
      );

    $command->update();

    $this->assertNotContains('config:set', $dispatchedCommands);
    $this->assertContains('config:import', $dispatchedCommands);
  }

  /**
   * Verifies update() runs pre_update hooks with correct command strings.
   */
  public function testUpdateRunsPreUpdateHooks(): void {
    $capturedCmds = [];
    $command = $this->buildCommand(
      $this->makeConfigLoader(preUpdate: ['echo pre-a', 'echo pre-b']),
    );
    $command->method('runDrushCommand')
      ->willReturnCallback(static function (): void {});
    $command->method('runShellCommand')
      ->willReturnCallback(
        static function (string $cmd) use (&$capturedCmds): void {
          $capturedCmds[] = $cmd;
        }
      );

    $command->update();

    $this->assertSame(['echo pre-a', 'echo pre-b'], $capturedCmds);
  }

  /**
   * Verifies update() runs post_update hooks with correct command strings.
   */
  public function testUpdateRunsPostUpdateHooks(): void {
    $capturedCmds = [];
    $command = $this->buildCommand(
      $this->makeConfigLoader(postUpdate: ['npm run build', 'drush cr']),
    );
    $command->method('runDrushCommand')
      ->willReturnCallback(static function (): void {});
    $command->method('runShellCommand')
      ->willReturnCallback(
        static function (string $cmd) use (&$capturedCmds): void {
          $capturedCmds[] = $cmd;
        }
      );

    $command->update();

    $this->assertSame(['npm run build', 'drush cr'], $capturedCmds);
  }

  /**
   * Verifies update() skips runShellCommand when both hook lists are empty.
   */
  public function testUpdateSkipsHooksWhenEmpty(): void {
    $command = $this->buildCommand();
    $command->method('runDrushCommand')
      ->willReturnCallback(static function (): void {});
    $command->expects($this->never())->method('runShellCommand');

    $command->update();
  }

  /**
   * Builds an UpdateCommands mock with standard dependencies wired up.
   *
   * RunDrushCommand is included in onlyMethods but not pre-stubbed, allowing
   * each test to configure its own return or tracking behaviour.
   *
   * The UUID getters are stubbed once here rather than per test, because
   * PHPUnit honours the first registered return for a method — a later
   * ->method() call in an individual test would be ignored.
   *
   * @param \Bounteous\Suds\Config\ConfigLoaderInterface|null $loader
   *   Config loader; defaults to a no-op loader.
   * @param string|null $syncUuid
   *   Value for getConfigSyncUuid(). NULL (the default) means config sync has
   *   no system.site.yml, which skips the UUID check entirely.
   * @param string $activeUuid
   *   Value for getActiveSiteUuid().
   *
   * @return \Bounteous\Suds\Drush\Commands\UpdateCommands&\PHPUnit\Framework\MockObject\MockObject
   *   A configured mock.
   */
  private function buildCommand(
    ?ConfigLoaderInterface $loader = NULL,
    ?string $syncUuid = NULL,
    string $activeUuid = 'active-uuid',
  ): UpdateCommands&MockObject {
    $mockAlias = $this->createMock(SiteAlias::class);
    $mockAliasManager = $this->createMock(SiteAliasManagerInterface::class);
    $mockAliasManager->method('getSelf')->willReturn($mockAlias);

    $command = $this->getMockBuilder(UpdateCommands::class)
      ->onlyMethods([
        'io',
        'siteAliasManager',
        'redispatchOptions',
        'runDrushCommand',
        'runDrushCommandCapture',
        'runShellCommand',
        'getConfigSyncUuid',
        'getActiveSiteUuid',
      ])
      ->getMock();
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->method('siteAliasManager')->willReturn($mockAliasManager);
    $command->method('redispatchOptions')->willReturn([]);
    $command->method('runShellCommand')
      ->willReturnCallback(static function (): void {});
    $command->method('getConfigSyncUuid')->willReturn($syncUuid);
    $command->method('getActiveSiteUuid')->willReturn($activeUuid);
    $command->setConfigLoader($loader ?? $this->makeConfigLoader());

    return $command;
  }

  /**
   * Builds a mock ConfigLoaderInterface with update config.
   *
   * @param list<string> $preUpdate
   *   Commands for update.hooks.pre_update.
   * @param list<string> $postUpdate
   *   Commands for update.hooks.post_update.
   * @param bool $reconcileSiteUuid
   *   Value for update.reconcile_site_uuid.
   *
   * @return \Bounteous\Suds\Config\ConfigLoaderInterface
   *   A mock config loader.
   */
  private function makeConfigLoader(
    array $preUpdate = [],
    array $postUpdate = [],
    bool $reconcileSiteUuid = FALSE,
  ): ConfigLoaderInterface {
    $loader = $this->createMock(ConfigLoaderInterface::class);
    $loader->method('getProjectRoot')->willReturn('/tmp');
    $loader->method('load')->willReturn([
      'update' => [
        'reconcile_site_uuid' => $reconcileSiteUuid,
        'hooks' => ['pre_update' => $preUpdate, 'post_update' => $postUpdate],
      ],
    ]);
    return $loader;
  }

}
