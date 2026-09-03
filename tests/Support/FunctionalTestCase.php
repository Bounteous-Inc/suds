<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Support;

use Drush\TestTraits\DrushTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Base class for functional tests that shell out to a real Drush binary.
 *
 * Functional tests invoke drush as a subprocess against a provisioned Drupal
 * site (the "SUT"). By default that is the repo's own `sut/` directory, sharing
 * this project's vendor/ — provision it with `composer sut:si`.
 *
 * Both the Drupal root and the drush binary can be redirected with environment
 * variables, so the same suite can run against a Drupal site built elsewhere
 * (used by CI to cover a Drupal version that cannot coexist with this
 * project's dev dependencies):
 *
 * - SUDS_SUT_ROOT:  absolute path to the Drupal root.
 * - SUDS_DRUSH_BIN: absolute path to the drush binary to invoke. This must come
 *   from the same vendor tree as that Drupal root, because drush has to
 *   autoload both Drupal and SUDS's command classes.
 *
 * IMPORTANT: `use DrushTestTrait` and the getPathToDrush() override must stay
 * together in this class. DrushTestTrait::drush() calls
 * `self::getPathToDrush()`, and inside a trait `self` binds to the class that
 * composed it — so an override in a subclass is silently ignored, and tests
 * would quietly run this project's drush (which has no Drupal) instead. Do not
 * override getPathToDrush() in individual test classes.
 */
abstract class FunctionalTestCase extends TestCase {

  use DrushTestTrait;
  use TempDirectoryTrait;

  /**
   * Returns the drush binary the tests should invoke.
   *
   * Overrides DrushTestTrait::getPathToDrush(), which would otherwise resolve
   * to the binary inside the drush package in this project's vendor/.
   *
   * @return string
   *   Absolute path to a drush executable.
   */
  public function getPathToDrush(): string {
    $configured = getenv('SUDS_DRUSH_BIN');
    if (is_string($configured) && $configured !== '') {
      return $configured;
    }
    return dirname(__DIR__, 2) . '/vendor/drush/drush/drush';
  }

  /**
   * Returns the Drupal root of the system under test.
   *
   * @return string
   *   Absolute path to the Drupal root.
   */
  protected function getSutRoot(): string {
    $configured = getenv('SUDS_SUT_ROOT');
    if (is_string($configured) && $configured !== '') {
      return $configured;
    }
    return dirname(__DIR__, 2) . '/sut';
  }

  /**
   * Returns the SUT's SQLite database file, when it uses one.
   *
   * Tests that install over the top of the SUT back this file up and restore
   * it afterwards so they do not destroy the site for later tests. Returns
   * NULL when UNISH_DB_URL points at a non-SQLite database, in which case
   * there is no single file to snapshot and callers should skip the backup.
   *
   * @return string|null
   *   Absolute path to the SQLite file, or NULL when not applicable.
   */
  protected function sqliteDbPath(): ?string {
    $dbUrl = getenv('UNISH_DB_URL');
    if (!is_string($dbUrl) || $dbUrl === '') {
      // Matches the default in the sut:si composer script.
      $dbUrl = 'sqlite://sites/default/files/.ht.sqlite';
    }
    if (!str_starts_with($dbUrl, 'sqlite://')) {
      return NULL;
    }
    $relative = substr($dbUrl, strlen('sqlite://'));
    return $this->getSutRoot() . '/' . ltrim($relative, '/');
  }

  /**
   * Skips the whole class when the SUT has not been provisioned.
   *
   * Without this a fresh clone produces a wall of confusing subprocess
   * failures instead of a clear signal to run `composer sut:si`.
   */
  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();

    $sutRoot = getenv('SUDS_SUT_ROOT');
    if (!is_string($sutRoot) || $sutRoot === '') {
      $sutRoot = dirname(__DIR__, 2) . '/sut';
    }
    if (!is_file($sutRoot . '/sites/default/settings.php')) {
      self::markTestSkipped(sprintf(
        'SUT is not provisioned at %s. Run `composer sut:si` first.',
        $sutRoot,
      ));
    }
  }

}
