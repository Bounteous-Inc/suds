<?php

/**
 * @file
 * PHPUnit bootstrap for SUDS.
 */

declare(strict_types=1);

// Load the Composer autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Make Drush\Style\DrushStyle loadable on Drush releases that ship it in the
// Symfony-compatibility tree rather than src/. See the file's docblock.
require_once __DIR__ . '/drush-compat-autoloader.php';
