<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Unit\Commands;

use Bounteous\Suds\Config\ConfigLoaderInterface;
use Bounteous\Suds\Drush\Commands\SetupCommands;
use Bounteous\Suds\Tests\Support\TempDirectoryTrait;
use Consolidation\SiteAlias\SiteAlias;
use Consolidation\SiteAlias\SiteAliasManagerInterface;
use Drush\Style\DrushStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SetupCommands.
 */
#[CoversClass(SetupCommands::class)]
class SetupCommandsTest extends TestCase {

  use TempDirectoryTrait;

  /**
   * The temporary project root, created on first use by projectRoot().
   *
   * @var string|null
   */
  private ?string $tempProjectRoot = NULL;

  /**
   * Webroot name used for the temporary project, mirroring drupal.root.
   */
  private const WEBROOT = 'web';

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->tempProjectRoot !== NULL) {
      $this->removeDirectory($this->tempProjectRoot);
      $this->tempProjectRoot = NULL;
    }
    parent::tearDown();
  }

  /**
   * Returns the temporary project root, creating it on first call.
   *
   * Created lazily so the majority of tests in this class, which drive
   * setup() entirely through mocks, do not touch the filesystem at all.
   */
  private function projectRoot(): string {
    return $this->tempProjectRoot ??= $this->createTempDir();
  }

  /**
   * Verifies vendor/bin/dr is preferred when core registers it (11.4+).
   *
   * Both entry points exist on 11.4+, where core/scripts/drupal is a
   * deprecated shim whose autoload fallback assumes a nested vendor/ — so the
   * Composer bin must win even when the core script is also present.
   */
  public function testRequireRecipeRunnerPrefersComposerBin(): void {
    $this->createComposerBin();
    $this->createCoreScript();

    $this->assertSame(
      escapeshellarg($this->projectRoot() . '/vendor/bin/dr'),
      $this->invokeRequireRecipeRunner(),
    );
  }

  /**
   * Verifies the fallback to core/scripts/drupal on Drupal 10.4 through 11.3.
   *
   * Those versions register no Composer bin at all, so the core script is the
   * only available entry point and must be invoked through the PHP binary.
   */
  public function testRequireRecipeRunnerFallsBackToCoreScript(): void {
    $this->createCoreScript();

    $this->assertSame(
      escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(
        $this->projectRoot() . '/' . self::WEBROOT . '/core/scripts/drupal',
      ),
      $this->invokeRequireRecipeRunner(),
    );
  }

  /**
   * Verifies requireRecipeRunner() throws when no entry point is present.
   */
  public function testRequireRecipeRunnerThrowsWhenMissing(): void {
    $this->expectException(\RuntimeException::class);
    $this->invokeRequireRecipeRunner();
  }

  /**
   * Verifies a relocated Composer vendor-dir is honoured.
   *
   * Composer's vendor directory is configurable, so a project that moves it
   * must still have its recipe bin found rather than silently falling through
   * to the deprecated core script.
   */
  public function testRequireRecipeRunnerHonoursConfiguredVendorDir(): void {
    $this->writeComposerManifest(['config' => ['vendor-dir' => 'libraries']]);
    mkdir($this->projectRoot() . '/libraries/bin', 0777, TRUE);
    touch($this->projectRoot() . '/libraries/bin/dr');
    // Present but must lose: the relocated Composer bin still takes priority.
    $this->createCoreScript();

    $this->assertSame(
      escapeshellarg($this->projectRoot() . '/libraries/bin/dr'),
      $this->invokeRequireRecipeRunner(),
    );
  }

  /**
   * Verifies an absolute vendor-dir is used as given, not re-rooted.
   */
  public function testVendorDirAcceptsAbsolutePath(): void {
    // Never stat()ed, so it need not exist.
    $this->writeComposerManifest(['config' => ['vendor-dir' => '/opt/shared/vendor']]);

    $this->assertSame('/opt/shared/vendor', $this->invokeVendorDir());
  }

  /**
   * Verifies the vendor-dir default when composer.json says nothing useful.
   *
   * @param string $manifest
   *   Raw composer.json contents to write.
   */
  #[DataProvider('vendorDirDefaultCases')]
  public function testVendorDirFallsBackToDefault(string $manifest): void {
    file_put_contents($this->projectRoot() . '/composer.json', $manifest);

    $this->assertSame($this->projectRoot() . '/vendor', $this->invokeVendorDir());
  }

  /**
   * Supplies composer.json contents that should yield the default vendor dir.
   *
   * @return array<string, array{string}>
   *   Test cases keyed by description.
   */
  public static function vendorDirDefaultCases(): array {
    return [
      'no config section' => ['{"name":"acme/site"}'],
      'config without vendor-dir' => ['{"config":{"sort-packages":true}}'],
      'empty vendor-dir' => ['{"config":{"vendor-dir":""}}'],
      'non-string vendor-dir' => ['{"config":{"vendor-dir":["vendor"]}}'],
      'malformed json' => ['{not valid json'],
    ];
  }

  /**
   * Verifies the vendor-dir default when there is no composer.json at all.
   */
  public function testVendorDirFallsBackWithoutComposerJson(): void {
    $this->assertSame($this->projectRoot() . '/vendor', $this->invokeVendorDir());
  }

  /**
   * Writes a composer.json into the temporary project root.
   *
   * @param array<string, mixed> $manifest
   *   Manifest contents to encode.
   */
  private function writeComposerManifest(array $manifest): void {
    file_put_contents(
      $this->projectRoot() . '/composer.json',
      (string) json_encode($manifest),
    );
  }

  /**
   * Invokes the protected vendorDir() against the temporary root.
   */
  private function invokeVendorDir(): string {
    $method = new \ReflectionMethod(SetupCommands::class, 'vendorDir');
    return $method->invoke(new SetupCommands(), $this->projectRoot());
  }

  /**
   * Creates the Composer-registered recipe bin in the temporary project.
   */
  private function createComposerBin(): void {
    mkdir($this->projectRoot() . '/vendor/bin', 0777, TRUE);
    touch($this->projectRoot() . '/vendor/bin/dr');
  }

  /**
   * Creates core/scripts/drupal under the temporary project's webroot.
   */
  private function createCoreScript(): void {
    $scripts = $this->projectRoot() . '/' . self::WEBROOT . '/core/scripts';
    mkdir($scripts, 0777, TRUE);
    touch($scripts . '/drupal');
  }

  /**
   * Invokes the protected requireRecipeRunner() against the temporary root.
   */
  private function invokeRequireRecipeRunner(): string {
    $method = new \ReflectionMethod(SetupCommands::class, 'requireRecipeRunner');
    return $method->invoke(
      new SetupCommands(),
      $this->projectRoot(),
      $this->projectRoot() . '/' . self::WEBROOT,
    );
  }

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
    $command->method('requireRecipeRunner')->willReturn("'/tmp/vendor/bin/dr'");
    $command->method('runShellCommand')
      ->willReturnCallback(
        static function (string $cmd, string $cwd) use (&$capturedCmds): void {
          $capturedCmds[] = [$cmd, $cwd];
        }
      );

    $command->setup(['profile' => '', 'existing-config' => FALSE]);

    $this->assertCount(2, $capturedCmds);
    $this->assertStringContainsString("'/tmp/vendor/bin/dr' recipe '", $capturedCmds[0][0]);
    $this->assertStringContainsString('recipes/foo', $capturedCmds[0][0]);
    $this->assertStringContainsString("'/tmp/vendor/bin/dr' recipe '", $capturedCmds[1][0]);
    $this->assertStringContainsString('recipes/bar', $capturedCmds[1][0]);
  }

  /**
   * Verifies recipes are applied from the Drupal root, not the project root.
   *
   * The core/scripts/drupal fallback loads its container from the relative
   * path core/core.services.yml, so running it from anywhere else fails with
   * "The service file is not valid". Recipe paths stay absolute so the cwd
   * cannot affect which recipe is resolved.
   */
  public function testSetupAppliesRecipesFromDrupalRoot(): void {
    $captured = [];
    $command = $this->buildCommand($this->makeConfigLoader(
      ['root' => 'docroot'],
      ['recipes' => ['recipes/foo']],
    ));
    $command->method('requireRecipeRunner')->willReturn("'dr'");
    $command->method('runShellCommand')
      ->willReturnCallback(
        static function (string $cmd, string $cwd) use (&$captured): void {
          $captured[] = [$cmd, $cwd];
        }
      );

    $command->setup(['profile' => '', 'existing-config' => FALSE]);

    $this->assertSame('/tmp/docroot', $captured[0][1]);
    $this->assertStringContainsString("recipe '/tmp/recipes/foo'", $captured[0][0]);
  }

  /**
   * Verifies the runner is resolved against the configured drupal.root.
   *
   * The core/scripts/drupal fallback lives under the webroot, so a project
   * that renames it must still get a correct lookup path.
   */
  public function testSetupResolvesRunnerAgainstConfiguredDrupalRoot(): void {
    $command = $this->buildCommand($this->makeConfigLoader(
      ['root' => 'docroot'],
      ['recipes' => ['recipes/foo']],
    ));
    $command->expects($this->once())
      ->method('requireRecipeRunner')
      ->with('/tmp', '/tmp/docroot')
      ->willReturn("'dr'");

    $command->setup(['profile' => '', 'existing-config' => FALSE]);
  }

  /**
   * Verifies setup() does not resolve a recipe runner when none are configured.
   *
   * Consumers who never set setup.recipes should not be forced to have
   * drupal/core's recipe entry point available.
   */
  public function testSetupSkipsRecipeRunnerLookupWhenNoRecipes(): void {
    $command = $this->buildCommand($this->makeConfigLoader());
    $command->expects($this->never())->method('requireRecipeRunner');

    $command->setup(['profile' => '', 'existing-config' => FALSE]);
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
        'requireRecipeRunner',
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
