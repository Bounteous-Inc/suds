<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Functional\Commands;

use Bounteous\Suds\Drush\Commands\SetupCommands;
use Bounteous\Suds\Tests\Support\FunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional tests for SetupCommands.
 *
 * These tests shell out to the Drush binary and require a provisioned
 * Drupal installation in the sut/ directory. Before running:
 *
 * @code
 * composer sut:si
 * composer test:functional
 * @endcode
 *
 * suds:setup functional tests install a real Drupal site and are therefore
 * slow. They verify end-to-end behavior that cannot be covered by unit or
 * integration tests (actual drush site:install subprocess, real DB writes).
 */
#[CoversClass(SetupCommands::class)]
#[Group('drupal-version-sensitive')]
class SetupCommandsFunctionalTest extends FunctionalTestCase {

  /**
   * The SUT database file backed up in setUp(), or NULL if none was taken.
   *
   * @var string|null
   */
  private ?string $backedUpDb = NULL;

  /**
   * {@inheritdoc}
   *
   * Back up the SUT SQLite database before each test. suds:setup calls
   * drush site:install which wipes the database and leaves the config sync
   * directory empty, which would cause subsequent tests (e.g. UpdateCommands)
   * to fail when they call suds:update → config:import.
   *
   * On a non-SQLite SUT there is no single file to snapshot, so the backup is
   * skipped and the test simply leaves the site reinstalled.
   */
  protected function setUp(): void {
    parent::setUp();
    $dbPath = $this->sqliteDbPath();
    if ($dbPath !== NULL && is_file($dbPath)) {
      copy($dbPath, $dbPath . '.bak');
      $this->backedUpDb = $dbPath;
    }
  }

  /**
   * {@inheritdoc}
   *
   * Restore the SUT SQLite database after each test so subsequent tests start
   * from the same known-good state that composer sut:si provisioned.
   */
  protected function tearDown(): void {
    parent::tearDown();
    if ($this->backedUpDb !== NULL) {
      copy($this->backedUpDb . '.bak', $this->backedUpDb);
      unlink($this->backedUpDb . '.bak');
      $this->backedUpDb = NULL;
    }
  }

  /**
   * Tests that suds:setup installs Drupal and outputs a success message.
   *
   * Runs a real site install via drush site:install. Requires a provisioned
   * SUT with a writable SQLite database (composer sut:si).
   */
  public function testSetupInstallsDrupal(): void {
    $this->drush(
      'suds:setup',
      [],
      ['yes' => TRUE],
      NULL,
      $this->getSutRoot(),
    );
    $this->assertStringContainsString(
      'SUDS: Setting Up Drupal Site',
      $this->getOutput(),
    );
    // DrushStyle::success() forces output to stderr to avoid interfering with
    // formatted output, so the completion message appears in getErrorOutput().
    $this->assertStringContainsString(
      'Drupal site setup complete',
      $this->getErrorOutput(),
    );
  }

  /**
   * Tests that suds:setup accepts a custom --profile option.
   */
  public function testSetupAcceptsCustomProfile(): void {
    $this->drush(
      'suds:setup',
      [],
      ['profile' => 'minimal', 'yes' => TRUE],
      NULL,
      $this->getSutRoot(),
    );
    $this->assertStringContainsString('minimal', $this->getOutput());
  }

  /**
   * Tests that suds:setup actually applies a configured recipe.
   *
   * Exercises the real recipe-invocation path end to end (issue #25),
   * asserting the recipe's config lands rather than just checking the command
   * string built to invoke it.
   *
   * Runs against whichever SUT the environment selects, so it covers both
   * runner branches across the CI matrix without naming either: the Drupal
   * 11.4 SUT resolves vendor/bin/dr, while the Drupal 10.4 job's SUT has no
   * Composer bin and falls back to core/scripts/drupal. core/recipes/example
   * ships in both versions.
   */
  public function testSetupAppliesConfiguredRecipe(): void {
    $webroot = 'web';
    $projectRoot = $this->createSutProjectFixture([
      'drupal' => ['root' => $webroot],
      'setup'  => ['recipes' => [$webroot . '/core/recipes/example']],
    ]);

    try {
      $this->drush(
        'suds:setup',
        [],
        ['yes' => TRUE, 'root' => $this->getSutRoot()],
        NULL,
        $projectRoot,
      );

      $this->assertStringContainsString(
        'Applying recipe: ' . $webroot . '/core/recipes/example',
        $this->getOutput(),
      );
    }
    finally {
      $this->removeDirectory($projectRoot);
    }

    $this->drush('pm:list', [], ['format' => 'json'], NULL, $this->getSutRoot());
    $this->assertStringContainsString('"node"', $this->getOutput());
  }

}
