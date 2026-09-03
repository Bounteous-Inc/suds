<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Functional\Commands;

use Bounteous\Suds\Drush\Commands\UpdateCommands;
use Bounteous\Suds\Tests\Support\FunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional tests for UpdateCommands.
 *
 * These tests shell out to the Drush binary and require a provisioned
 * Drupal installation in the sut/ directory. Before running:
 *
 * @code
 * composer sut:si
 * composer test:functional
 * @endcode
 *
 * suds:update runs cache:rebuild, updatedb, and config:import twice
 * against the live SUT. These are idempotent operations that leave the
 * site in a consistent state after each run.
 */
#[CoversClass(UpdateCommands::class)]
#[Group('drupal-version-sensitive')]
class UpdateCommandsFunctionalTest extends FunctionalTestCase {

  /**
   * Absolute path to the config sync directory, once resolved.
   *
   * @var string|null
   */
  private ?string $syncDir = NULL;

  /**
   * Filenames present in the sync directory before this test exported.
   *
   * @var list<string>
   */
  private array $syncFilesBefore = [];

  /**
   * Tests that suds:update exits cleanly against the live SUT.
   *
   * Exit code 0 is asserted implicitly — drush() fails the test if the
   * command exits non-zero.
   */
  public function testUpdateExitsCleanly(): void {
    $this->drush(
      'suds:update',
      [],
      ['yes' => TRUE],
      NULL,
      $this->getSutRoot(),
    );
    $this->assertStringContainsString(
      'SUDS: Updating Environment',
      $this->getOutput(),
    );
  }

  /**
   * Tests that suds:update dispatches cache:rebuild.
   *
   * Verifies that the cache rebuild step runs by checking for its output.
   */
  public function testUpdateRebuildsCaches(): void {
    $this->drush(
      'suds:update',
      [],
      ['yes' => TRUE],
      NULL,
      $this->getSutRoot(),
    );
    // Drush routes cache:rebuild's completion message through its logger, which
    // writes to stderr. Assert on getErrorOutput() rather than getOutput().
    $this->assertStringContainsString(
      'Cache rebuild complete',
      $this->getErrorOutput(),
    );
  }

  /**
   * Tests that a mismatched site UUID aborts suds:update with guidance.
   *
   * Simulates an environment provisioned independently of the source database
   * by pointing the active site UUID at a bogus value, then asserts the update
   * refuses to run and explains how to proceed. Drupal rejects this import on
   * purpose, so SUDS surfaces it rather than silently overwriting the UUID.
   */
  public function testUpdateFailsOnMismatchedSiteUuid(): void {
    $syncUuid = $this->exportConfigAndGetSiteUuid();
    $this->setSiteUuid('00000000-0000-0000-0000-000000000000');

    try {
      $this->drush(
        'suds:update',
        [],
        ['yes' => TRUE],
        NULL,
        $this->getSutRoot(),
        1,
      );
      $this->assertStringContainsString(
        'Site UUID does not match config sync',
        $this->getErrorOutput(),
      );
      $this->assertStringContainsString(
        '--reconcile-site-uuid',
        $this->getErrorOutput(),
      );
    }
    finally {
      $this->setSiteUuid($syncUuid);
    }
  }

  /**
   * Tests that --reconcile-site-uuid recovers a mismatched site UUID.
   *
   * Asserts the opt-in path both succeeds and leaves the active UUID matching
   * config sync, so the subsequent config:import is accepted.
   */
  public function testUpdateReconcilesSiteUuidWhenFlagPassed(): void {
    $syncUuid = $this->exportConfigAndGetSiteUuid();
    $this->setSiteUuid('00000000-0000-0000-0000-000000000000');

    $this->drush(
      'suds:update',
      [],
      ['yes' => TRUE, 'reconcile-site-uuid' => TRUE],
      NULL,
      $this->getSutRoot(),
    );

    $this->drush(
      'config:get',
      ['system.site', 'uuid'],
      ['format' => 'string'],
      NULL,
      $this->getSutRoot(),
    );
    $this->assertSame($syncUuid, trim($this->getOutput()));
  }

  /**
   * Removes configuration exported by these tests.
   *
   * The sync directory lives under sites/default/files and survives a
   * reinstall, so an export left behind would give every later suds:update in
   * the suite a stale UUID once another test reinstalls the site.
   */
  protected function tearDown(): void {
    if ($this->syncDir !== NULL) {
      foreach (array_diff($this->listSyncFiles(), $this->syncFilesBefore) as $file) {
        unlink($this->syncDir . '/' . $file);
      }
    }
    parent::tearDown();
  }

  /**
   * Exports configuration and returns the site UUID it records.
   *
   * Snapshots the sync directory first so tearDown() can restore it.
   *
   * @return string
   *   The site UUID present in the config sync directory.
   */
  private function exportConfigAndGetSiteUuid(): string {
    $this->drush('status', [], ['format' => 'json'], NULL, $this->getSutRoot());
    $status = json_decode($this->getOutput(), TRUE);
    $syncDir = $status['config'];
    $this->syncDir = str_starts_with($syncDir, '/')
      ? $syncDir
      : $status['root'] . '/' . $syncDir;
    $this->syncFilesBefore = $this->listSyncFiles();

    $this->drush('config:export', [], ['yes' => TRUE], NULL, $this->getSutRoot());
    $this->drush(
      'config:get',
      ['system.site', 'uuid'],
      ['format' => 'string'],
      NULL,
      $this->getSutRoot(),
    );
    return trim($this->getOutput());
  }

  /**
   * Lists the filenames currently in the config sync directory.
   *
   * @return list<string>
   *   Filenames, excluding dot entries.
   */
  private function listSyncFiles(): array {
    if ($this->syncDir === NULL || !is_dir($this->syncDir)) {
      return [];
    }
    return array_values(array_diff(scandir($this->syncDir) ?: [], ['.', '..']));
  }

  /**
   * Sets the active site UUID on the SUT.
   *
   * @param string $uuid
   *   The UUID to write to system.site.
   */
  private function setSiteUuid(string $uuid): void {
    $this->drush(
      'config:set',
      ['system.site', 'uuid', $uuid],
      ['yes' => TRUE],
      NULL,
      $this->getSutRoot(),
    );
  }

  /**
   * Tests that suds:update is reachable via its su-update alias.
   */
  public function testUpdateAliasExitsCleanly(): void {
    $this->drush(
      'su-update',
      [],
      ['yes' => TRUE],
      NULL,
      $this->getSutRoot(),
    );
    $this->assertStringContainsString(
      'SUDS: Updating Environment',
      $this->getOutput(),
    );
  }

}
