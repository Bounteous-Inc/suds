<?php

declare(strict_types=1);

namespace Bounteous\Suds\Process;

/**
 * The result of a captured (non-throwing) git command invocation.
 *
 * Returned by GitCommandRunner::run() so read-only callers (branch/hash
 * lookups, remote existence checks) can inspect the exit code and output
 * without a try/catch, unlike the throwing mustRun() path.
 */
final class GitCommandResult {

  /**
   * Constructs a GitCommandResult.
   *
   * @param int $exitCode
   *   The process exit code.
   * @param string $output
   *   The trimmed STDOUT of the process.
   */
  public function __construct(
    public readonly int $exitCode,
    public readonly string $output,
  ) {}

  /**
   * Returns whether the command exited successfully.
   *
   * @return bool
   *   TRUE if the exit code was 0.
   */
  public function isSuccessful(): bool {
    return $this->exitCode === 0;
  }

}
