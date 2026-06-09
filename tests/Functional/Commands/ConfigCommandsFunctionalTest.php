<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Functional\Commands;

use Bounteous\Suds\Drush\Commands\ConfigCommands;
use Bounteous\Suds\Tests\Support\TempDirectoryTrait;
use Drush\TestTraits\DrushTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Functional tests for ConfigCommands.
 *
 * These tests shell out to the Drush binary and require a provisioned
 * Drupal installation in the sut/ directory. Before running:
 *
 * @code
 * composer sut:si
 * composer test:functional
 * @endcode
 *
 * The default dump tests run from the SUT root with no suds.yml and verify
 * SUDS's built-in defaults are output correctly (resolved == defaults when
 * no suds.yml exists).
 *
 * The project-config tests create a temporary project directory containing a
 * suds.yml and pass --root to point Drush at the SUT, allowing the config
 * loader to discover suds.yml from getcwd() while Drush bootstraps Drupal
 * from the SUT.
 */
#[CoversClass(ConfigCommands::class)]
class ConfigCommandsFunctionalTest extends TestCase {

  use DrushTestTrait;
  use TempDirectoryTrait;

  /**
   * Temporary project directory used for resolved-config tests.
   */
  private string $tempProjectRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->tempProjectRoot = $this->createTempDir('suds_func_');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    parent::tearDown();
    $this->removeDirectory($this->tempProjectRoot);
  }

  /**
   * Returns the path to the SUT Drupal root.
   */
  protected function getSutRoot(): string {
    return dirname(__DIR__, 3) . '/sut';
  }

  /**
   * Tests that suds:config:dump exits cleanly and produces output.
   *
   * Exit code 0 is asserted implicitly by drush() via its $expected_return
   * parameter (default 0). A non-zero exit would fail the test there.
   */
  public function testDumpExitsCleanly(): void {
    $this->drush('suds:config:dump', [], [], NULL, $this->getSutRoot());
    $this->assertNotEmpty($this->getOutput());
  }

  /**
   * Tests that suds:config:dump output contains expected top-level keys.
   */
  public function testDumpOutputContainsExpectedKeys(): void {
    $this->drush('suds:config:dump', [], [], NULL, $this->getSutRoot());
    $output = $this->getOutput();

    foreach (['project:', 'drupal:', 'sync:', 'deploy:', 'setup:'] as $key) {
      $this->assertStringContainsString($key, $output, "Output missing YAML key: $key");
    }
  }

  /**
   * Tests that suds:config:dump output is parseable YAML.
   */
  public function testDumpOutputIsParseableYaml(): void {
    $this->drush('suds:config:dump', [], [], NULL, $this->getSutRoot());
    $parsed = Yaml::parse($this->getOutput());

    $this->assertIsArray($parsed);
    $this->assertArrayHasKey('drupal', $parsed);
    $this->assertArrayHasKey('sync', $parsed);
  }

  /**
   * Tests that the default drupal.root is 'web'.
   */
  public function testDumpDefaultDrupalRoot(): void {
    $this->drush('suds:config:dump', [], [], NULL, $this->getSutRoot());
    $parsed = Yaml::parse($this->getOutput());

    $this->assertSame('web', $parsed['drupal']['root']);
  }

  /**
   * Tests that the default truncate_tables list contains Drupal cache tables.
   */
  public function testDumpDefaultTruncateTablesContainsDrupalCacheTables(): void {
    $this->drush('suds:config:dump', [], [], NULL, $this->getSutRoot());
    $parsed = Yaml::parse($this->getOutput());

    $tables = $parsed['sync']['db']['truncate_tables'];
    $this->assertIsArray($tables);
    $this->assertNotEmpty($tables);
    $this->assertContains('cache_default', $tables);
    $this->assertContains('watchdog', $tables);
    $this->assertContains('sessions', $tables);
  }

  /**
   * Tests that --defaults shows built-in defaults regardless of project config.
   *
   * Exit code 0 is asserted implicitly by drush() via its $expected_return
   * parameter (default 0).
   */
  public function testDumpWithDefaultsFlagShowsDefaults(): void {
    file_put_contents(
      $this->tempProjectRoot . '/suds.yml',
      Yaml::dump(['drupal' => ['root' => 'docroot']], 4, 2),
    );

    $this->drush(
      'suds:config:dump',
      [],
      ['defaults' => TRUE, 'root' => $this->getSutRoot()],
      NULL,
      $this->tempProjectRoot,
    );

    $parsed = Yaml::parse($this->getOutput());
    // Project override must NOT appear when --defaults is used.
    $this->assertSame('web', $parsed['drupal']['root']);
  }

  /**
   * Tests that suds:config:dump merges a project config override by default.
   *
   * Runs Drush with --root pointing to the SUT while $cd is a temp directory
   * containing a suds.yml. ConfigLoader discovers the project config from
   * getcwd() while Drush bootstraps Drupal from the SUT root.
   */
  public function testDumpMergesProjectConfig(): void {
    file_put_contents(
      $this->tempProjectRoot . '/suds.yml',
      Yaml::dump(['drupal' => ['root' => 'docroot']], 4, 2),
    );

    $this->drush(
      'suds:config:dump',
      [],
      ['root' => $this->getSutRoot()],
      NULL,
      $this->tempProjectRoot,
    );

    $parsed = Yaml::parse($this->getOutput());
    $this->assertSame('docroot', $parsed['drupal']['root']);
  }

  /**
   * Tests that passing a KEY argument outputs only that key's value.
   */
  public function testDumpWithKeyOutputsValue(): void {
    $this->drush(
      'suds:config:dump',
      ['drupal.root'],
      [],
      NULL,
      $this->getSutRoot(),
    );
    $this->assertStringContainsString('web', $this->getOutput());
  }

  /**
   * Tests that passing an unknown KEY argument exits non-zero.
   *
   * Drush exits with a non-zero status when the command throws
   * InvalidArgumentException for an unknown config key.
   */
  public function testDumpWithMissingKeyExitsNonZero(): void {
    $this->drush(
      'suds:config:dump',
      ['no.such.key'],
      ['root' => $this->getSutRoot()],
      NULL,
      $this->getSutRoot(),
      1,
    );
    $this->assertStringContainsString('not found', $this->getErrorOutput());
  }

  /**
   * Tests that dump preserves default keys not overridden by the project.
   */
  public function testDumpPreservesUnrelatedDefaults(): void {
    file_put_contents(
      $this->tempProjectRoot . '/suds.yml',
      Yaml::dump(['drupal' => ['root' => 'docroot']], 4, 2),
    );

    $this->drush(
      'suds:config:dump',
      [],
      ['root' => $this->getSutRoot()],
      NULL,
      $this->tempProjectRoot,
    );

    $parsed = Yaml::parse($this->getOutput());
    // drupal.profile comes from defaults; must still be present.
    $this->assertArrayHasKey('profile', $parsed['drupal']);
  }

}
