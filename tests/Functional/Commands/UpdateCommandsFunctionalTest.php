<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Functional\Commands;

use Bounteous\Suds\Drush\Commands\UpdateCommands;
use Drush\TestTraits\DrushTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
class UpdateCommandsFunctionalTest extends TestCase {

  use DrushTestTrait;

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
