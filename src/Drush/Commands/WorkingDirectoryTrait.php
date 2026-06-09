<?php

declare(strict_types=1);

namespace Bounteous\Suds\Drush\Commands;

/**
 * Provides getTargetDir() for command classes that operate on the CWD.
 *
 * Extracted as a protected method so integration-test subclasses can
 * override it to return a temporary directory without chdir().
 */
trait WorkingDirectoryTrait {

  /**
   * Returns the absolute path to the current working directory.
   *
   * @return string
   *   Absolute path to the current working directory.
   *
   * @throws \RuntimeException
   *   When the current working directory cannot be determined.
   */
  protected function getTargetDir(): string {
    $cwd = getcwd();
    if ($cwd === FALSE) {
      throw new \RuntimeException(
        'Unable to determine the current working directory.',
      );
    }
    return $cwd;
  }

}
