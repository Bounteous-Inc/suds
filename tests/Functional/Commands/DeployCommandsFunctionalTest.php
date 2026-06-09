<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Functional\Commands;

use Bounteous\Suds\Drush\Commands\DeployCommands;
use Bounteous\Suds\Tests\Support\TempDirectoryTrait;
use Drush\TestTraits\DrushTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Functional tests for DeployCommands.
 *
 * These tests shell out to the Drush binary and require a provisioned
 * Drupal installation in the sut/ directory. Before running:
 *
 * @code
 * composer sut:si
 * composer test:functional
 * @endcode
 *
 * Full artifact assembly (rsync, git push) requires a real remote repository
 * and is not covered here. These tests verify command availability, error
 * conditions, and flag handling without executing real git operations.
 */
#[CoversClass(DeployCommands::class)]
class DeployCommandsFunctionalTest extends TestCase {

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
    $this->tmpDir = $this->createTempDir('suds_deploy_func_');
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
   * Tests that suds:deploy exits non-zero when deploy.repo.url is not set.
   *
   * No suds.yml is written so deploy.repo.url defaults to null. The command
   * must exit non-zero and print an error before attempting any git operations.
   */
  public function testDeployFailsWhenRepoUrlNotConfigured(): void {
    $this->drush(
      'suds:deploy',
      [],
      ['root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
      1,
    );
    $this->assertStringContainsString(
      'deploy.repo.url',
      $this->getErrorOutput(),
    );
  }

  /**
   * Tests that suds:deploy is reachable via its su-deploy alias.
   *
   * The missing repo URL causes the same error as above — this test confirms
   * the alias is registered and delegates to the same command logic.
   */
  public function testDeployAliasIsRegistered(): void {
    $this->drush(
      'su-deploy',
      [],
      ['root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
      1,
    );
    $this->assertStringContainsString(
      'deploy.repo.url',
      $this->getErrorOutput(),
    );
  }

  /**
   * Tests that suds:deploy --dry-run prints commands without executing them.
   *
   * With --dry-run, the command should output the shell commands that would run
   * rather than executing them, allowing it to complete without a real remote.
   * The project directory is initialised as a git repository so that
   * getCurrentBranch() and getHeadHash() can resolve successfully.
   */
  public function testDeployDryRunOutputsCommands(): void {
    // Initialise a git repo in the temp dir so branch/hash lookups succeed.
    // Pass user identity inline so the commit does not fail in CI environments
    // that have no global git config (e.g. a fresh Bitbucket Pipelines runner).
    exec(
      sprintf(
        'git -C %1$s init -q && git -C %1$s -c user.email="ci@suds.test" -c user.name="CI" commit --allow-empty -m init -q',
        escapeshellarg($this->tmpDir),
      ),
      $output,
      $exitCode,
    );
    $this->assertSame(0, $exitCode, 'Git repo initialisation failed: ' . implode("\n", $output));

    file_put_contents(
      $this->tmpDir . '/suds.yml',
      Yaml::dump([
        'deploy' => ['repo' => ['url' => 'git@github.com:example/artifact.git']],
      ], 4, 2),
    );

    $this->drush(
      'suds:deploy',
      [],
      ['dry-run' => TRUE, 'root' => $this->getSutRoot()],
      NULL,
      $this->tmpDir,
    );
    $this->assertStringContainsString(
      '[dry-run]',
      $this->getOutput(),
    );
  }

}
