<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Integration\Commands;

use Bounteous\Suds\Config\ConfigLoader;
use Bounteous\Suds\Drush\Commands\DeployCommands;
use Bounteous\Suds\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Yaml\Yaml;

/**
 * Integration tests for DeployCommands.
 *
 * Commands are invoked through the Symfony Application API via
 * AnnotatedCommandFactory — no subprocess is spawned and no Drupal bootstrap
 * is required. A testable subclass overrides all shell-exec methods so that
 * no real git or rsync commands are executed.
 */
#[CoversClass(DeployCommands::class)]
class DeployCommandsIntegrationTest extends IntegrationTestCase {

  /**
   * Temporary project directory.
   *
   * @var string
   */
  private string $projectRoot;

  /**
   * The command instance under test.
   *
   * @var \Bounteous\Suds\Tests\Integration\Commands\TestableDeployCommands
   */
  private TestableDeployCommands $commandInstance;

  /**
   * The application tester.
   *
   * @var \Symfony\Component\Console\Tester\ApplicationTester
   */
  private ApplicationTester $tester;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->projectRoot = $this->createTempDir('suds_deploy_integration_');
    $this->commandInstance = new TestableDeployCommands();
    $this->tester = $this->buildTester($this->commandInstance);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeDirectory($this->projectRoot);
  }

  /**
   * Tests that suds:deploy exits cleanly when repo URL is configured.
   */
  public function testDeployExitsCleanly(): void {
    file_put_contents(
      $this->projectRoot . '/suds.yml',
      Yaml::dump([
        'deploy' => ['repo' => ['url' => 'git@github.com:example/artifact.git']],
      ], 4, 2),
    );

    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );

    $exitCode = $this->tester->run(['command' => 'suds:deploy']);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that suds:deploy is reachable via its su-deploy alias.
   */
  public function testDeployAliasIsRegistered(): void {
    file_put_contents(
      $this->projectRoot . '/suds.yml',
      Yaml::dump([
        'deploy' => ['repo' => ['url' => 'git@github.com:example/artifact.git']],
      ], 4, 2),
    );

    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );

    $exitCode = $this->tester->run(['command' => 'su-deploy']);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that suds:deploy --dry-run exits cleanly.
   */
  public function testDeployDryRunExitsCleanly(): void {
    file_put_contents(
      $this->projectRoot . '/suds.yml',
      Yaml::dump([
        'deploy' => ['repo' => ['url' => 'git@github.com:example/artifact.git']],
      ], 4, 2),
    );

    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );

    $exitCode = $this->tester->run(['command' => 'suds:deploy', '--dry-run' => TRUE]);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that suds:deploy exits non-zero when deploy.repo.url is missing.
   *
   * AnnotatedCommand catches the RuntimeException thrown inside deploy() and
   * converts it to a non-zero exit code.
   */
  public function testDeployExitsNonZeroWhenRepoUrlMissing(): void {
    // No suds.yml — deploy.repo.url defaults to null (empty).
    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );

    $exitCode = $this->tester->run(['command' => 'suds:deploy']);
    $this->assertNotSame(0, $exitCode);
  }

  /**
   * Returns the package root path (contains config/suds.defaults.yml).
   */
  private function packageRoot(): string {
    return dirname(__DIR__, 3);
  }

}

/**
 * Testable subclass of DeployCommands for integration tests.
 *
 * Overrides all shell-execution methods so that no real git, rsync, or other
 * subprocess commands are spawned during integration tests.
 */
class TestableDeployCommands extends DeployCommands {

  /**
   * {@inheritdoc}
   *
   * Returns a fixed branch name so no real git process is needed.
   */
  protected function getCurrentBranch(string $projectRoot): string {
    return 'main';
  }

  /**
   * {@inheritdoc}
   *
   * Returns a fixed hash so no real git process is needed.
   */
  protected function getHeadHash(string $projectRoot): string {
    return 'abc1234def56789abc1234def56789abc1234def5';
  }

  /**
   * {@inheritdoc}
   *
   * Returns the system temp dir so no actual artifact is assembled.
   */
  protected function buildArtifact(
    string $projectRoot,
    string $repoUrl,
    string $artifactBranch,
    array $excludePaths,
  ): string {
    return sys_get_temp_dir();
  }

  /**
   * {@inheritdoc}
   *
   * No-op in integration tests — shell commands are not executed.
   */
  protected function runShellCommand(string $cmd, string $cwd = ''): void {
    // Intentionally empty: integration tests do not spawn real subprocesses.
  }

  /**
   * {@inheritdoc}
   *
   * No-op in integration tests — no real artifact directory exists to write to.
   */
  protected function writeManifestFile(string $filePath, string $content): void {
    // Intentionally empty: integration tests do not write manifest files.
  }

  /**
   * {@inheritdoc}
   *
   * No-op in integration tests — buildArtifact() returns sys_get_temp_dir()
   * directly, which must not be deleted.
   */
  protected function removeDirectory(string $dir): void {
    // Intentionally empty: the stub artifact dir must not be removed.
  }

  /**
   * {@inheritdoc}
   *
   * Returns empty array in integration tests — no Drush container is present.
   */
  protected function redispatchOptions(): array {
    return [];
  }

}
