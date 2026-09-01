<?php

declare(strict_types=1);

namespace Bounteous\Suds\Drush\Commands;

use Consolidation\SiteAlias\SiteAlias;
use Drush\Drush;
use Symfony\Component\Process\Process;

/**
 * Provides reusable shell-execution and Drush redispatch helpers.
 *
 * Extracted to avoid duplicating these methods across every command class that
 * shells out to Drush sub-commands or arbitrary shell tools. The methods are
 * declared protected so that integration-test subclasses (e.g.
 * TestableSetupCommands) can override them as no-ops without spawning real
 * subprocesses.
 */
trait ProcessHelperTrait {

  /**
   * Dispatches a Drush sub-command and streams its output.
   *
   * Wraps the three-line processManager()->drush() + mustRun() pattern so
   * that command implementations read as intent rather than plumbing. The
   * protected visibility lets integration-test subclasses override it as a
   * no-op without spawning real Drush processes.
   *
   * @param \Consolidation\SiteAlias\SiteAlias $alias
   *   The site alias to dispatch against (typically getSelf()).
   * @param string $cmd
   *   The Drush command name (e.g. 'cache:rebuild', 'sql:sync').
   * @param array<mixed> $args
   *   Positional arguments for the command.
   * @param array<string, mixed> $opts
   *   Options to pass to the command.
   */
  protected function runDrushCommand(
    SiteAlias $alias,
    string $cmd,
    array $args = [],
    array $opts = [],
  ): void {
    $process = $this->processManager()->drush($alias, $cmd, $args, $opts);
    $process->mustRun($process->showRealtime());
  }

  /**
   * Dispatches a Drush sub-command and returns its captured STDOUT.
   *
   * Unlike runDrushCommand(), output is captured and returned rather than
   * streamed, for callers that need to read the result (e.g. `drush status
   * --format=json` or `drush config:get --format=string`).
   *
   * @param \Consolidation\SiteAlias\SiteAlias $alias
   *   The site alias to dispatch against (typically getSelf()).
   * @param string $cmd
   *   The Drush command name (e.g. 'status', 'config:get').
   * @param array<mixed> $args
   *   Positional arguments for the command.
   * @param array<string, mixed> $opts
   *   Options to pass to the command.
   *
   * @return string
   *   The trimmed STDOUT of the command.
   */
  protected function runDrushCommandCapture(
    SiteAlias $alias,
    string $cmd,
    array $args = [],
    array $opts = [],
  ): string {
    $process = $this->processManager()->drush($alias, $cmd, $args, $opts);
    $process->mustRun();
    return trim($process->getOutput());
  }

  /**
   * Returns options to forward to child drush processes.
   *
   * Extracted as a protected method so unit tests can override it without
   * requiring a fully initialized Drush DI container.
   *
   * @return array<string, mixed>
   *   Options array suitable for passing to ProcessManager::drush().
   */
  protected function redispatchOptions(): array {
    return Drush::redispatchOptions();
  }

  /**
   * Finds an executable on the system PATH.
   *
   * @param string $binary
   *   The binary name to search for (e.g. 'composer').
   *
   * @return string|null
   *   The absolute path to the binary, or NULL if not found.
   */
  protected function findExecutable(string $binary): ?string {
    // phpcs:ignore
    $path = trim((string) shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($binary))));
    return $path !== '' ? $path : NULL;
  }

  /**
   * Runs an arbitrary shell command, streaming output and failing on error.
   *
   * STDOUT is forwarded via io()->write(). STDERR is routed through the error
   * style so it is visually distinct in the terminal.
   *
   * @param string $cmd
   *   The shell command to execute.
   * @param string $cwd
   *   Working directory for the command.
   *
   * @throws \Symfony\Component\Process\Exception\ProcessFailedException
   *   When the command exits with a non-zero status.
   */
  protected function runShellCommand(string $cmd, string $cwd): void {
    $process = Process::fromShellCommandline($cmd, $cwd);
    $process->setTimeout(NULL);
    $io = $this->io();
    $process->mustRun(static function (string $type, string $buffer) use ($io): void {
      if ($type === Process::ERR) {
        $io->getErrorStyle()->write($buffer);
      }
      else {
        $io->write($buffer);
      }
    });
  }

}
