<?php

/**
 * @file
 * Registers Drush's Symfony-compatibility autoloader for dev tooling.
 *
 * Drush 13 ships Drush\Style\DrushStyle under
 * src-symfony-compatibility/v{4,6}/ instead of src/, and registers that
 * directory as a PSR-4 root at runtime in
 * Drush\Preflight\Preflight::loadSymfonyCompatabilityAutoloader(), so a single
 * Drush release can support more than one Symfony major. Drush 13.7.1 moved
 * the class to src/Style/, where Composer's normal PSR-4 map finds it.
 *
 * PHPUnit and PHPStan never run Drush's preflight, so between our floor
 * (13.3.3) and 13.7.0 the class is not autoloadable and anything referencing
 * it fails — mocks in unit tests, and DrushCommands::io() return types during
 * static analysis. Replicating the registration here fixes both. Upstream does
 * the same thing for static analysis in
 * vendor/drush/drush/phpstan-bootstrap.php, which likewise targets v6 only.
 *
 * @see https://github.com/drush-ops/drush/issues/6110
 * @see https://github.com/drush-ops/drush/issues/6334
 */

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Drush\Style\DrushStyle;

// Wrapped so the locals cannot collide with either including scope: this file
// is required from both tests/bootstrap.php and PHPStan's bootstrapFiles.
(static function (): void {
  // Self-sufficient for the PHPStan entry path, which has no autoloader of
  // ours loaded yet; require_once makes this a no-op under PHPUnit.
  require_once dirname(__DIR__) . '/vendor/autoload.php';

  if (class_exists(DrushStyle::class)) {
    return;
  }

  // SUDS requires PHP 8.3 and Drupal 10.4+, so Symfony is always 6 or 7, and
  // Drush maps both of those majors to its v6 compatibility tree.
  $compatDir = dirname(__DIR__)
    . '/vendor/drush/drush/src-symfony-compatibility/v6';
  if (!is_dir($compatDir)) {
    throw new RuntimeException(sprintf(
      'Drush\Style\DrushStyle is not autoloadable and the Symfony '
      . 'compatibility tree is missing at %s. Drush has probably moved it; '
      . 'see tests/drush-compat-autoloader.php.',
      $compatDir,
    ));
  }

  $loader = new ClassLoader();
  $loader->addPsr4('Drush\\', $compatDir);
  $loader->register();
})();
