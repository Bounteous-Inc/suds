<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Unit\Commands;

use Bounteous\Suds\Config\ConfigLoaderInterface;
use Bounteous\Suds\Drush\Commands\SetupCommands;
use Consolidation\SiteAlias\SiteAlias;
use Consolidation\SiteAlias\SiteAliasManagerInterface;
use Drush\Style\DrushStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SetupCommands.
 */
#[CoversClass(SetupCommands::class)]
class SetupCommandsTest extends TestCase {

  /**
   * Verifies suds:setup has the correct annotations.
   */
  public function testSetupAnnotations(): void {
    $doc = (new \ReflectionMethod(SetupCommands::class, 'setup'))->getDocComment();
    $this->assertIsString($doc);
    $this->assertStringContainsString(' suds:setup', $doc);
    $this->assertStringContainsString(' su-setup', $doc);
    $this->assertStringContainsString('@bootstrap none', $doc);
    $this->assertStringContainsString('@option profile', $doc);
    $this->assertStringContainsString('@option existing-config', $doc);
  }

  /**
   * Verifies setup() defaults profile to '' and existing-config to FALSE.
   */
  public function testSetupDefaultOptions(): void {
    $params = (new \ReflectionMethod(SetupCommands::class, 'setup'))->getParameters();
    $this->assertCount(1, $params);
    $default = $params[0]->getDefaultValue();
    $this->assertIsArray($default);
    $this->assertArrayHasKey('profile', $default);
    $this->assertSame('', $default['profile']);
    $this->assertArrayHasKey('existing-config', $default);
    $this->assertFalse($default['existing-config']);
  }

  /**
   * Verifies setup() resolves the profile from config when --profile is empty.
   */
  public function testSetupResolvesProfileFromConfig(): void {
    $command = $this->buildCommand(
      $this->makeConfigLoader(['profile' => 'standard']),
    );

    $command->expects($this->once())
      ->method('runDrushCommand')
      ->with(
        $this->anything(),
        'site:install',
        $this->equalTo(['standard']),
        $this->anything(),
      );

    $command->setup(['profile' => '', 'existing-config' => FALSE]);
  }

  /**
   * Verifies --profile option overrides the profile in config.
   */
  public function testSetupOptionOverridesConfigProfile(): void {
    $command = $this->buildCommand(
      $this->makeConfigLoader(['profile' => 'standard']),
    );

    $command->expects($this->once())
      ->method('runDrushCommand')
      ->with(
        $this->anything(),
        'site:install',
        $this->equalTo(['minimal']),
        $this->anything(),
      );

    $command->setup(['profile' => 'minimal', 'existing-config' => FALSE]);
  }

  /**
   * Verifies --existing-config=TRUE flows into the drush site:install options.
   */
  public function testSetupPassesExistingConfigOption(): void {
    $command = $this->buildCommand($this->makeConfigLoader());

    $command->expects($this->once())
      ->method('runDrushCommand')
      ->with(
        $this->anything(),
        'site:install',
        $this->anything(),
        $this->callback(
          static fn(array $opts): bool =>
            isset($opts['existing-config']) && $opts['existing-config'] === TRUE,
        ),
      );

    $command->setup(['profile' => '', 'existing-config' => TRUE]);
  }

  /**
   * Verifies existing-config is absent from site:install options when FALSE.
   */
  public function testSetupDoesNotPassExistingConfigWhenFalse(): void {
    $command = $this->buildCommand($this->makeConfigLoader());

    $command->expects($this->once())
      ->method('runDrushCommand')
      ->with(
        $this->anything(),
        'site:install',
        $this->anything(),
        $this->callback(
          static fn(array $opts): bool => !array_key_exists('existing-config', $opts),
        ),
      );

    $command->setup(['profile' => '', 'existing-config' => FALSE]);
  }

  /**
   * Verifies setup() runs the recipe shell command for each configured recipe.
   */
  public function testSetupAppliesRecipes(): void {
    $capturedCmds = [];
    $command = $this->buildCommand($this->makeConfigLoader(
      [],
      ['recipes' => ['recipes/foo', 'recipes/bar']],
    ));
    $command->method('runDrushCommand')
      ->willReturnCallback(static function (): void {});
    $command->method('runShellCommand')
      ->willReturnCallback(
        static function (string $cmd) use (&$capturedCmds): void {
          $capturedCmds[] = $cmd;
        }
      );

    $command->setup(['profile' => '', 'existing-config' => FALSE]);

    $this->assertCount(2, $capturedCmds);
    $this->assertStringContainsString('drupal recipe', $capturedCmds[0]);
    $this->assertStringContainsString('recipes/foo', $capturedCmds[0]);
    $this->assertStringContainsString('drupal recipe', $capturedCmds[1]);
    $this->assertStringContainsString('recipes/bar', $capturedCmds[1]);
  }

  /**
   * Verifies setup() runs pre_setup hooks with correct command strings.
   */
  public function testSetupRunsPreSetupHooks(): void {
    $capturedCmds = [];
    $command = $this->buildCommand($this->makeConfigLoader(
      [],
      [],
      ['pre_setup' => ['echo pre-a', 'echo pre-b']],
    ));
    $command->method('runDrushCommand')
      ->willReturnCallback(static function (): void {});
    $command->method('runShellCommand')
      ->willReturnCallback(
        static function (string $cmd) use (&$capturedCmds): void {
          $capturedCmds[] = $cmd;
        }
      );

    $command->setup(['profile' => '', 'existing-config' => FALSE]);

    $this->assertSame(['echo pre-a', 'echo pre-b'], $capturedCmds);
  }

  /**
   * Verifies setup() runs post_setup hooks with correct command strings.
   */
  public function testSetupRunsPostSetupHooks(): void {
    $capturedCmds = [];
    $command = $this->buildCommand($this->makeConfigLoader(
      [],
      [],
      ['post_setup' => ['drush cr', 'drush updb']],
    ));
    $command->method('runDrushCommand')
      ->willReturnCallback(static function (): void {});
    $command->method('runShellCommand')
      ->willReturnCallback(
        static function (string $cmd) use (&$capturedCmds): void {
          $capturedCmds[] = $cmd;
        }
      );

    $command->setup(['profile' => '', 'existing-config' => FALSE]);

    $this->assertSame(['drush cr', 'drush updb'], $capturedCmds);
  }

  /**
   * Builds a SetupCommands mock with standard dependencies wired up.
   *
   * RunDrushCommand is included in onlyMethods but not pre-stubbed, allowing
   * each test to configure its own expectation or tracking behaviour.
   *
   * @param \Bounteous\Suds\Config\ConfigLoaderInterface $loader
   *   Config loader to inject.
   *
   * @return \Bounteous\Suds\Drush\Commands\SetupCommands&\PHPUnit\Framework\MockObject\MockObject
   *   A configured mock.
   */
  private function buildCommand(ConfigLoaderInterface $loader): SetupCommands&MockObject {
    $mockAlias = $this->createMock(SiteAlias::class);
    $mockAliasManager = $this->createMock(SiteAliasManagerInterface::class);
    $mockAliasManager->method('getSelf')->willReturn($mockAlias);

    $command = $this->getMockBuilder(SetupCommands::class)
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
    $command->setConfigLoader($loader);

    return $command;
  }

  /**
   * Returns a config array suitable for use in setup() tests.
   *
   * @param array<string, mixed> $drupalOverrides
   *   Overrides for the drupal section.
   * @param array<string, mixed> $setupOverrides
   *   Overrides for the setup section.
   * @param array<string, mixed> $hookOverrides
   *   Overrides for setup.hooks section.
   *
   * @return \Bounteous\Suds\Config\ConfigLoaderInterface
   *   A mock config loader.
   */
  private function makeConfigLoader(
    array $drupalOverrides = [],
    array $setupOverrides = [],
    array $hookOverrides = [],
  ): ConfigLoaderInterface {
    $config = [
      'drupal' => array_merge(['profile' => 'minimal', 'root' => 'web'], $drupalOverrides),
      'setup' => array_merge(
        [
          'recipes' => [],
          'hooks'   => array_merge(['pre_setup' => [], 'post_setup' => []], $hookOverrides),
        ],
        $setupOverrides,
      ),
    ];
    $loader = $this->createMock(ConfigLoaderInterface::class);
    $loader->method('getProjectRoot')->willReturn('/tmp');
    $loader->method('load')->willReturn($config);
    return $loader;
  }

}
