<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Functional\Commands;

use Bounteous\Suds\Drush\Commands\InitCommands;
use Bounteous\Suds\Tests\Support\FunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Functional tests for InitCommands.
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
 * suds.yml is never written to the actual SUT or project root.
 */
#[CoversClass(InitCommands::class)]
class InitCommandsFunctionalTest extends FunctionalTestCase {

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
    $this->tmpDir = $this->createTempDir('suds_init_func_');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    parent::tearDown();
    $this->removeDirectory($this->tmpDir);
  }

  /**
   * Tests that suds:init exits cleanly and outputs the project name.
   *
   * A successful drush() call implicitly asserts exit code 0 because the
   * trait fails the test when the expected return code is not met.
   */
  public function testInitOutputsProjectName(): void {
    $this->drush(
      'suds:init',
      [],
      ['name' => 'Functional Test Project', 'root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );
    // DrushStyle::success() forces output to stderr to avoid interfering with
    // formatted output, so the project name appears in getErrorOutput().
    $this->assertStringContainsString(
      'Functional Test Project',
      $this->getErrorOutput(),
    );
  }

  /**
   * Tests that suds:init creates suds.yml in the working directory.
   */
  public function testInitCreatesSudsYml(): void {
    $this->drush(
      'suds:init',
      [],
      ['name' => 'Functional Test Project', 'root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );
    $this->assertFileExists($this->tmpDir . '/suds.yml');
  }

  /**
   * Tests that --drupal-root writes the specified webroot to suds.yml.
   */
  public function testInitWithDrupalRootOption(): void {
    $this->drush(
      'suds:init',
      [],
      [
        'name'        => 'Functional Test Project',
        'drupal-root' => 'docroot',
        'root'        => $this->getSutRoot(),
      ],
      NULL,
      $this->tmpDir,
    );
    $contents = file_get_contents($this->tmpDir . '/suds.yml');
    $this->assertIsString($contents);
    $this->assertStringContainsString('root: docroot', $contents);
  }

  /**
   * Tests that suds:init auto-detects a single webroot candidate.
   */
  public function testInitAutoDetectsDrupalRoot(): void {
    mkdir($this->tmpDir . '/web', 0755, TRUE);

    $this->drush(
      'suds:init',
      [],
      ['name' => 'Auto Detect Project', 'root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );
    $contents = file_get_contents($this->tmpDir . '/suds.yml');
    $this->assertIsString($contents);
    $this->assertStringContainsString('root: web', $contents);
  }

}
