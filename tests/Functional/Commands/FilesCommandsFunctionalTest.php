<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Functional\Commands;

use Bounteous\Suds\Drush\Commands\FilesCommands;
use Bounteous\Suds\Tests\Support\TempDirectoryTrait;
use Drush\TestTraits\DrushTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Functional tests for FilesCommands.
 *
 * These tests shell out to the Drush binary and require a provisioned
 * Drupal installation in the sut/ directory. Before running:
 *
 * @code
 * composer sut:si
 * composer test:functional
 * @endcode
 *
 * When sync.files.paths is empty no rsync subprocess is dispatched, so tests
 * that cover the command's orchestration logic can run without a real remote
 * source alias. Tests that require actual file transfer are not covered here
 * — that dispatch logic is verified in FilesCommandsIntegrationTest.
 */
#[CoversClass(FilesCommands::class)]
class FilesCommandsFunctionalTest extends TestCase {

  use DrushTestTrait;
  use TempDirectoryTrait;

  /**
   * Temporary directory used as the fake project root per test.
   *
   * @var string
   */
  private string $tmpDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->tmpDir = $this->createTempDir('suds_files_func_');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    parent::tearDown();
    $this->removeDirectory($this->tmpDir);
  }

  /**
   * Returns the path to the System Under Test Drupal root.
   *
   * @return string
   *   Absolute path to the SUT webroot.
   */
  protected function getSutRoot(): string {
    return dirname(__DIR__, 3) . '/sut';
  }

  /**
   * Tests that suds:files:sync exits cleanly when paths is empty.
   *
   * With an empty sync.files.paths list no rsync is dispatched, so any source
   * alias string is accepted without attempting a real network connection.
   * Exit code 0 is asserted implicitly — drush() fails the test if the
   * command exits non-zero.
   */
  public function testFilesSyncWithEmptyPathsExitsCleanly(): void {
    $this->writeSudsYml($this->tmpDir, []);

    $this->drush(
      'suds:files:sync',
      ['@self'],
      ['root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );
    $this->assertStringContainsString(
      'SUDS: Syncing Files',
      $this->getOutput(),
    );
  }

  /**
   * Tests that suds:files:sync is reachable via its su-files-sync alias.
   */
  public function testFilesSyncAliasExitsCleanly(): void {
    $this->writeSudsYml($this->tmpDir, []);

    $this->drush(
      'su-files-sync',
      ['@self'],
      ['root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );
    $this->assertStringContainsString(
      'SUDS: Syncing Files',
      $this->getOutput(),
    );
  }

  /**
   * Writes a suds.yml to the given directory with the specified paths list.
   *
   * @param string $dir
   *   The directory in which to write suds.yml.
   * @param list<string> $paths
   *   The sync.files.paths value.
   */
  private function writeSudsYml(string $dir, array $paths): void {
    file_put_contents(
      $dir . '/suds.yml',
      Yaml::dump([
        'sync' => [
          'files' => ['paths' => $paths],
        ],
      ], 4, 2),
    );
  }

}
