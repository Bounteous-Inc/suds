<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Unit\Commands;

use Bounteous\Suds\Config\ConfigLoaderInterface;
use Bounteous\Suds\Drush\Commands\FilesCommands;
use Consolidation\SiteAlias\SiteAlias;
use Consolidation\SiteAlias\SiteAliasManagerInterface;
use Drush\Style\DrushStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FilesCommands.
 */
#[CoversClass(FilesCommands::class)]
class FilesCommandsTest extends TestCase {

  /**
   * Verifies suds:files:sync has the correct annotations.
   */
  public function testFilesSyncAnnotations(): void {
    $doc = (new \ReflectionMethod(FilesCommands::class, 'filesSync'))->getDocComment();
    $this->assertIsString($doc);
    $this->assertStringContainsString(' suds:files:sync', $doc);
    $this->assertStringContainsString(' su-files-sync', $doc);
    $this->assertStringContainsString('@bootstrap none', $doc);
  }

  /**
   * Verifies filesSync() throws when source is an empty string.
   */
  public function testFilesSyncThrowsWhenSourceIsEmpty(): void {
    $command = $this->buildCommand(['public://files']);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/source alias is required/');

    $command->filesSync('');
  }

  /**
   * Verifies filesSync() dispatches drush rsync once per path in config.
   */
  public function testFilesSyncDispatchesRsyncPerPath(): void {
    $command = $this->buildCommand(['public://files', 'private://']);

    $command->expects($this->exactly(2))
      ->method('runDrushCommand')
      ->with($this->anything(), 'rsync', $this->anything(), $this->anything());

    $command->filesSync('@prod');
  }

  /**
   * Verifies filesSync() dispatches no rsync calls when paths list is empty.
   */
  public function testFilesSyncSkipsDispatchWhenPathsEmpty(): void {
    $command = $this->buildCommand([]);

    $command->expects($this->never())->method('runDrushCommand');

    $command->filesSync('@prod');
  }

  /**
   * Verifies filesSync() passes correct source, self, and stats args to rsync.
   */
  public function testFilesSyncPassesCorrectPathArgs(): void {
    $command = $this->buildCommand(['public://files']);

    $command->expects($this->once())
      ->method('runDrushCommand')
      ->with(
        $this->anything(),
        'rsync',
        $this->equalTo([
          '@prod:%public://files',
          '@self:%public://files',
          '--',
          '--stats',
        ]),
        $this->anything(),
      );

    $command->filesSync('@prod');
  }

  /**
   * Builds a FilesCommands mock loaded with the given paths.
   *
   * @param list<string> $paths
   *   File paths for the sync.files.paths config key.
   *
   * @return \Bounteous\Suds\Drush\Commands\FilesCommands&\PHPUnit\Framework\MockObject\MockObject
   *   A configured mock.
   */
  private function buildCommand(array $paths): FilesCommands&MockObject {
    $mockAlias = $this->createMock(SiteAlias::class);
    $mockAliasManager = $this->createMock(SiteAliasManagerInterface::class);
    $mockAliasManager->method('getSelf')->willReturn($mockAlias);

    $mockLoader = $this->createMock(ConfigLoaderInterface::class);
    $mockLoader->method('load')->willReturn([
      'sync' => ['files' => ['paths' => $paths]],
    ]);

    $command = $this->getMockBuilder(FilesCommands::class)
      ->onlyMethods(['io', 'siteAliasManager', 'redispatchOptions', 'runDrushCommand'])
      ->getMock();
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->method('siteAliasManager')->willReturn($mockAliasManager);
    $command->method('redispatchOptions')->willReturn([]);
    $command->method('runDrushCommand')
      ->willReturnCallback(static function (): void {});
    $command->setConfigLoader($mockLoader);

    return $command;
  }

}
