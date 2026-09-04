<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Support;

/**
 * Provides temporary directory utilities for test classes.
 *
 * Eliminates boilerplate and consolidates the removeDirectory() helper that
 * was previously duplicated across ConfigLoaderTest,
 * ConfigCommandsIntegrationTest, and ConfigCommandsFunctionalTest.
 */
trait TempDirectoryTrait {

  /**
   * Creates a unique temporary directory and returns its path.
   *
   * @param string $prefix
   *   Prefix for the temporary directory name.
   *
   * @return string
   *   Absolute path to the created temporary directory.
   */
  protected function createTempDir(string $prefix = 'suds_'): string {
    $dir = sys_get_temp_dir() . '/' . $prefix . uniqid('', TRUE);
    mkdir($dir, 0777, TRUE);
    return $dir;
  }

  /**
   * Recursively removes a directory and all its contents.
   *
   * Symlinks are removed as links and never descended into. is_dir() follows
   * symlinks, so recursing on it would delete the *target's* contents — a
   * fixture that symlinks vendor/ or a webroot into a temp directory would
   * otherwise take the real tree down with it on teardown.
   *
   * @param string $dir
   *   Absolute path of the directory to remove.
   */
  protected function removeDirectory(string $dir): void {
    if (is_link($dir)) {
      unlink($dir);
      return;
    }
    if (!is_dir($dir)) {
      return;
    }
    foreach (scandir($dir) ?: [] as $item) {
      if ($item === '.' || $item === '..') {
        continue;
      }
      $path = $dir . '/' . $item;
      // A symlinked directory takes the is_dir() branch; the recursive call's
      // is_link() guard then unlinks it instead of descending.
      is_dir($path) ? $this->removeDirectory($path) : unlink($path);
    }
    rmdir($dir);
  }

}
