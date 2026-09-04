<?php

declare(strict_types=1);

namespace Bounteous\Suds\Drush\Commands;

use Bounteous\Suds\Config\ConfigLoaderAwareTrait;
use Bounteous\Suds\Process\GitCommandRunner;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for deploying a production artifact.
 */
class DeployCommands extends DrushCommands {

  use ConfigLoaderAwareTrait;
  use ProcessHelperTrait {
    runShellCommand as executeShellCommand;
  }

  /**
   * Whether to simulate execution without running any shell commands.
   *
   * @var bool
   */
  private bool $dryRun = FALSE;

  /**
   * Build a production artifact and push it to the deployment repository.
   *
   * Assembles a clean artifact directory, runs build steps, and commits on
   * top of the artifact branch's existing history (if any) before pushing
   * to the configured deployment repository. Environment
   * variables $SUDS_BRANCH, $SUDS_HASH, and $SUDS_SHORT_HASH are
   * set before hook and step execution so they may be referenced in
   * commit_message, branch names, and shell commands.
   *
   * @param array $options
   *   An associative array of options.
   *
   * @phpstan-param array{dry-run: bool, tag: string|null} $options
   *
   * @command suds:deploy
   * @aliases su-deploy
   * @bootstrap none
   * @option dry-run Print the commands that would run without executing them.
   * @option tag Create a git tag on the artifact repository after pushing.
   * @usage drush suds:deploy
   *   Build and push a production artifact to the configured deployment
   *   repository.
   * @usage drush suds:deploy --dry-run
   *   Print the commands that would run without executing them.
   * @usage drush suds:deploy --tag=v1.2.3
   *   Deploy and create tag v1.2.3 on the artifact repository.
   */
  public function deploy(array $options = ['dry-run' => FALSE, 'tag' => NULL]): void {
    $this->dryRun = (bool) $options['dry-run'];
    $loader = $this->configLoader();
    $config = $loader->load();
    $projectRoot = $loader->getProjectRoot();

    $repoUrl = $config['deploy']['repo']['url'] ?? '';
    if (empty($repoUrl)) {
      throw new \RuntimeException(
        'deploy.repo.url is not configured. Set it in suds.yml.',
      );
    }

    $sourceBranch = $this->getCurrentBranch($projectRoot);
    $headHash = $this->getHeadHash($projectRoot);

    if ($sourceBranch === '') {
      throw new \RuntimeException(
        'Could not determine the current git branch. '
        . 'Ensure the project root is inside a git repository.',
      );
    }
    if ($sourceBranch === 'HEAD') {
      throw new \RuntimeException(
        'Detached HEAD state — suds:deploy cannot determine the current branch. '
        . 'Set `deploy.repo.branch` to a fixed value or CI environment variable '
        . 'in suds.ci.yml (e.g. branch: \'$GITHUB_REF_NAME-build\').',
      );
    }
    if ($headHash === '') {
      throw new \RuntimeException(
        'Could not determine the HEAD commit hash. '
        . 'Ensure the project root is inside a git repository.',
      );
    }

    $shortHash = substr($headHash, 0, 8);

    putenv("SUDS_BRANCH={$sourceBranch}");
    putenv("SUDS_HASH={$headHash}");
    putenv("SUDS_SHORT_HASH={$shortHash}");

    $artifactBranch = $this->expandEnvVars($config['deploy']['repo']['branch']);
    /** @var list<string> $excludePaths */
    $excludePaths = array_values(array_merge(
      $config['deploy']['exclude'] ?? [],
      $config['deploy']['exclude_extra'] ?? [],
    ));

    $this->io()->title('SUDS: Deploying');
    $this->warnConfigIssues();
    $this->io()->note(sprintf('Source branch:   %s', $sourceBranch));
    $this->io()->note(sprintf('Artifact branch: %s', $artifactBranch));
    $this->io()->note(sprintf('Target repo:     %s', $repoUrl));
    if (!empty($options['tag'])) {
      $this->io()->note(sprintf('Tag:             %s', $options['tag']));
    }

    // Run pre_deploy hooks in project root.
    foreach ($config['deploy']['hooks']['pre_deploy'] as $cmd) {
      $this->io()->note(sprintf('Running: %s', $cmd));
      $this->runShellCommand($cmd, $projectRoot);
    }

    // Build the artifact directory.
    $artifactDir = $this->buildArtifact($projectRoot, $repoUrl, $artifactBranch, $excludePaths);

    // All remaining steps use the artifact directory. If any step fails the
    // finally block removes the directory so it does not accumulate in /tmp.
    try {
      // Run build_steps in artifact dir.
      foreach ($config['deploy']['build_steps'] as $cmd) {
        $this->io()->note(sprintf('Running: %s', $cmd));
        $this->runShellCommand($cmd, $artifactDir);
      }

      // Write build manifest if configured.
      if ($config['deploy']['manifest'] ?? FALSE) {
        $manifestFile = (string) ($config['deploy']['manifest_file'] ?? 'SUDS_BUILD.txt');
        $manifestContent = implode(PHP_EOL, [
          'branch: ' . $sourceBranch,
          'hash: ' . $headHash,
          'short_hash: ' . $shortHash,
          'built_at: ' . (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]) . PHP_EOL;
        $this->io()->note(sprintf('Writing build manifest: %s', $manifestFile));
        if (!$this->dryRun) {
          $this->writeManifestFile($artifactDir . '/' . $manifestFile, $manifestContent);
        }
      }

      // Configure git identity in artifact repo.
      $this->runGitCommand(['config', 'user.name', $config['deploy']['git']['name']], $artifactDir);
      $this->runGitCommand(['config', 'user.email', $config['deploy']['git']['email']], $artifactDir);

      // Commit and push the artifact.
      $commitMessage = $this->expandEnvVars($config['deploy']['commit_message']);
      $this->runGitCommand(['add', '-A', '.'], $artifactDir);
      $this->runGitCommand(['commit', '--allow-empty', '-m', $commitMessage], $artifactDir);
      $pushArgs = ['push', 'origin', $artifactBranch];
      if ($config['deploy']['force_push'] ?? FALSE) {
        $pushArgs[] = '--force';
      }
      $this->runGitCommand($pushArgs, $artifactDir);

      // Create and push tag on artifact repository if --tag is specified.
      if (!empty($options['tag'])) {
        $this->runGitCommand(['tag', (string) $options['tag']], $artifactDir);
        $this->runGitCommand(['push', 'origin', (string) $options['tag']], $artifactDir);
      }

      // Run post_deploy hooks in project root.
      foreach ($config['deploy']['hooks']['post_deploy'] as $cmd) {
        $this->io()->note(sprintf('Running: %s', $cmd));
        $this->runShellCommand($cmd, $projectRoot);
      }
    }
    finally {
      $this->removeDirectory($artifactDir);
    }

    $this->io()->success('Deployment complete.');
  }

  /**
   * Returns the current git branch name.
   *
   * @param string $projectRoot
   *   Absolute path to the project root.
   *
   * @return string
   *   The current branch name.
   */
  protected function getCurrentBranch(string $projectRoot): string {
    return $this->gitCommandRunner()->run(['rev-parse', '--abbrev-ref', 'HEAD'], $projectRoot)->output;
  }

  /**
   * Returns the HEAD commit hash.
   *
   * @param string $projectRoot
   *   Absolute path to the project root.
   *
   * @return string
   *   The full HEAD commit SHA.
   */
  protected function getHeadHash(string $projectRoot): string {
    return $this->gitCommandRunner()->run(['rev-parse', 'HEAD'], $projectRoot)->output;
  }

  /**
   * Checks whether a branch exists on the remote deployment repository.
   *
   * Used to decide whether the artifact should be built on top of the
   * branch's existing history or as a brand-new branch. Runs even in
   * dry-run mode since it is read-only and needed to determine which
   * commands would be printed.
   *
   * @param string $repoUrl
   *   URL of the deployment repository.
   * @param string $branch
   *   Branch name to check for.
   *
   * @return bool
   *   TRUE if the branch exists on the remote.
   */
  protected function remoteBranchExists(string $repoUrl, string $branch): bool {
    return $this->gitCommandRunner()
      ->run(['ls-remote', '--exit-code', $repoUrl, 'refs/heads/' . $branch], (string) getcwd())
      ->isSuccessful();
  }

  /**
   * {@inheritdoc}
   *
   * In dry-run mode, prints the command that would run instead of executing it.
   */
  protected function runShellCommand(string $cmd, string $cwd = ''): void {
    if ($this->dryRun) {
      $this->io()->note(sprintf('[dry-run] %s (in %s)', $cmd, $cwd ?: 'cwd'));
      return;
    }
    $this->executeShellCommand($cmd, $cwd);
  }

  /**
   * Runs a git command, streaming output and failing on error.
   *
   * Mirrors runShellCommand()'s dry-run override, but for the git-specific
   * mutation call sites that go through GitCommandRunner instead of the
   * shell-string-based Process::fromShellCommandline() path.
   *
   * @param list<string> $args
   *   Arguments to pass to git, excluding the leading 'git'.
   * @param string $cwd
   *   Working directory for the command.
   */
  protected function runGitCommand(array $args, string $cwd): void {
    if ($this->dryRun) {
      $this->io()->note(sprintf('[dry-run] git %s (in %s)', implode(' ', $args), $cwd));
      return;
    }
    $this->gitCommandRunner()->mustRun($args, $cwd, $this->forwardProcessOutput(...));
  }

  /**
   * Returns the git command runner.
   *
   * @return \Bounteous\Suds\Process\GitCommandRunner
   *   The git command runner. Stateless, so a fresh instance is fine.
   */
  private function gitCommandRunner(): GitCommandRunner {
    return new GitCommandRunner();
  }

  /**
   * Builds the artifact directory.
   *
   * Creates a temporary directory, initializes a git repository, adds the
   * remote origin, and checks out the artifact branch. If the branch
   * already exists on the remote, its history is fetched and the new build
   * is committed on top of it; otherwise a fresh branch is created. The
   * project contents are then rsynced into it.
   *
   * @param string $projectRoot
   *   Absolute path to the project root.
   * @param string $repoUrl
   *   URL of the deployment repository.
   * @param string $artifactBranch
   *   Branch to create or update in the artifact repository.
   * @param list<string> $excludePaths
   *   Paths to exclude from the artifact, relative to the project root.
   *
   * @return string
   *   Absolute path to the artifact directory.
   */
  protected function buildArtifact(
    string $projectRoot,
    string $repoUrl,
    string $artifactBranch,
    array $excludePaths,
  ): string {
    $artifactDir = sys_get_temp_dir() . '/suds-deploy-' . uniqid();
    if (!$this->dryRun) {
      mkdir($artifactDir, 0777, TRUE);
    }

    $this->runGitCommand(['init', '.'], $artifactDir);
    $this->runGitCommand(['remote', 'add', 'origin', $repoUrl], $artifactDir);

    if ($this->remoteBranchExists($repoUrl, $artifactBranch)) {
      $this->runGitCommand(['fetch', 'origin', $artifactBranch], $artifactDir);
      $this->runGitCommand(['checkout', '-B', $artifactBranch, 'origin/' . $artifactBranch], $artifactDir);
    }
    else {
      $this->runGitCommand(['checkout', '-b', $artifactBranch], $artifactDir);
    }

    $excludeArgs = $this->buildExcludeArgs($excludePaths);
    $this->runShellCommand(
      sprintf(
        'rsync -rlptD --delete --exclude=.git %s %s/ %s/',
        $excludeArgs,
        escapeshellarg($projectRoot),
        escapeshellarg($artifactDir),
      ),
      $projectRoot,
    );

    return $artifactDir;
  }

  /**
   * Builds rsync --exclude= arguments from a list of paths.
   *
   * @param list<string> $paths
   *   Paths to exclude.
   *
   * @return string
   *   A string of space-separated --exclude= arguments.
   */
  private function buildExcludeArgs(array $paths): string {
    return implode(' ', array_map(
      static fn(string $p): string => '--exclude=' . escapeshellarg($p),
      $paths,
    ));
  }

  /**
   * Writes the build manifest file to the artifact directory.
   *
   * Extracted as a protected method so subclasses can override it in tests
   * without performing real filesystem writes.
   *
   * @param string $filePath
   *   Absolute path to the manifest file to write.
   * @param string $content
   *   Content to write to the manifest file.
   */
  protected function writeManifestFile(string $filePath, string $content): void {
    file_put_contents($filePath, $content);
  }

  /**
   * Recursively removes a directory and all of its contents.
   *
   * Used to clean up the temporary artifact directory after deploy completes
   * or fails. Silently returns when the directory does not exist so that
   * dry-run mode (which skips mkdir) does not need special-casing.
   *
   * @param string $dir
   *   Absolute path to the directory to remove.
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

  /**
   * Expands $VAR and ${VAR} references in a string using getenv().
   *
   * Only expands names matching [A-Za-z_][A-Za-z0-9_]*. Unknown variables
   * expand to an empty string. No shell is invoked; expansion is safe from
   * command injection.
   *
   * @param string $template
   *   The template string containing variable references.
   *
   * @return string
   *   The expanded string.
   */
  private function expandEnvVars(string $template): string {
    return preg_replace_callback(
      '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}|\$([A-Za-z_][A-Za-z0-9_]*)/',
      static fn(array $m): string => (string) (getenv($m[1] !== '' ? $m[1] : $m[2]) ?: ''),
      $template,
    ) ?? $template;
  }

}
