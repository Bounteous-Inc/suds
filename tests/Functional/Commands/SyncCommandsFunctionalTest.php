<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Functional\Commands;

use Bounteous\Suds\Drush\Commands\SyncCommands;
use Bounteous\Suds\Tests\Support\FunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Functional tests for SyncCommands.
 *
 * These tests shell out to the Drush binary and require a provisioned
 * Drupal installation in the sut/ directory. Before running:
 *
 * @code
 * composer sut:si
 * composer test:functional
 * @endcode
 *
 * Full end-to-end sync tests (database + files) require a real remote source
 * alias. The tests here focus on the orchestration logic by skipping the db
 * and files steps, exercising the command entry point, config resolution, and
 * post-sync hook handling without a real remote connection. The sub-command
 * dispatch logic is covered by SyncCommandsIntegrationTest.
 */
#[CoversClass(SyncCommands::class)]
#[Group('drupal-version-sensitive')]
class SyncCommandsFunctionalTest extends FunctionalTestCase {

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
    $this->tmpDir = $this->createTempDir('suds_sync_func_');
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
   * Tests that suds:sync with --db-file exits cleanly and outputs title.
   *
   * Dumps the SUT database to a temp file and reimports it via --db-file so
   * no remote alias is required. Sanitize and files steps are disabled in
   * the fixture suds.yml. The reimport leaves the database in a valid state
   * so the suds:update step (cr + updb + cim) completes successfully.
   */
  public function testSyncWithFileExitsCleanly(): void {
    $sqlFile = $this->tmpDir . '/sut_dump.sql';
    $this->drush(
      'sql:dump',
      [],
      ['result-file' => $sqlFile, 'root' => $this->getSutRoot()],
    );

    $this->drush(
      'suds:sync',
      [],
      ['db-file' => $sqlFile, 'root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );

    $this->assertStringContainsString(
      'SUDS: Syncing Environment',
      $this->getOutput(),
    );
  }

  /**
   * Tests that suds:sync fails with a non-zero exit when no source is set.
   *
   * With no --skip-db flag and no source alias or default configured in
   * suds.yml, the command must exit non-zero and print an error.
   */
  public function testSyncFailsWithNoSourceConfigured(): void {
    $this->drush(
      'suds:sync',
      [],
      ['root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
      1,
    );
    $this->assertStringContainsString(
      'No source alias',
      $this->getErrorOutput(),
    );
  }

  /**
   * Writes a minimal suds.yml and composer.json to the given directory.
   *
   * Sync.db.sanitize is false so the sanitize step is skipped without needing
   * --skip-sanitize on the CLI (which would otherwise be forwarded via Drush
   * redispatch to child commands that don't accept it).
   * sync.files.enabled is false so the files sync step is skipped by default.
   * sync.hooks.post_sync is empty so no shell commands are spawned.
   * A minimal composer.json is written so that the "composer install" step in
   * suds:sync exits cleanly from the temporary project root.
   *
   * @param string $dir
   *   The directory in which to write the fixture files.
   */
  private function writeSudsYml(string $dir): void {
    file_put_contents(
      $dir . '/suds.yml',
      Yaml::dump([
        'sync' => [
          'db'    => ['sanitize' => FALSE],
          'files' => ['enabled' => FALSE],
          'hooks' => ['post_sync' => []],
        ],
      ], 4, 2),
    );
    file_put_contents(
      $dir . '/composer.json',
      json_encode(['name' => 'test/suds-sync-func', 'description' => 'Functional test fixture'], JSON_PRETTY_PRINT) . "\n",
    );
  }

}
