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
   * @param string $dir
   *   Absolute path of the directory to remove.
   */
  protected function removeDirectory(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }
    foreach (scandir($dir) ?: [] as $item) {
      if ($item === '.' || $item === '..') {
        continue;
      }
      $path = $dir . '/' . $item;
      is_dir($path) ? $this->removeDirectory($path) : unlink($path);
    }
    rmdir($dir);
  }

}
