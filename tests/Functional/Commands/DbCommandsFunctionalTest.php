<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Functional\Commands;

use Bounteous\Suds\Drush\Commands\DbCommands;
use Bounteous\Suds\Tests\Support\FunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Functional tests for DbCommands.
 *
 * These tests shell out to the Drush binary and require a provisioned
 * Drupal installation in the sut/ directory. Before running:
 *
 * @code
 * composer sut:si
 * composer test:functional
 * @endcode
 *
 * suds:db:sanitize is exercised end-to-end against the live SUT database.
 * suds:db:sync requires a real remote source alias and is not covered here
 * (the integration tests verify its dispatch logic without a real alias).
 */
#[CoversClass(DbCommands::class)]
#[Group('drupal-version-sensitive')]
class DbCommandsFunctionalTest extends FunctionalTestCase {

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
    $this->tmpDir = $this->createTempDir('suds_db_func_');
    $this->writeSudsYml($this->tmpDir);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    parent::tearDown();
    $this->removeDirectory($this->tmpDir);
  }

  /**
   * Tests that suds:db:sanitize exits cleanly against the SUT database.
   *
   * Runs real drush sql:sanitize via the processManager. truncate_tables is
   * set to an empty list so no TRUNCATE TABLE queries are issued. Exit code 0
   * is asserted implicitly — drush() fails the test if the command exits
   * non-zero.
   */
  public function testDbSanitizeExitsCleanly(): void {
    $this->drush(
      'suds:db:sanitize',
      [],
      ['yes' => TRUE, 'root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );
    $this->assertStringContainsString(
      'SUDS: Sanitizing Database',
      $this->getOutput(),
    );
  }

  /**
   * Tests that suds:db:export creates a gzipped SQL file in the export dir.
   */
  public function testDbExportCreatesFile(): void {
    $exportDir = $this->tmpDir . '/db-exports';
    $this->drush(
      'suds:db:export',
      [],
      ['root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );
    $files = glob($exportDir . '/*.sql.gz');
    $this->assertNotEmpty($files, 'suds:db:export should create a .sql.gz file in db-exports/');
  }

  /**
   * Tests that suds:db:sanitize dispatches drush sql:sanitize.
   *
   * Verifies that the sql:sanitize sub-command is invoked by checking that
   * its confirmation prompt appears in the command output.
   */
  public function testDbSanitizeDispatchesSqlSanitize(): void {
    $this->drush(
      'suds:db:sanitize',
      [],
      ['yes' => TRUE, 'root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );
    $this->assertStringContainsString(
      'Sanitize',
      $this->getOutput(),
    );
  }

  /**
   * Writes a minimal suds.yml to the given directory.
   *
   * Truncate_tables is empty so no TRUNCATE TABLE queries are issued during
   * functional tests.
   *
   * @param string $dir
   *   The directory in which to write suds.yml.
   */
  private function writeSudsYml(string $dir): void {
    file_put_contents(
      $dir . '/suds.yml',
      Yaml::dump([
        'sync' => [
          'db' => [
            'truncate_tables'   => [],
            'sanitize_email'    => 'user+%uid@localhost',
            'sanitize_password' => 'password',
          ],
        ],
      ], 4, 2),
    );
  }

}
