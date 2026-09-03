<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Functional\Commands;

use Bounteous\Suds\Drush\Commands\DepsUpdateCommands;
use Bounteous\Suds\Tests\Support\FunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Functional tests for DepsUpdateCommands.
 *
 * These tests shell out to the Drush binary and require a provisioned
 * Drupal installation in the sut/ directory. Before running:
 *
 * @code
 * composer sut:si
 * composer test:functional
 * @endcode
 *
 * suds:deps-update runs `composer update`, updatedb, cache:rebuild, and
 * config:export against the live SUT. The fixture composer.json declares no
 * dependencies, so the unrestricted `composer update` pass resolves
 * instantly without hitting the network, while still exercising the real
 * command invocation and its guard/step sequencing.
 *
 * Every test here passes --skip-cex. config:export writes the SUT's active
 * config — including system.site.yml and its site UUID — into the sync
 * directory, which is shared mutable state for the whole functional suite.
 * A later test that reinstalls the SUT changes the active UUID, and
 * suds:update then rejects config:import on the mismatch. The export path
 * itself is covered by the integration tests, which assert the dispatched
 * command sequence without touching the SUT.
 */
#[CoversClass(DepsUpdateCommands::class)]
class DepsUpdateCommandsFunctionalTest extends FunctionalTestCase {

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
    $this->tmpDir = $this->createTempDir('suds_deps_update_func_');
    $this->writeSudsYml($this->tmpDir);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    parent::tearDown();
    $this->removeDirectory($this->tmpDir);
  }

  /**
   * Tests that suds:deps-update exits cleanly against the live SUT.
   *
   * Exit code 0 is asserted implicitly — drush() fails the test if the
   * command exits non-zero.
   */
  public function testDepsUpdateExitsCleanly(): void {
    $this->drush(
      'suds:deps-update',
      [],
      ['yes' => TRUE, 'skip-cex' => TRUE, 'root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );
    $this->assertStringContainsString(
      'SUDS: Updating Dependencies',
      $this->getOutput(),
    );
  }

  /**
   * Tests that suds:deps-update is reachable via its su-deps-update alias.
   */
  public function testDepsUpdateAliasExitsCleanly(): void {
    $this->drush(
      'su-deps-update',
      [],
      ['yes' => TRUE, 'skip-cex' => TRUE, 'root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );
    $this->assertStringContainsString(
      'SUDS: Updating Dependencies',
      $this->getOutput(),
    );
  }

  /**
   * Tests that --skip-cex skips config export.
   *
   * Drush routes config:export's completion message through its logger,
   * which writes to stderr, so its absence is asserted there.
   */
  public function testDepsUpdateSkipsConfigExportWhenFlagPassed(): void {
    $this->drush(
      'suds:deps-update',
      [],
      ['yes' => TRUE, 'skip-cex' => TRUE, 'root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );
    $this->assertStringNotContainsString(
      'Configuration successfully exported',
      $this->getErrorOutput(),
    );
  }

  /**
   * Writes a minimal suds.yml and composer.json to the given directory.
   *
   * Deps_update.composer.groups is empty (unrestricted composer update) and
   * the fixture composer.json declares no dependencies, so the pass resolves
   * instantly without touching the network. Deps_update.hooks are empty so
   * no shell commands are spawned beyond the composer update pass.
   *
   * @param string $dir
   *   The directory in which to write the fixture files.
   */
  private function writeSudsYml(string $dir): void {
    file_put_contents(
      $dir . '/suds.yml',
      Yaml::dump([
        'deps_update' => [
          'composer' => ['groups' => []],
          'hooks' => ['pre_deps_update' => [], 'post_deps_update' => []],
        ],
      ], 4, 2),
    );
    file_put_contents(
      $dir . '/composer.json',
      json_encode(['name' => 'test/suds-deps-update-func', 'description' => 'Functional test fixture'], JSON_PRETTY_PRINT) . "\n",
    );
  }

}
