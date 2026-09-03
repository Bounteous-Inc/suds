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
 * vendor/drush/drush/phpstan-bootstrap.php.
 *
 * @see https://github.com/drush-ops/drush/issues/6110
 * @see https://github.com/drush-ops/drush/issues/6334
 */

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Drush\Style\DrushStyle;

// Self-sufficient so both PHPUnit's bootstrap and PHPStan's bootstrapFiles can
// include it; require_once makes the duplicate load a no-op under PHPUnit.
$sudsAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($sudsAutoload)) {
  require_once $sudsAutoload;
}

if (!class_exists(DrushStyle::class)) {
  // SUDS requires PHP 8.3 and Drupal 10.4+, so Symfony is always 6 or 7, and
  // Drush maps both of those majors to its v6 compatibility tree.
  $sudsDrushCompatDir = dirname(__DIR__)
    . '/vendor/drush/drush/src-symfony-compatibility/v6';
  if (is_dir($sudsDrushCompatDir)) {
    $sudsDrushLoader = new ClassLoader();
    $sudsDrushLoader->addPsr4('Drush\\', $sudsDrushCompatDir);
    $sudsDrushLoader->register();
  }
}
