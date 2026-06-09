<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Unit\Config;

use Bounteous\Suds\Config\ConfigLoader;
use Bounteous\Suds\Tests\Support\TempDirectoryTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit tests for ConfigLoader.
 */
#[CoversClass(ConfigLoader::class)]
class ConfigLoaderTest extends TestCase {

  use TempDirectoryTrait;

  /**
   * Temporary project directory.
   *
   * @var string
   */
  private string $projectRoot;

  /**
   * Temporary package root directory with config/suds.defaults.yml.
   *
   * @var string
   */
  private string $packageRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->projectRoot = $this->createTempDir('suds_project_');
    $this->packageRoot = $this->createTempDir('suds_package_');
    mkdir($this->packageRoot . '/config', 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    parent::tearDown();
    $this->removeDirectory($this->projectRoot);
    $this->removeDirectory($this->packageRoot);
  }

  /**
   * Verifies the real defaults file loads and contains expected top-level keys.
   */
  public function testGetDefaultsFromRealFile(): void {
    $loader = new ConfigLoader($this->projectRoot, dirname(__DIR__, 3));
    $defaults = $loader->getDefaults();

    foreach (['project', 'drupal', 'sync', 'deploy', 'setup'] as $key) {
      $this->assertArrayHasKey($key, $defaults, "Defaults missing top-level key: $key");
    }
  }

  /**
   * Verifies drupal.root defaults to 'web'.
   */
  public function testDefaultDrupalRoot(): void {
    $loader = new ConfigLoader($this->projectRoot, dirname(__DIR__, 3));
    $defaults = $loader->getDefaults();
    $this->assertSame('web', $defaults['drupal']['root']);
  }

  /**
   * Verifies load() returns defaults when no suds.yml is present.
   */
  public function testLoadReturnDefaultsWithNoProjectConfig(): void {
    $this->writeDefaults(['drupal' => ['root' => 'web']]);
    $loader = $this->loader();
    $this->assertSame('web', $loader->load()['drupal']['root']);
  }

  /**
   * Verifies a scalar override replaces the default value.
   */
  public function testLoadScalarOverrideReplacesDefault(): void {
    $this->writeDefaults(['drupal' => ['root' => 'web']]);
    $this->writeProjectConfig(['drupal' => ['root' => 'docroot']]);

    $config = $this->loader()->load();
    $this->assertSame('docroot', $config['drupal']['root']);
  }

  /**
   * Verifies a partial associative override preserves unrelated keys.
   */
  public function testLoadPartialAssocOverridePreservesOtherKeys(): void {
    $this->writeDefaults(['drupal' => ['root' => 'web', 'profile' => 'minimal']]);
    $this->writeProjectConfig(['drupal' => ['root' => 'docroot']]);

    $config = $this->loader()->load();
    $this->assertSame('minimal', $config['drupal']['profile']);
  }

  /**
   * Verifies a list override replaces the default list entirely.
   */
  public function testLoadListReplaces(): void {
    $this->writeDefaults(['sync' => ['db' => ['truncate_tables' => ['cache', 'watchdog']]]]);
    $this->writeProjectConfig(['sync' => ['db' => ['truncate_tables' => ['my_table']]]]);

    $config = $this->loader()->load();
    $this->assertSame(['my_table'], $config['sync']['db']['truncate_tables']);
  }

  /**
   * Verifies an empty list in project config replaces the default list with [].
   */
  public function testLoadEmptyListReplacesDefaultList(): void {
    $this->writeDefaults(['sync' => ['db' => ['truncate_tables' => ['cache', 'watchdog']]]]);
    $this->writeProjectConfig(['sync' => ['db' => ['truncate_tables' => []]]]);

    $config = $this->loader()->load();
    $this->assertSame([], $config['sync']['db']['truncate_tables']);
  }

  /**
   * Verifies suds.local.yml is merged on top of suds.yml.
   */
  public function testLoadLocalConfigOverridesProjectConfig(): void {
    $this->writeDefaults(['drupal' => ['root' => 'web']]);
    $this->writeProjectConfig(['drupal' => ['root' => 'docroot']]);
    $this->writeLocalConfig(['drupal' => ['root' => 'local_web']]);

    $config = $this->loader()->load();
    $this->assertSame('local_web', $config['drupal']['root']);
  }

  /**
   * Verifies local config is silently skipped when absent.
   */
  public function testLoadWithNoLocalConfigFile(): void {
    $this->writeDefaults(['drupal' => ['root' => 'web']]);
    $this->writeProjectConfig(['drupal' => ['root' => 'docroot']]);

    $config = $this->loader()->load();
    $this->assertSame('docroot', $config['drupal']['root']);
  }

  /**
   * Verifies suds.ci.yml is merged when the CI environment variable is set.
   */
  public function testLoadMergesCiConfigWhenCiEnvSet(): void {
    $this->writeDefaults(['drupal' => ['root' => 'web']]);
    file_put_contents(
      $this->projectRoot . '/suds.ci.yml',
      Yaml::dump(['deploy' => ['git' => ['name' => 'CI Bot']]], 4, 2),
    );

    $originalCi = getenv('CI');
    putenv('CI=true');
    try {
      $config = $this->loader()->load();
    }
    finally {
      $originalCi !== FALSE ? putenv('CI=' . $originalCi) : putenv('CI');
    }

    $this->assertSame('CI Bot', $config['deploy']['git']['name']);
  }

  /**
   * Verifies suds.ci.yml is skipped when the CI env variable is absent.
   */
  public function testLoadSkipsCiConfigWhenCiEnvNotSet(): void {
    $this->writeDefaults(['drupal' => ['root' => 'web']]);
    file_put_contents(
      $this->projectRoot . '/suds.ci.yml',
      Yaml::dump(['deploy' => ['git' => ['name' => 'CI Bot']]], 4, 2),
    );

    $originalCi = getenv('CI');
    putenv('CI');
    try {
      $config = $this->loader()->load();
    }
    finally {
      $originalCi !== FALSE ? putenv('CI=' . $originalCi) : putenv('CI');
    }

    // Without CI set, suds.ci.yml is ignored; the default name remains.
    $this->assertNotSame('CI Bot', $config['deploy']['git']['name'] ?? NULL);
  }

  /**
   * Verifies hasProjectConfig() returns TRUE when suds.yml exists.
   */
  public function testHasProjectConfigReturnsTrueWhenSudsYmlExists(): void {
    $this->writeProjectConfig(['project' => ['name' => 'test']]);
    $this->assertTrue($this->loader()->hasProjectConfig());
  }

  /**
   * Verifies hasProjectConfig() returns FALSE when suds.yml is absent.
   */
  public function testHasProjectConfigReturnsFalseWhenNoSudsYml(): void {
    $this->assertFalse($this->loader()->hasProjectConfig());
  }

  /**
   * Verifies getUnknownKeys() returns empty when all config keys are known.
   */
  public function testGetUnknownKeysReturnsEmptyWhenAllKeysKnown(): void {
    $this->writeDefaults(['drupal' => ['root' => 'web']]);
    $this->writeProjectConfig(['drupal' => ['root' => 'docroot']]);

    $this->assertSame([], $this->loader()->getUnknownKeys());
  }

  /**
   * Verifies getUnknownKeys() returns empty when no project config files exist.
   */
  public function testGetUnknownKeysReturnsEmptyWithNoProjectConfig(): void {
    $this->writeDefaults(['drupal' => ['root' => 'web']]);

    $this->assertSame([], $this->loader()->getUnknownKeys());
  }

  /**
   * Verifies getUnknownKeys() reports a typo'd key in suds.yml.
   */
  public function testGetUnknownKeysReportsTypoInSudsYml(): void {
    $this->writeDefaults(['drupal' => ['root' => 'web']]);
    $this->writeProjectConfig(['drupal' => ['rot' => 'docroot']]);

    $unknown = $this->loader()->getUnknownKeys();
    $this->assertCount(1, $unknown);
    $this->assertSame('suds.yml', $unknown[0]['file']);
    $this->assertSame('drupal.rot', $unknown[0]['key']);
  }

  /**
   * Verifies getUnknownKeys() reports typo'd keys in suds.local.yml.
   */
  public function testGetUnknownKeysReportsTypoInLocalYml(): void {
    $this->writeDefaults(['drupal' => ['root' => 'web']]);
    $this->writeLocalConfig(['drupal' => ['rot' => 'local']]);

    $unknown = $this->loader()->getUnknownKeys();
    $this->assertCount(1, $unknown);
    $this->assertSame('suds.local.yml', $unknown[0]['file']);
    $this->assertSame('drupal.rot', $unknown[0]['key']);
  }

  /**
   * Verifies getUnknownKeys() does not report items inside list values.
   *
   * List values (indexed arrays) are treated as leaves, so their
   * contents are not compared against the defaults schema.
   */
  public function testGetUnknownKeysDoesNotReportListItems(): void {
    $this->writeDefaults(['sync' => ['db' => ['truncate_tables' => ['cache']]]]);
    $this->writeProjectConfig(['sync' => ['db' => ['truncate_tables' => ['my_table']]]]);

    $this->assertSame([], $this->loader()->getUnknownKeys());
  }

  /**
   * Verifies getUnknownKeys() checks suds.ci.yml when present.
   */
  public function testGetUnknownKeysChecksCiYml(): void {
    $this->writeDefaults(['drupal' => ['root' => 'web']]);
    file_put_contents(
      $this->projectRoot . '/suds.ci.yml',
      Yaml::dump(['drupal' => ['rot' => 'docroot']], 4, 2),
    );

    $unknown = $this->loader()->getUnknownKeys();
    $this->assertCount(1, $unknown);
    $this->assertSame('suds.ci.yml', $unknown[0]['file']);
    $this->assertSame('drupal.rot', $unknown[0]['key']);
  }

  /**
   * Verifies getTypeErrors() returns empty when no project config exists.
   */
  public function testGetTypeErrorsReturnsEmptyWithNoProjectConfig(): void {
    $this->writeDefaults(['sync' => ['db' => ['sanitize' => TRUE]]]);
    $this->assertSame([], $this->loader()->getTypeErrors());
  }

  /**
   * Verifies getTypeErrors() returns empty when all types match defaults.
   */
  public function testGetTypeErrorsReturnsEmptyWhenTypesMatch(): void {
    $this->writeDefaults(['drupal' => ['root' => 'web', 'sanitize' => TRUE]]);
    $this->writeProjectConfig(['drupal' => ['root' => 'docroot', 'sanitize' => FALSE]]);
    $this->assertSame([], $this->loader()->getTypeErrors());
  }

  /**
   * Verifies getTypeErrors() reports a string key set to a boolean.
   */
  public function testGetTypeErrorsReportsBoolForString(): void {
    $this->writeDefaults(['drupal' => ['root' => 'web']]);
    $this->writeProjectConfig(['drupal' => ['root' => TRUE]]);

    $errors = $this->loader()->getTypeErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('suds.yml', $errors[0]['file']);
    $this->assertSame('drupal.root', $errors[0]['key']);
    $this->assertSame('string', $errors[0]['expected']);
    $this->assertSame('bool', $errors[0]['actual']);
  }

  /**
   * Verifies getTypeErrors() reports a bool key set to a string.
   */
  public function testGetTypeErrorsReportsStringForBool(): void {
    $this->writeDefaults(['sync' => ['db' => ['sanitize' => TRUE]]]);
    $this->writeProjectConfig(['sync' => ['db' => ['sanitize' => 'yes']]]);

    $errors = $this->loader()->getTypeErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('sync.db.sanitize', $errors[0]['key']);
    $this->assertSame('bool', $errors[0]['expected']);
    $this->assertSame('string', $errors[0]['actual']);
  }

  /**
   * Verifies getTypeErrors() reports a list-type key set to a scalar.
   */
  public function testGetTypeErrorsReportsScalarForListArray(): void {
    $this->writeDefaults(['sync' => ['db' => ['truncate_tables' => ['cache']]]]);
    $this->writeProjectConfig(['sync' => ['db' => ['truncate_tables' => 'cache']]]);

    $errors = $this->loader()->getTypeErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('sync.db.truncate_tables', $errors[0]['key']);
    $this->assertSame('array', $errors[0]['expected']);
    $this->assertSame('string', $errors[0]['actual']);
  }

  /**
   * Verifies getTypeErrors() skips keys where the default is null.
   */
  public function testGetTypeErrorsSkipsNullDefaults(): void {
    $this->writeDefaults(['sync' => ['default_source' => NULL]]);
    $this->writeProjectConfig(['sync' => ['default_source' => '@prod']]);
    $this->assertSame([], $this->loader()->getTypeErrors());
  }

  /**
   * Verifies getTypeErrors() reports type errors from suds.local.yml.
   */
  public function testGetTypeErrorsChecksLocalYml(): void {
    $this->writeDefaults(['drupal' => ['root' => 'web']]);
    $this->writeLocalConfig(['drupal' => ['root' => 42]]);

    $errors = $this->loader()->getTypeErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('suds.local.yml', $errors[0]['file']);
    $this->assertSame('drupal.root', $errors[0]['key']);
  }

  /**
   * Returns a ConfigLoader using the temporary project and package directories.
   */
  private function loader(): ConfigLoader {
    return new ConfigLoader($this->projectRoot, $this->packageRoot);
  }

  /**
   * Writes the defaults YAML to the package's config directory.
   *
   * @param array<string, mixed> $config
   *   The config array to write as defaults.
   */
  private function writeDefaults(array $config): void {
    file_put_contents(
      $this->packageRoot . '/config/suds.defaults.yml',
      Yaml::dump($config, 4, 2),
    );
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

  /**
   * Writes suds.local.yml to the temporary project directory.
   *
   * @param array<string, mixed> $config
   *   The config array to write.
   */
  private function writeLocalConfig(array $config): void {
    file_put_contents(
      $this->projectRoot . '/suds.local.yml',
      Yaml::dump($config, 4, 2),
    );
  }

}
