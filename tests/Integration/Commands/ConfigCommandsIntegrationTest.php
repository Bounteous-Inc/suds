<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Integration\Commands;

use Bounteous\Suds\Config\ConfigLoader;
use Bounteous\Suds\Drush\Commands\ConfigCommands;
use Bounteous\Suds\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Yaml\Yaml;

/**
 * Integration tests for ConfigCommands.
 *
 * Commands are invoked through the Symfony Application API via
 * AnnotatedCommandFactory. A ConfigLoader is injected via setConfigLoader()
 * to control config state without filesystem side-effects.
 */
#[CoversClass(ConfigCommands::class)]
class ConfigCommandsIntegrationTest extends IntegrationTestCase {

  /**
   * The application tester.
   *
   * @var \Symfony\Component\Console\Tester\ApplicationTester
   */
  private ApplicationTester $tester;

  /**
   * The ConfigCommands instance under test.
   *
   * @var \Bounteous\Suds\Drush\Commands\ConfigCommands
   */
  private ConfigCommands $commandInstance;

  /**
   * Temporary project directory.
   *
   * @var string
   */
  private string $projectRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->projectRoot = $this->createTempDir('suds_config_integration_');
    $this->commandInstance = new ConfigCommands();
    $this->tester = $this->buildTester($this->commandInstance);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeDirectory($this->projectRoot);
  }

  /**
   * Tests that suds:config:dump is registered and exits cleanly.
   */
  public function testDumpIsRegisteredAndExitsCleanly(): void {
    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    $exitCode = $this->tester->run(['command' => 'suds:config:dump']);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that suds:config:dump outputs valid YAML containing expected keys.
   */
  public function testDumpOutputsYamlWithExpectedTopLevelKeys(): void {
    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    $this->tester->run(['command' => 'suds:config:dump']);
    $output = $this->tester->getDisplay();

    foreach (['project:', 'drupal:', 'sync:', 'deploy:', 'setup:'] as $key) {
      $this->assertStringContainsString($key, $output, "Output missing key: $key");
    }
  }

  /**
   * Tests that suds:config:dump output is parseable YAML.
   */
  public function testDumpOutputIsParseableYaml(): void {
    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    $this->tester->run(['command' => 'suds:config:dump']);
    $parsed = Yaml::parse($this->tester->getDisplay());
    $this->assertIsArray($parsed);
    $this->assertArrayHasKey('drupal', $parsed);
  }

  /**
   * Tests that dump merges a project config override by default.
   */
  public function testDumpMergesProjectConfigByDefault(): void {
    $this->writeProjectConfig(['drupal' => ['root' => 'docroot']]);
    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );

    $this->tester->run(['command' => 'suds:config:dump']);
    $parsed = Yaml::parse($this->tester->getDisplay());

    $this->assertSame('docroot', $parsed['drupal']['root']);
  }

  /**
   * Tests that --defaults ignores project config and shows built-in defaults.
   */
  public function testDumpWithDefaultsFlagIgnoresProjectConfig(): void {
    // Write a project config that changes drupal.root.
    $this->writeProjectConfig(['drupal' => ['root' => 'docroot']]);
    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );

    $this->tester->run(['command' => 'suds:config:dump', '--defaults' => TRUE]);
    $parsed = Yaml::parse($this->tester->getDisplay());

    // Default is 'web'; the project override must NOT appear.
    $this->assertSame('web', $parsed['drupal']['root']);
  }

  /**
   * Tests that suds:config:dump exits cleanly with no project config.
   */
  public function testDumpWithNoProjectConfigExitsCleanly(): void {
    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    $exitCode = $this->tester->run(['command' => 'suds:config:dump']);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that dump merges a scalar project config override.
   */
  public function testDumpMergesScalarOverride(): void {
    $this->writeProjectConfig(['drupal' => ['root' => 'docroot']]);
    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );

    $this->tester->run(['command' => 'suds:config:dump']);
    $parsed = Yaml::parse($this->tester->getDisplay());

    $this->assertSame('docroot', $parsed['drupal']['root']);
  }

  /**
   * Tests that dump preserves default keys not overridden by the project.
   */
  public function testDumpPreservesUnrelatedDefaults(): void {
    $this->writeProjectConfig(['drupal' => ['root' => 'docroot']]);
    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );

    $this->tester->run(['command' => 'suds:config:dump']);
    $parsed = Yaml::parse($this->tester->getDisplay());

    // drupal.profile comes from defaults; must still be present.
    $this->assertArrayHasKey('profile', $parsed['drupal']);
  }

  /**
   * Tests that dump reflects a list override (replace semantics).
   */
  public function testDumpListOverrideReplaces(): void {
    $this->writeProjectConfig([
      'sync' => ['db' => ['truncate_tables' => ['my_cache']]],
    ]);
    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );

    $this->tester->run(['command' => 'suds:config:dump']);
    $parsed = Yaml::parse($this->tester->getDisplay());

    $this->assertSame(['my_cache'], $parsed['sync']['db']['truncate_tables']);
  }

  /**
   * Tests that suds:config:dump with a key exits cleanly.
   */
  public function testDumpWithKeyExitsCleanly(): void {
    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    $exitCode = $this->tester->run([
      'command' => 'suds:config:dump',
      'key'     => 'drupal.root',
    ]);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that suds:config:dump with a key outputs just that value.
   */
  public function testDumpWithKeyOutputsValue(): void {
    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    $this->tester->run([
      'command' => 'suds:config:dump',
      'key'     => 'drupal.root',
    ]);
    $this->assertSame('web', trim($this->tester->getDisplay()));
  }

  /**
   * Tests that suds:config:dump with a missing key exits non-zero.
   */
  public function testDumpWithMissingKeyExitsNonZero(): void {
    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    $exitCode = $this->tester->run([
      'command' => 'suds:config:dump',
      'key'     => 'nonexistent.key',
    ]);
    $this->assertNotSame(0, $exitCode);
  }

  /**
   * Returns the package root path (contains config/suds.defaults.yml).
   */
  private function packageRoot(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * Writes suds.yml to the temporary project directory.
   *
   * @param array<string, mixed> $config
   *   The config array to write.
   */
  private function writeProjectConfig(array $config): void {
    file_put_contents(
      $this->projectRoot . '/suds.yml',
      Yaml::dump($config, 4, 2),
    );
  }

}
