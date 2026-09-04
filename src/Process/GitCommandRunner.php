<?php

declare(strict_types=1);

namespace Bounteous\Suds\Process;

use Symfony\Component\Process\Process;

/**
 * Runs the git binary via Symfony Process.
 *
 * Replaces ad-hoc shell_exec()/exec()/Process::fromShellCommandline() call
 * sites that invoke git. Arguments are always passed as an array to Process
 * rather than as a shell string, so no escapeshellarg() is needed for git
 * invocations. Two modes are provided: a non-throwing capture mode for
 * read-only queries, and a streaming, throwing mode for mutating commands.
 */
class GitCommandRunner {

  /**
   * Runs a git command and captures its result without throwing.
   *
   * Intended for read-only queries (current branch, HEAD hash, remote
   * branch existence) where the caller wants to inspect the exit code
   * itself rather than handle an exception. Timeout is disabled since
   * queries against a remote (ls-remote) may legitimately take a while,
   * and a timeout would surface as an exception rather than a result.
   *
   * @param list<string> $args
   *   Arguments to pass to git, excluding the leading 'git'.
   * @param string $cwd
   *   Working directory for the command.
   *
   * @return \Bounteous\Suds\Process\GitCommandResult
   *   The exit code and trimmed STDOUT.
   */
  public function run(array $args, string $cwd): GitCommandResult {
    $process = new Process(['git', ...$args], $cwd);
    $process->setTimeout(NULL);
    $process->run();
    return new GitCommandResult(
      (int) $process->getExitCode(),
      trim($process->getOutput()),
    );
  }

  /**
   * Runs a git command, streaming output and throwing on failure.
   *
   * Intended for mutating commands (commit, push, checkout, etc.) where a
   * non-zero exit should abort the calling command. Timeout is disabled
   * since git operations such as push/fetch may legitimately take a while.
   *
   * @param list<string> $args
   *   Arguments to pass to git, excluding the leading 'git'.
   * @param string $cwd
   *   Working directory for the command.
   * @param callable|null $callback
   *   Optional callback invoked with ($type, $buffer) as output is produced.
   *
   * @throws \Symfony\Component\Process\Exception\ProcessFailedException
   *   When the command exits with a non-zero status.
   */
  public function mustRun(array $args, string $cwd, ?callable $callback = NULL): void {
    $process = new Process(['git', ...$args], $cwd);
    $process->setTimeout(NULL);
    $process->mustRun($callback);
  }

}
