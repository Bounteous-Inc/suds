<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Unit\Commands;

use Bounteous\Suds\Config\ConfigLoaderInterface;
use Bounteous\Suds\Drush\Commands\ConfigCommands;
use Drush\Style\DrushStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Drush\Log\DrushLoggerManager;

/**
 * Unit tests for ConfigCommands.
 */
#[CoversClass(ConfigCommands::class)]
class ConfigCommandsTest extends TestCase {

  /**
   * Verifies dump() calls load() by default (resolved config).
   */
  public function testDumpCallsLoadByDefault(): void {
    $loader = $this->createMock(ConfigLoaderInterface::class);
    $loader->expects($this->once())
      ->method('load')
      ->willReturn(['project' => ['name' => NULL]]);
    $loader->expects($this->never())->method('getDefaults');
    $loader->method('hasProjectConfig')->willReturn(TRUE);

    $command = $this->createPartialMock(ConfigCommands::class, ['io']);
    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->once())->method('writeln');
    $command->method('io')->willReturn($io);
    $command->setConfigLoader($loader);

    $command->dump();
  }

  /**
   * Verifies dump() calls getDefaults() when --defaults is set.
   */
  public function testDumpWithDefaultsFlagCallsGetDefaults(): void {
    $loader = $this->createMock(ConfigLoaderInterface::class);
    $loader->expects($this->once())
      ->method('getDefaults')
      ->willReturn(['project' => ['name' => NULL]]);
    $loader->expects($this->never())->method('load');
    // hasProjectConfig() is called by warnUnknownConfigKeys(); allow it.
    $loader->method('hasProjectConfig')->willReturn(FALSE);

    $command = $this->createPartialMock(ConfigCommands::class, ['io']);
    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->once())->method('writeln');
    $command->method('io')->willReturn($io);
    $command->setConfigLoader($loader);

    $command->dump('', ['defaults' => TRUE]);
  }

  /**
   * Verifies dump() logs a notice when no project config exists.
   */
  public function testDumpWithNoProjectConfigLogsNotice(): void {
    $loader = $this->createMock(ConfigLoaderInterface::class);
    $loader->method('hasProjectConfig')->willReturn(FALSE);
    $loader->method('load')->willReturn(['project' => ['name' => NULL]]);

    $mockLogger = $this->getMockBuilder(DrushLoggerManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['notice'])
      ->getMock();
    $mockLogger->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('No suds.yml found'));

    $command = $this->getMockBuilder(ConfigCommands::class)
      ->onlyMethods(['io', 'logger'])
      ->getMock();
    $io = $this->createMock(DrushStyle::class);
    $io->method('writeln');
    $command->method('io')->willReturn($io);
    $command->method('logger')->willReturn($mockLogger);
    $command->setConfigLoader($loader);

    $command->dump();
  }

  /**
   * Verifies dump() does not log a notice when --defaults is set.
   */
  public function testDumpWithDefaultsFlagDoesNotLogNotice(): void {
    $loader = $this->createMock(ConfigLoaderInterface::class);
    // hasProjectConfig() is called by warnUnknownConfigKeys(); allow it.
    $loader->method('hasProjectConfig')->willReturn(FALSE);
    $loader->method('getDefaults')->willReturn(['project' => ['name' => NULL]]);

    $mockLogger = $this->getMockBuilder(DrushLoggerManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['notice'])
      ->getMock();
    $mockLogger->expects($this->never())->method('notice');

    $command = $this->getMockBuilder(ConfigCommands::class)
      ->onlyMethods(['io', 'logger'])
      ->getMock();
    $io = $this->createMock(DrushStyle::class);
    $io->method('writeln');
    $command->method('io')->willReturn($io);
    $command->method('logger')->willReturn($mockLogger);
    $command->setConfigLoader($loader);

    $command->dump('', ['defaults' => TRUE]);
  }

  /**
   * Verifies dump() with a scalar key outputs only that value.
   */
  public function testDumpWithKeyOutputsScalarValue(): void {
    $loader = $this->createMock(ConfigLoaderInterface::class);
    $loader->method('load')->willReturn(['drupal' => ['root' => 'web']]);
    $loader->method('hasProjectConfig')->willReturn(TRUE);

    $command = $this->createPartialMock(ConfigCommands::class, ['io']);
    $io = $this->createMock(DrushStyle::class);

    $written = NULL;
    $io->method('writeln')->willReturnCallback(
      static function (string $output) use (&$written): void {
        $written = $output;
      }
    );
    $command->method('io')->willReturn($io);
    $command->setConfigLoader($loader);

    $command->dump('drupal.root');

    $this->assertSame('web', $written);
  }

  /**
   * Verifies dump() with an array key outputs YAML for that subtree.
   */
  public function testDumpWithKeyOutputsArrayValue(): void {
    $loader = $this->createMock(ConfigLoaderInterface::class);
    $loader->method('load')->willReturn(['drupal' => ['root' => 'web']]);
    $loader->method('hasProjectConfig')->willReturn(TRUE);

    $command = $this->createPartialMock(ConfigCommands::class, ['io']);
    $io = $this->createMock(DrushStyle::class);

    $written = NULL;
    $io->method('writeln')->willReturnCallback(
      static function (string $output) use (&$written): void {
        $written = $output;
      }
    );
    $command->method('io')->willReturn($io);
    $command->setConfigLoader($loader);

    $command->dump('drupal');

    $this->assertIsString($written);
    $this->assertStringContainsString('root', $written);
  }

  /**
   * Verifies dump() throws when the key is not found in config.
   */
  public function testDumpWithMissingKeyThrows(): void {
    $loader = $this->createMock(ConfigLoaderInterface::class);
    $loader->method('load')->willReturn(['drupal' => ['root' => 'web']]);
    $loader->method('hasProjectConfig')->willReturn(TRUE);

    $command = $this->createPartialMock(ConfigCommands::class, ['io']);
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->setConfigLoader($loader);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/Config key not found/');

    $command->dump('sync.db.nonexistent');
  }

  /**
   * Verifies dump() outputs valid YAML containing the config keys.
   */
  public function testDumpOutputsYaml(): void {
    $loader = $this->createMock(ConfigLoaderInterface::class);
    $loader->method('load')->willReturn([
      'drupal' => ['root' => 'web'],
      'sync'   => ['db' => ['sanitize' => TRUE]],
    ]);
    $loader->method('hasProjectConfig')->willReturn(TRUE);

    $command = $this->createPartialMock(ConfigCommands::class, ['io']);
    $io = $this->createMock(DrushStyle::class);

    $written = NULL;
    $io->method('writeln')->willReturnCallback(
      static function (string $output) use (&$written): void {
        $written = $output;
      }
    );
    $command->method('io')->willReturn($io);
    $command->setConfigLoader($loader);

    $command->dump();

    $this->assertIsString($written);
    $this->assertStringContainsString('drupal', $written);
    $this->assertStringContainsString('root', $written);
  }

}
