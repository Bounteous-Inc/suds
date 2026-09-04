<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Unit\Support;

use Bounteous\Suds\Tests\Support\TempDirectoryTrait;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TempDirectoryTrait.
 */
#[CoversTrait(TempDirectoryTrait::class)]
class TempDirectoryTraitTest extends TestCase {

  use TempDirectoryTrait;

  /**
   * Verifies removeDirectory() deletes symlinks without touching the target.
   *
   * Because is_dir() follows symlinks, a naive recursive delete would descend
   * into a symlinked directory and destroy its contents. Fixtures symlink real
   * vendor/ and webroot trees into temp directories, so a regression here
   * would delete the developer's actual dependencies on teardown.
   */
  public function testRemoveDirectoryDoesNotFollowSymlinkedDirectories(): void {
    $target = $this->createTempDir('suds_target_');
    file_put_contents($target . '/precious.txt', 'keep me');

    $container = $this->createTempDir('suds_container_');
    symlink($target, $container . '/linked');

    $this->removeDirectory($container);

    $this->assertDirectoryDoesNotExist($container);
    $this->assertFileExists(
      $target . '/precious.txt',
      'Symlink target contents must survive teardown.',
    );

    $this->removeDirectory($target);
  }

  /**
   * Verifies removeDirectory() unlinks a symlink passed as the root itself.
   */
  public function testRemoveDirectoryUnlinksSymlinkRoot(): void {
    $target = $this->createTempDir('suds_target_');
    file_put_contents($target . '/precious.txt', 'keep me');

    $link = sys_get_temp_dir() . '/suds_link_' . uniqid('', TRUE);
    symlink($target, $link);

    $this->removeDirectory($link);

    $this->assertFalse(is_link($link), 'Symlink itself must be removed.');
    $this->assertFileExists($target . '/precious.txt');

    $this->removeDirectory($target);
  }

  /**
   * Verifies removeDirectory() still removes real nested directories.
   */
  public function testRemoveDirectoryRemovesNestedRealDirectories(): void {
    $root = $this->createTempDir('suds_nested_');
    mkdir($root . '/a/b', 0777, TRUE);
    file_put_contents($root . '/a/b/file.txt', 'x');

    $this->removeDirectory($root);

    $this->assertDirectoryDoesNotExist($root);
  }

}
