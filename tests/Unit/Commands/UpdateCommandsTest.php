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
   * @param \Bounteous\Suds\Config\ConfigLoaderInterface|null $loader
   *   Config loader; defaults to a no-op loader.
   *
   * @return \Bounteous\Suds\Drush\Commands\UpdateCommands&\PHPUnit\Framework\MockObject\MockObject
   *   A configured mock.
   */
  private function buildCommand(?ConfigLoaderInterface $loader = NULL): UpdateCommands&MockObject {
    $mockAlias = $this->createMock(SiteAlias::class);
    $mockAliasManager = $this->createMock(SiteAliasManagerInterface::class);
    $mockAliasManager->method('getSelf')->willReturn($mockAlias);

    $command = $this->getMockBuilder(UpdateCommands::class)
      ->onlyMethods([
        'io',
        'siteAliasManager',
        'redispatchOptions',
        'runDrushCommand',
        'runShellCommand',
      ])
      ->getMock();
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->method('siteAliasManager')->willReturn($mockAliasManager);
    $command->method('redispatchOptions')->willReturn([]);
    $command->method('runShellCommand')
      ->willReturnCallback(static function (): void {});
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
   *
   * @return \Bounteous\Suds\Config\ConfigLoaderInterface
   *   A mock config loader.
   */
  private function makeConfigLoader(array $preUpdate = [], array $postUpdate = []): ConfigLoaderInterface {
    $loader = $this->createMock(ConfigLoaderInterface::class);
    $loader->method('getProjectRoot')->willReturn('/tmp');
    $loader->method('load')->willReturn([
      'update' => [
        'hooks' => ['pre_update' => $preUpdate, 'post_update' => $postUpdate],
      ],
    ]);
    return $loader;
  }

}
