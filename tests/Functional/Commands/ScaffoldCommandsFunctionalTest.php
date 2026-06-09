<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Functional\Commands;

use Bounteous\Suds\Drush\Commands\ScaffoldCommands;
use Bounteous\Suds\Tests\Support\TempDirectoryTrait;
use Drush\TestTraits\DrushTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Functional tests for ScaffoldCommands.
 *
 * These tests shell out to the Drush binary and require a provisioned
 * Drupal installation in the sut/ directory. Before running:
 *
 * @code
 * composer sut:si
 * composer test:functional
 * @endcode
 *
 * Each test uses a fresh temporary directory as the working directory so that
 * scaffolded files are never written to the actual SUT or project root.
 */
#[CoversClass(ScaffoldCommands::class)]
class ScaffoldCommandsFunctionalTest extends TestCase {

  use DrushTestTrait;
  use TempDirectoryTrait;

  /**
   * Temporary directory used as the fake project root per test.
   *
   * @var string
   */
  private string $tmpDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->tmpDir = $this->createTempDir('suds_scaffold_func_');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    parent::tearDown();
    $this->removeDirectory($this->tmpDir);
  }

  /**
   * Returns the path to the System Under Test Drupal root.
   *
   * @return string
   *   Absolute path to the SUT webroot.
   */
  protected function getSutRoot(): string {
    return dirname(__DIR__, 3) . '/sut';
  }

  /**
   * Tests that suds:scaffold:quality exits cleanly and creates all files.
   */
  public function testScaffoldQualityCreatesFiles(): void {
    $this->drush(
      'suds:scaffold:quality',
      [],
      ['drupal-root' => 'web', 'root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );

    $this->assertFileExists($this->tmpDir . '/grumphp.yml');
    $this->assertFileExists($this->tmpDir . '/phpcs.xml.dist');
    $this->assertFileExists($this->tmpDir . '/phpstan.neon');
  }

  /**
   * Tests that the scaffolded files contain the correct drupal root.
   */
  public function testScaffoldQualityWritesCorrectDrupalRoot(): void {
    $this->drush(
      'suds:scaffold:quality',
      [],
      ['drupal-root' => 'docroot', 'root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );

    foreach (['grumphp.yml', 'phpcs.xml.dist', 'phpstan.neon'] as $file) {
      $contents = file_get_contents($this->tmpDir . '/' . $file);
      $this->assertIsString($contents);
      $this->assertStringContainsString('docroot', $contents, $file);
      $this->assertStringNotContainsString('{{ drupal_root }}', $contents, $file);
    }
  }

  /**
   * Tests that suds:scaffold:quality reads drupal.root from suds.yml.
   */
  public function testScaffoldQualityReadsDrupalRootFromSudsYml(): void {
    file_put_contents($this->tmpDir . '/suds.yml', "drupal:\n  root: html\n");

    $this->drush(
      'suds:scaffold:quality',
      [],
      ['root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );

    $contents = file_get_contents($this->tmpDir . '/phpcs.xml.dist');
    $this->assertIsString($contents);
    $this->assertStringContainsString('html', $contents);
  }

  /**
   * Tests that suds:scaffold:quality skips existing files without --force.
   */
  public function testScaffoldQualitySkipsExistingFiles(): void {
    file_put_contents($this->tmpDir . '/grumphp.yml', '# sentinel');

    $this->drush(
      'suds:scaffold:quality',
      [],
      ['drupal-root' => 'web', 'root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );

    $this->assertSame(
      '# sentinel',
      file_get_contents($this->tmpDir . '/grumphp.yml'),
    );
  }

  /**
   * Tests that --force overwrites existing quality files.
   */
  public function testScaffoldQualityOverwritesWithForce(): void {
    file_put_contents($this->tmpDir . '/grumphp.yml', '# sentinel');

    $this->drush(
      'suds:scaffold:quality',
      [],
      ['drupal-root' => 'web', 'force' => TRUE, 'root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );

    $this->assertNotSame(
      '# sentinel',
      file_get_contents($this->tmpDir . '/grumphp.yml'),
    );
  }

  /**
   * Tests that suds:init creates quality tooling files by default.
   */
  public function testInitCreatesQualityFiles(): void {
    $this->drush(
      'suds:init',
      [],
      [
        'name'        => 'Quality Test',
        'drupal-root' => 'web',
        'root'        => $this->getSutRoot(),
      ],
      NULL,
      $this->tmpDir,
    );

    $this->assertFileExists($this->tmpDir . '/grumphp.yml');
    $this->assertFileExists($this->tmpDir . '/phpcs.xml.dist');
    $this->assertFileExists($this->tmpDir . '/phpstan.neon');
  }

  /**
   * Tests that --skip-quality prevents quality file creation.
   */
  public function testInitSkipsQualityFilesWhenFlagSet(): void {
    $this->drush(
      'suds:init',
      [],
      [
        'name'         => 'Quality Test',
        'drupal-root'  => 'web',
        'skip-quality' => TRUE,
        'skip-ci'      => TRUE,
        'root'         => $this->getSutRoot(),
      ],
      NULL,
      $this->tmpDir,
    );

    $this->assertFileDoesNotExist($this->tmpDir . '/grumphp.yml');
    $this->assertFileDoesNotExist($this->tmpDir . '/phpcs.xml.dist');
    $this->assertFileDoesNotExist($this->tmpDir . '/phpstan.neon');
  }

  /**
   * Tests that suds:scaffold:ci creates the pipeline and suds.ci.yml.
   *
   * @param string $provider
 *   CI provider identifier.
   * @param string $expectedPath
 *   Expected pipeline path relative to project root.
   */
  #[DataProvider('ciProviderProvider')]
  public function testScaffoldCiCreatesFiles(
    string $provider,
    string $expectedPath,
  ): void {
    $this->drush(
      'suds:scaffold:ci',
      [$provider],
      ['root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );

    $this->assertFileExists($this->tmpDir . '/' . $expectedPath);
    $this->assertFileExists($this->tmpDir . '/suds.ci.yml');
  }

  /**
   * Tests that the php_version token is substituted in the pipeline file.
   *
   * @param string $provider
 *   CI provider identifier.
   * @param string $expectedPath
 *   Expected pipeline path relative to project root.
   */
  #[DataProvider('ciProviderProvider')]
  public function testScaffoldCiSubstitutesPhpVersionToken(
    string $provider,
    string $expectedPath,
  ): void {
    $this->drush(
      'suds:scaffold:ci',
      [$provider],
      ['root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );

    $contents = file_get_contents($this->tmpDir . '/' . $expectedPath);
    $this->assertIsString($contents);
    $this->assertStringNotContainsString('{{ php_version }}', $contents, $provider);
  }

  /**
   * Tests that suds:scaffold:ci reads the PHP version from composer.json.
   */
  public function testScaffoldCiReadPhpVersionFromComposerJson(): void {
    file_put_contents(
      $this->tmpDir . '/composer.json',
      json_encode(['require' => ['php' => '>=8.2']]),
    );

    $this->drush(
      'suds:scaffold:ci',
      ['github'],
      ['root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );

    $contents = file_get_contents(
      $this->tmpDir . '/.github/workflows/ci.yml',
    );
    $this->assertIsString($contents);
    $this->assertStringContainsString('8.2', $contents);
  }

  /**
   * Tests that existing CI files are not overwritten without --force.
   */
  public function testScaffoldCiSkipsExistingFiles(): void {
    $pipelinePath = $this->tmpDir . '/.github/workflows/ci.yml';
    mkdir(dirname($pipelinePath), 0755, TRUE);
    file_put_contents($pipelinePath, '# sentinel');

    $this->drush(
      'suds:scaffold:ci',
      ['github'],
      ['root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );

    $this->assertSame('# sentinel', file_get_contents($pipelinePath));
  }

  /**
   * Tests that --force overwrites existing CI files.
   */
  public function testScaffoldCiOverwritesWithForce(): void {
    $pipelinePath = $this->tmpDir . '/.github/workflows/ci.yml';
    mkdir(dirname($pipelinePath), 0755, TRUE);
    file_put_contents($pipelinePath, '# sentinel');

    $this->drush(
      'suds:scaffold:ci',
      ['github'],
      ['force' => TRUE, 'root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );

    $this->assertNotSame('# sentinel', file_get_contents($pipelinePath));
  }

  /**
   * Data provider: CI provider identifier and expected target path.
   *
   * @return array<string, array{string, string}>
   *   Keyed by provider name; values are [provider, expectedPath].
   */
  public static function ciProviderProvider(): array {
    return [
      'github'    => ['github', '.github/workflows/ci.yml'],
      'gitlab'    => ['gitlab', '.gitlab-ci.yml'],
      'bitbucket' => ['bitbucket', 'bitbucket-pipelines.yml'],
    ];
  }

}
