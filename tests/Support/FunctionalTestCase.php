<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Support;

use Drush\TestTraits\DrushTestTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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
 * - SUDS_DRUSH_BIN: absolute path to the drush binary to invoke.
 *
 * A third, optional variable covers layouts where the SUT's project root is
 * not the Drupal root's parent:
 *
 * - SUDS_SUT_PROJECT_ROOT: absolute path to the directory holding the SUT's
 *   composer.json and vendor/. Defaults to dirname(SUDS_SUT_ROOT), which is
 *   correct for both a sibling and a nested vendor/.
 *
 * The first two must be set together, and must come from the same vendor tree:
 * drush has to autoload both Drupal and SUDS's command classes. Setting only
 * one is rejected in setUpBeforeClass() rather than left to fail later as a
 * confusing "command not found".
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
   * to the binary inside the drush package in this project's vendor/. Must
   * stay non-static to match the trait's signature.
   *
   * @return string
   *   Absolute path to a drush executable.
   */
  public function getPathToDrush(): string {
    return getenv('SUDS_DRUSH_BIN')
      ?: dirname(__DIR__, 2) . '/vendor/drush/drush/drush';
  }

  /**
   * Returns the Drupal root of the system under test.
   *
   * Static so setUpBeforeClass() can share one definition of the default
   * path rather than re-deriving it; subclasses still call $this->getSutRoot().
   *
   * @return string
   *   Absolute path to the Drupal root.
   */
  protected static function getSutRoot(): string {
    return getenv('SUDS_SUT_ROOT') ?: dirname(__DIR__, 2) . '/sut';
  }

  /**
   * Returns the project root of the system under test.
   *
   * The directory holding the SUT's composer.json and vendor/ — the repo root
   * for the default `sut/`, or the separately-built site's root in CI. Derived
   * from the Drupal root's parent, which holds for both a sibling vendor/
   * (drupal/recommended-project) and a nested one.
   *
   * @return string
   *   Absolute path to the SUT's project root.
   */
  protected static function getSutProjectRoot(): string {
    return getenv('SUDS_SUT_PROJECT_ROOT') ?: dirname(static::getSutRoot());
  }

  /**
   * Builds a throwaway project root wired to the SUT, and returns its path.
   *
   * The result looks like a minimal Drupal project:
   *
   *   <temp>/suds.yml           the $config passed in
   *   <temp>/vendor          -> symlink to the SUT's vendor/
   *   <temp>/<drupal.root>   -> symlink to the SUT's Drupal root
   *
   * Symlinks rather than copies are what keep this SUT-agnostic: a command
   * resolves Composer binaries and Drupal beneath the fixture, but lands in
   * whichever real tree SUDS_SUT_ROOT selects. One fixture therefore covers
   * both CI jobs — the repo's Drupal 11.4 sut/ and a separately provisioned
   * older site — each resolving its own version-appropriate binaries.
   *
   * Tests must not write suds.yml into the real project root instead: a
   * hard-killed run would leave it behind to poison later runs and
   * `git status`.
   *
   * Teardown is the caller's job via removeDirectory(), which removes symlinks
   * as links rather than descending into them.
   *
   * @param array<string, mixed> $config
   *   The suds.yml contents. drupal.root names the path that becomes the
   *   symlink to the SUT's Drupal root, and may be nested (e.g. 'docroot/web').
   *
   * @return string
   *   Absolute path to the created project root.
   *
   * @throws \InvalidArgumentException
   *   When drupal.root is missing or empty, since there would be nothing to
   *   point at the SUT.
   */
  protected function createSutProjectFixture(array $config): string {
    $webroot = $config['drupal']['root'] ?? NULL;
    if (!is_string($webroot) || trim($webroot, '/') === '') {
      throw new \InvalidArgumentException(
        'createSutProjectFixture() needs a non-empty drupal.root to link the SUT into.',
      );
    }

    $root = $this->createTempDir('suds_project_');
    $webrootPath = $root . '/' . trim($webroot, '/');
    // symlink() will not create intermediate directories for a nested root.
    $webrootParent = dirname($webrootPath);
    if (!is_dir($webrootParent)) {
      mkdir($webrootParent, 0777, TRUE);
    }
    symlink(static::getSutProjectRoot() . '/vendor', $root . '/vendor');
    symlink(static::getSutRoot(), $webrootPath);
    file_put_contents($root . '/suds.yml', Yaml::dump($config, 4, 2));

    return $root;
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
    // phpunit.xml.dist sets this; the default mirrors the sut:si script.
    $dbUrl = getenv('UNISH_DB_URL') ?: 'sqlite://sites/default/files/.ht.sqlite';
    if (!str_starts_with($dbUrl, 'sqlite://')) {
      return NULL;
    }
    $relative = substr($dbUrl, strlen('sqlite://'));
    return static::getSutRoot() . '/' . ltrim($relative, '/');
  }

  /**
   * Validates the SUT configuration before any test in the class runs.
   *
   * Skips locally so a fresh clone gets one actionable message instead of a
   * wall of subprocess failures, but fails under CI: PHPUnit exits 0 on a
   * fully-skipped suite, which would let a broken SUT report green having
   * tested nothing.
   */
  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();

    $root = getenv('SUDS_SUT_ROOT');
    $bin = getenv('SUDS_DRUSH_BIN');
    if (($root === FALSE) !== ($bin === FALSE)) {
      self::fail(
        'SUDS_SUT_ROOT and SUDS_DRUSH_BIN must be set together: the drush '
        . 'binary has to come from the same vendor tree as the Drupal root, '
        . 'so it can autoload both Drupal and SUDS.'
      );
    }

    if (is_file(static::getSutRoot() . '/sites/default/settings.php')) {
      return;
    }

    $message = sprintf(
      'SUT is not provisioned at %s. Run `composer sut:si` first.',
      static::getSutRoot(),
    );
    if (getenv('CI')) {
      self::fail($message);
    }
    self::markTestSkipped($message);
  }

}
