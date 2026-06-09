<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Functional\Commands;

use Bounteous\Suds\Drush\Commands\SetupCommands;
use Drush\TestTraits\DrushTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
class SetupCommandsFunctionalTest extends TestCase {

  use DrushTestTrait;

  /**
   * Absolute path to the SUT SQLite database file.
   *
   * @var string
   */
  private string $sutDbPath;

  /**
   * {@inheritdoc}
   *
   * Back up the SUT SQLite database before each test. suds:setup calls
   * drush site:install which wipes the database and leaves the config sync
   * directory empty, which would cause subsequent tests (e.g. UpdateCommands)
   * to fail when they call suds:update → config:import.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->sutDbPath = $this->getSutRoot() . '/sites/default/files/.ht.sqlite';
    copy($this->sutDbPath, $this->sutDbPath . '.bak');
  }

  /**
   * {@inheritdoc}
   *
   * Restore the SUT SQLite database after each test so subsequent tests start
   * from the same known-good state that composer sut:si provisioned.
   */
  protected function tearDown(): void {
    parent::tearDown();
    if (file_exists($this->sutDbPath . '.bak')) {
      copy($this->sutDbPath . '.bak', $this->sutDbPath);
      unlink($this->sutDbPath . '.bak');
    }
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

}
