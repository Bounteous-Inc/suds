<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Unit\Process;

use Bounteous\Suds\Process\GitCommandRunner;
use Bounteous\Suds\Tests\Support\TempDirectoryTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Unit tests for GitCommandRunner.
 *
 * Runs against a real temporary git repository since GitCommandRunner is a
 * thin wrapper around the real git binary with no side effects worth
 * mocking.
 */
#[CoversClass(GitCommandRunner::class)]
class GitCommandRunnerTest extends TestCase {

  use TempDirectoryTrait;

  /**
   * Temporary git repository used by each test.
   *
   * @var string
   */
  private string $repo;

  /**
   * The runner under test.
   *
   * @var \Bounteous\Suds\Process\GitCommandRunner
   */
  private GitCommandRunner $runner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->repo = $this->createTempDir('suds_git_runner_');
    $this->runner = new GitCommandRunner();
    (new Process(['git', 'init', '.'], $this->repo))->mustRun();
    (new Process(['git', 'config', 'user.name', 'Test'], $this->repo))->mustRun();
    (new Process(['git', 'config', 'user.email', 'test@example.com'], $this->repo))->mustRun();
    (new Process(['git', 'commit', '--allow-empty', '-m', 'init'], $this->repo))->mustRun();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeDirectory($this->repo);
  }

  /**
   * Verifies run() captures STDOUT from a successful git command.
   */
  public function testRunCapturesOutput(): void {
    $result = $this->runner->run(['rev-parse', '--abbrev-ref', 'HEAD'], $this->repo);

    $this->assertTrue($result->isSuccessful());
    $this->assertSame(0, $result->exitCode);
    $this->assertNotSame('', $result->output);
  }

  /**
   * Verifies run() does not throw and reports failure for a bad command.
   */
  public function testRunReportsFailureWithoutThrowing(): void {
    $result = $this->runner->run(['this-is-not-a-git-command'], $this->repo);

    $this->assertFalse($result->isSuccessful());
    $this->assertNotSame(0, $result->exitCode);
  }

  /**
   * Verifies mustRun() succeeds silently for a valid git command.
   */
  public function testMustRunSucceeds(): void {
    $this->runner->mustRun(['rev-parse', 'HEAD'], $this->repo);
    $this->addToAssertionCount(1);
  }

  /**
   * Verifies mustRun() throws ProcessFailedException on a failing command.
   */
  public function testMustRunThrowsOnFailure(): void {
    $this->expectException(ProcessFailedException::class);
    $this->runner->mustRun(['this-is-not-a-git-command'], $this->repo);
  }

}
