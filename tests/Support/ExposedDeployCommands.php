<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Support;

use Bounteous\Suds\Drush\Commands\DeployCommands;

/**
 * Exposes protected buildArtifact() and captures shell commands for testing.
 *
 * RunShellCommand() is overridden to record calls rather than execute them,
 * which prevents real git and rsync invocations during unit tests while still
 * exercising the command-generation logic in buildArtifact().
 */
class ExposedDeployCommands extends DeployCommands {

  /**
   * Shell commands captured by runShellCommand().
   *
   * @var list<array{cmd: string, cwd: string}>
   */
  public array $shellLog = [];

  /**
   * Manifest writes captured by writeManifestFile().
   *
   * @var list<array{path: string, content: string}>
   */
  public array $manifestLog = [];

  /**
   * {@inheritdoc}
   */
  protected function getCurrentBranch(string $projectRoot): string {
    return 'main';
  }

  /**
   * {@inheritdoc}
   */
  protected function getHeadHash(string $projectRoot): string {
    return 'abc1234def56789abc1234def56789abc1234def5';
  }

  /**
   * {@inheritdoc}
   */
  protected function runShellCommand(string $cmd, string $cwd = ''): void {
    $this->shellLog[] = ['cmd' => $cmd, 'cwd' => $cwd];
  }

  /**
   * {@inheritdoc}
   */
  protected function writeManifestFile(string $filePath, string $content): void {
    $this->manifestLog[] = ['path' => $filePath, 'content' => $content];
  }

  /**
   * Public proxy so tests can call the protected buildArtifact() directly.
   *
   * @param string $projectRoot
   *   Absolute path to the project root.
   * @param string $repoUrl
   *   URL of the deployment repository.
   * @param string $artifactBranch
   *   Branch name for the artifact repository.
   * @param list<string> $excludePaths
   *   Paths to exclude from the rsync.
   *
   * @return string
   *   Absolute path to the artifact directory.
   */
  public function callBuildArtifact(
    string $projectRoot,
    string $repoUrl,
    string $artifactBranch,
    array $excludePaths = [],
  ): string {
    return $this->buildArtifact($projectRoot, $repoUrl, $artifactBranch, $excludePaths);
  }

}
