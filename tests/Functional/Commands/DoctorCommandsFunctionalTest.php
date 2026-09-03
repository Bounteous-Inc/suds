<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Functional\Commands;

use Bounteous\Suds\Drush\Commands\DoctorCommands;
use Bounteous\Suds\Tests\Support\FunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Functional tests for DoctorCommands.
 *
 * These tests shell out to the Drush binary and require a provisioned
 * Drupal installation in the sut/ directory. Before running:
 *
 * @code
 * composer sut:si
 * composer test:functional
 * @endcode
 *
 * Each test uses a temporary directory as the project root so that
 * real files on disk are controlled by the test. Binary detection
 * relies on the actual system PATH available in the test environment.
 */
#[CoversClass(DoctorCommands::class)]
class DoctorCommandsFunctionalTest extends FunctionalTestCase {

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
    $this->tmpDir = $this->createTempDir('suds_doctor_func_');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    parent::tearDown();
    $this->removeDirectory($this->tmpDir);
  }

  /**
   * Tests that suds:doctor exits cleanly in a healthy project.
   *
   * Creates a temporary project root with suds.yml, the expected Drupal
   * directory structure, and quality tooling config files so that all
   * quality checks pass. Exit code 0 is asserted implicitly — drush() fails
   * the test when the expected return code is not met.
   */
  public function testDoctorExitsCleanlyInHealthyProject(): void {
    mkdir($this->tmpDir . '/web/core', 0755, TRUE);
    file_put_contents($this->tmpDir . '/grumphp.yml', '');
    file_put_contents($this->tmpDir . '/phpcs.xml.dist', '');
    file_put_contents($this->tmpDir . '/phpstan.neon', '');
    file_put_contents(
      $this->tmpDir . '/suds.yml',
      Yaml::dump([
        'project' => ['name' => 'Functional Test'],
        'drupal'  => ['root' => 'web'],
        'deploy'  => ['repo' => ['url' => 'git@example.com:example.git']],
        'sync'    => ['default_source' => '@prod'],
      ], 4, 2),
    );

    $this->drush(
      'suds:doctor',
      [],
      ['root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );
    $this->assertStringContainsString(
      'SUDS: Checking Environment',
      $this->getOutput(),
    );
  }

  /**
   * Tests that suds:doctor exits non-zero when drupal.root is missing.
   *
   * A missing drupal.root directory is a FAIL-level check, so the command
   * must exit non-zero even when all other checks pass.
   */
  public function testDoctorFailsWhenDrupalRootMissing(): void {
    // suds.yml points to web/ but we do not create the directory.
    file_put_contents(
      $this->tmpDir . '/suds.yml',
      Yaml::dump(['drupal' => ['root' => 'web']], 4, 2),
    );

    $this->drush(
      'suds:doctor',
      [],
      ['root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
      1,
    );
    $this->assertStringContainsString(
      '[FAIL]',
      $this->getOutput(),
    );
  }

  /**
   * Tests that suds:doctor is reachable via its su-doctor alias.
   */
  public function testDoctorAliasExitsCleanly(): void {
    mkdir($this->tmpDir . '/web/core', 0755, TRUE);
    file_put_contents($this->tmpDir . '/grumphp.yml', '');
    file_put_contents($this->tmpDir . '/phpcs.xml.dist', '');
    file_put_contents($this->tmpDir . '/phpstan.neon', '');
    file_put_contents(
      $this->tmpDir . '/suds.yml',
      Yaml::dump([
        'project' => ['name' => 'Functional Test'],
        'drupal'  => ['root' => 'web'],
        'deploy'  => ['repo' => ['url' => 'git@example.com:example.git']],
        'sync'    => ['default_source' => '@prod'],
      ], 4, 2),
    );

    $this->drush(
      'su-doctor',
      [],
      ['root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );
    $this->assertStringContainsString(
      'SUDS: Checking Environment',
      $this->getOutput(),
    );
  }

}
