#!/usr/bin/env php
<?php

/**
 * @file
 * Syncs the version in composer.json.
 *
 * Usage:
 *   php scripts/sync-version.php [version]
 *
 * If a version argument is supplied it is written to composer.json.
 * If omitted, the version is read from composer.json and echoed (no-op sync).
 *
 * This script is called by the GitHub Actions release workflow. It can also
 * be run locally after manually editing composer.json:
 *   php scripts/sync-version.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$composer_path = $root . '/composer.json';
$composer = json_decode((string) file_get_contents($composer_path), TRUE);

$version = $argv[1] ?? NULL;

if ($version !== NULL) {
  $composer['version'] = $version;
  file_put_contents($composer_path, format_json($composer));
  echo "composer.json → $version\n";
}
else {
  $version = $composer['version'] ?? NULL;
  if ($version === NULL) {
    fwrite(STDERR, "Error: no version in composer.json and none supplied as argument.\n");
    exit(1);
  }
  echo "composer.json version: $version\n";
}

/**
 * JSON-encodes an array with 4-space indentation, matching composer.json style.
 *
 * @param mixed $data
 *   The data to encode.
 *
 * @return string
 *   The JSON-encoded string.
 */
function format_json(mixed $data): string {
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  return (string) $json . "\n";
}
