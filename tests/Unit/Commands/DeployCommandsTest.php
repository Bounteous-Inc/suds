<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Unit\Commands;

use Bounteous\Suds\Config\ConfigLoaderInterface;
use Bounteous\Suds\Drush\Commands\DeployCommands;
use Bounteous\Suds\Tests\Support\ExposedDeployCommands;
use Drush\Style\DrushStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DeployCommands.
 */
#[CoversClass(DeployCommands::class)]
class DeployCommandsTest extends TestCase {

  /**
   * Verifies suds:deploy has the correct @command, @aliases, and @bootstrap.
   */
  public function testDeployAnnotations(): void {
    $doc = (new \ReflectionMethod(DeployCommands::class, 'deploy'))->getDocComment();
    $this->assertIsString($doc);
    $this->assertStringContainsString('suds:deploy', $doc);
    $this->assertStringContainsString('su-deploy', $doc);
    $this->assertStringContainsString('@bootstrap none', $doc);
    $this->assertStringContainsString('@option dry-run', $doc);
  }

  /**
   * Verifies deploy() throws when deploy.repo.url is not set.
   */
  public function testDeployThrowsWhenRepoUrlNotSet(): void {
    $command = $this->buildCommand();
    $command->setConfigLoader($this->makeConfigLoader(repoUrl: ''));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/deploy\.repo\.url/');

    $command->deploy();
  }

  /**
   * Verifies deploy() throws when the current branch cannot be determined.
   */
  public function testDeployThrowsWhenBranchEmpty(): void {
    $command = $this->getMockBuilder(DeployCommands::class)
      ->onlyMethods(['io', 'getCurrentBranch', 'getHeadHash', 'buildArtifact', 'runShellCommand'])
      ->getMock();
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->method('getCurrentBranch')->willReturn('');
    $command->method('getHeadHash')->willReturn('deadbeef12345678deadbeef12345678deadbeef');
    $command->method('buildArtifact')->willReturn('/tmp/artifact-test');
    $command->method('runShellCommand')->willReturnCallback(static function (): void {});
    $command->setConfigLoader($this->makeConfigLoader());

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/current git branch/');

    $command->deploy();
  }

  /**
   * Verifies deploy() throws when in detached HEAD state.
   */
  public function testDeployThrowsOnDetachedHead(): void {
    $command = $this->getMockBuilder(DeployCommands::class)
      ->onlyMethods(['io', 'getCurrentBranch', 'getHeadHash', 'buildArtifact', 'runShellCommand'])
      ->getMock();
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->method('getCurrentBranch')->willReturn('HEAD');
    $command->method('getHeadHash')->willReturn('deadbeef12345678deadbeef12345678deadbeef');
    $command->method('buildArtifact')->willReturn('/tmp/artifact-test');
    $command->method('runShellCommand')->willReturnCallback(static function (): void {});
    $command->setConfigLoader($this->makeConfigLoader());

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/Detached HEAD/');

    $command->deploy();
  }

  /**
   * Verifies pre_deploy hooks run before buildArtifact is called.
   *
   * Tracks the call order of runShellCommand and buildArtifact to assert that
   * the pre_deploy hook executes first.
   */
  public function testDeployRunsPreDeployHooksBeforeArtifact(): void {
    $callLog = [];

    $command = $this->buildCommand();
    $command->setConfigLoader($this->makeConfigLoader(
      preDeployHooks: ['echo pre-deploy'],
    ));

    $command->method('runShellCommand')
      ->willReturnCallback(static function (string $cmd) use (&$callLog): void {
        $callLog[] = $cmd;
      });
    $command->method('buildArtifact')
      ->willReturnCallback(static function () use (&$callLog): string {
        $callLog[] = '__buildArtifact__';
        return '/tmp/artifact-test';
      });

    $command->deploy();

    $preDeployIdx = array_search('echo pre-deploy', $callLog, TRUE);
    $buildIdx = array_search('__buildArtifact__', $callLog, TRUE);

    $this->assertNotFalse($preDeployIdx, 'Pre-deploy hook was not called.');
    $this->assertNotFalse($buildIdx, 'buildArtifact was not called.');
    $this->assertLessThan($buildIdx, $preDeployIdx, 'Pre-deploy hook must run before buildArtifact.');
  }

  /**
   * Verifies build_steps are executed in the artifact directory.
   */
  public function testDeployRunsBuildStepsInArtifactDir(): void {
    $calls = [];

    $command = $this->buildCommand();
    $command->setConfigLoader($this->makeConfigLoader(
      buildSteps: ['npm run build'],
    ));

    $command->method('runShellCommand')
      ->willReturnCallback(static function (string $cmd, string $cwd) use (&$calls): void {
        $calls[] = ['cmd' => $cmd, 'cwd' => $cwd];
      });

    $command->deploy();

    $buildStepCalls = array_filter($calls, static fn(array $c) => $c['cmd'] === 'npm run build');
    $this->assertNotEmpty($buildStepCalls, 'build_steps command was not called.');

    foreach ($buildStepCalls as $call) {
      $this->assertSame('/tmp/artifact-test', $call['cwd'], 'build_steps must run in the artifact dir.');
    }
  }

  /**
   * Verifies post_deploy hooks run after the git push.
   */
  public function testDeployRunsPostDeployHooksAfterPush(): void {
    $callLog = [];

    $command = $this->buildCommand();
    $command->setConfigLoader($this->makeConfigLoader(
      postDeployHooks: ['echo post-deploy'],
    ));

    $command->method('runShellCommand')
      ->willReturnCallback(static function (string $cmd) use (&$callLog): void {
        $callLog[] = $cmd;
      });

    $command->deploy();

    $pushIdx = NULL;
    $postIdx = NULL;
    foreach ($callLog as $i => $cmd) {
      if (str_contains($cmd, 'git push')) {
        $pushIdx = $i;
      }
      if ($cmd === 'echo post-deploy') {
        $postIdx = $i;
      }
    }

    $this->assertNotNull($pushIdx, 'git push was not called.');
    $this->assertNotNull($postIdx, 'Post-deploy hook was not called.');
    $this->assertGreaterThan($pushIdx, $postIdx, 'Post-deploy hook must run after git push.');
  }

  /**
   * Verifies that with all hook lists empty, only core git commands run.
   *
   * With pre_deploy=[], build_steps=[], and post_deploy=[] the only
   * runShellCommand calls should be: git config user.name, git config
   * user.email, git add, git commit, and git push — exactly five.
   */
  public function testDeploySkipsHooksWhenAllEmpty(): void {
    $command = $this->buildCommand();
    $command->setConfigLoader($this->makeConfigLoader());

    $command->expects($this->exactly(5))
      ->method('runShellCommand');

    $command->deploy();
  }

  /**
   * Verifies the artifact branch is built by expanding $SUDS_BRANCH.
   *
   * Builds the mock from scratch so getCurrentBranch can be configured
   * independently of the default in buildCommand().
   */
  public function testDeploySubstitutesSourceBranchInArtifactBranch(): void {
    $command = $this->getMockBuilder(DeployCommands::class)
      ->onlyMethods(['io', 'getCurrentBranch', 'getHeadHash', 'buildArtifact', 'runShellCommand'])
      ->getMock();
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->method('getCurrentBranch')->willReturn('feature-x');
    $command->method('getHeadHash')->willReturn('deadbeef12345678deadbeef12345678deadbeef');
    $command->method('runShellCommand')->willReturnCallback(static function (string $cmd, string $cwd): void {});

    $command->setConfigLoader($this->makeConfigLoader(
      repoBranch: '$SUDS_BRANCH-artifact',
    ));

    $command->expects($this->once())
      ->method('buildArtifact')
      ->with(
        $this->anything(),
        $this->anything(),
        'feature-x-artifact',
        $this->anything(),
      )
      ->willReturn('/tmp/artifact-test');

    $command->deploy();
  }

  /**
   * Verifies commit_message env var substitution uses the HEAD hash.
   *
   * Builds the mock from scratch so getHeadHash can be configured
   * independently of the default in buildCommand().
   */
  public function testDeployExpandsEnvVarsInCommitMessage(): void {
    $command = $this->getMockBuilder(DeployCommands::class)
      ->onlyMethods(['io', 'getCurrentBranch', 'getHeadHash', 'buildArtifact', 'runShellCommand'])
      ->getMock();
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->method('getCurrentBranch')->willReturn('main');
    $command->method('getHeadHash')->willReturn('abc1234def56789abc1234def56789abc1234def5');
    $command->method('buildArtifact')->willReturn('/tmp/artifact-test');

    $command->setConfigLoader($this->makeConfigLoader(
      commitMessage: 'Deploy $SUDS_BRANCH [$SUDS_SHORT_HASH]',
    ));

    $shellCalls = [];
    $command->method('runShellCommand')
      ->willReturnCallback(static function (string $cmd) use (&$shellCalls): void {
        $shellCalls[] = $cmd;
      });

    $command->deploy();

    $commitCalls = array_filter($shellCalls, static fn(string $c) => str_contains($c, 'git commit'));
    $this->assertNotEmpty($commitCalls, 'git commit was not called.');

    // 'abc1234def56789abc1234def56789abc1234def5' — first 8 chars = 'abc1234d'
    $commitCmd = reset($commitCalls);
    $this->assertStringContainsString('Deploy main [abc1234d]', $commitCmd);
  }

  /**
   * Verifies git identity is configured via runShellCommand in artifact dir.
   */
  public function testDeployConfiguresGitIdentityInArtifactRepo(): void {
    $calls = [];

    $command = $this->buildCommand();
    $command->setConfigLoader($this->makeConfigLoader(
      gitName: 'My CI Bot',
      gitEmail: 'ci@example.com',
    ));

    $command->method('runShellCommand')
      ->willReturnCallback(static function (string $cmd, string $cwd) use (&$calls): void {
        $calls[] = ['cmd' => $cmd, 'cwd' => $cwd];
      });

    $command->deploy();

    $nameCalls = array_filter($calls, static fn(array $c) => str_contains($c['cmd'], 'git config user.name'));
    $emailCalls = array_filter($calls, static fn(array $c) => str_contains($c['cmd'], 'git config user.email'));

    $this->assertNotEmpty($nameCalls, 'git config user.name was not called.');
    $this->assertNotEmpty($emailCalls, 'git config user.email was not called.');

    foreach ($nameCalls as $call) {
      $this->assertStringContainsString('My CI Bot', $call['cmd']);
      $this->assertSame('/tmp/artifact-test', $call['cwd']);
    }
    foreach ($emailCalls as $call) {
      $this->assertStringContainsString('ci@example.com', $call['cmd']);
      $this->assertSame('/tmp/artifact-test', $call['cwd']);
    }
  }

  /**
   * Verifies expandEnvVars() replaces an unknown variable with an empty string.
   */
  public function testExpandEnvVarsUnknownVarBecomesEmpty(): void {
    // Guarantee the variable is not set in the test environment.
    putenv('SUDS_TEST_UNKNOWN_XYZ');

    $command = $this->buildCommand();
    $command->setConfigLoader($this->makeConfigLoader(
      commitMessage: 'prefix-$SUDS_TEST_UNKNOWN_XYZ-suffix',
    ));

    $shellCalls = [];
    $command->method('runShellCommand')
      ->willReturnCallback(static function (string $cmd) use (&$shellCalls): void {
        $shellCalls[] = $cmd;
      });

    $command->deploy();

    $commitCalls = array_filter($shellCalls, static fn(string $c) => str_contains($c, 'git commit'));
    $this->assertNotEmpty($commitCalls);
    $this->assertStringContainsString('prefix--suffix', reset($commitCalls));
  }

  /**
   * Verifies expandEnvVars() handles ${BRACED} variable syntax.
   */
  public function testExpandEnvVarsBracedSyntax(): void {
    $command = $this->getMockBuilder(DeployCommands::class)
      ->onlyMethods(['io', 'getCurrentBranch', 'getHeadHash', 'buildArtifact', 'runShellCommand'])
      ->getMock();
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->method('getCurrentBranch')->willReturn('release-1');
    $command->method('getHeadHash')->willReturn('deadbeef12345678deadbeef12345678deadbeef');
    $command->method('buildArtifact')->willReturn('/tmp/artifact-test');

    $command->setConfigLoader($this->makeConfigLoader(
      commitMessage: 'Deploy ${SUDS_BRANCH}',
    ));

    $shellCalls = [];
    $command->method('runShellCommand')
      ->willReturnCallback(static function (string $cmd) use (&$shellCalls): void {
        $shellCalls[] = $cmd;
      });

    $command->deploy();

    $commitCalls = array_filter($shellCalls, static fn(string $c) => str_contains($c, 'git commit'));
    $this->assertNotEmpty($commitCalls);
    $this->assertStringContainsString('Deploy release-1', reset($commitCalls));
  }

  /**
   * Verifies expandEnvVars() passes through plain text without variables.
   */
  public function testExpandEnvVarsPlainTextPassesThrough(): void {
    $command = $this->buildCommand();
    $command->setConfigLoader($this->makeConfigLoader(
      commitMessage: 'Automated deployment',
    ));

    $shellCalls = [];
    $command->method('runShellCommand')
      ->willReturnCallback(static function (string $cmd) use (&$shellCalls): void {
        $shellCalls[] = $cmd;
      });

    $command->deploy();

    $commitCalls = array_filter($shellCalls, static fn(string $c) => str_contains($c, 'git commit'));
    $this->assertNotEmpty($commitCalls);
    $this->assertStringContainsString('Automated deployment', reset($commitCalls));
  }

  /**
   * Verifies deploy() prints [dry-run] notes instead of executing commands.
   *
   * RunShellCommand is NOT mocked so that the real dry-run override in
   * DeployCommands fires. Io()->note() is captured to assert that dry-run
   * output is produced for each command that would have run.
   */
  public function testDeployDryRunPrintsNotesInsteadOfExecuting(): void {
    $notes = [];
    $io = $this->createMock(DrushStyle::class);
    $io->method('note')
      ->willReturnCallback(static function (string $msg) use (&$notes): void {
        $notes[] = $msg;
      });
    $io->method('title')->willReturnCallback(static function (): void {});
    $io->method('success')->willReturnCallback(static function (): void {});

    // Do NOT mock runShellCommand — we want the real dry-run override to fire.
    $command = $this->getMockBuilder(DeployCommands::class)
      ->onlyMethods(['io', 'getCurrentBranch', 'getHeadHash', 'buildArtifact'])
      ->getMock();
    $command->method('io')->willReturn($io);
    $command->method('getCurrentBranch')->willReturn('main');
    $command->method('getHeadHash')->willReturn('deadbeef12345678deadbeef12345678deadbeef');
    $command->method('buildArtifact')->willReturn('/tmp/artifact-test');
    $command->setConfigLoader($this->makeConfigLoader());

    $command->deploy(['dry-run' => TRUE, 'tag' => NULL]);

    $dryRunNotes = array_filter($notes, static fn(string $n) => str_starts_with($n, '[dry-run]'));
    $this->assertNotEmpty($dryRunNotes, 'Expected [dry-run] notes to be printed.');
  }

  /**
   * Verifies buildArtifact() runs git init in the artifact directory.
   */
  public function testBuildArtifactRunsGitInit(): void {
    $exposed = $this->makeExposedCommand();
    $artifactDir = $exposed->callBuildArtifact('/tmp/project', 'git@example.com:repo.git', 'main-build');

    $this->assertDirectoryExists($artifactDir);
    $this->removeDir($artifactDir);

    $cmds = array_column($exposed->shellLog, 'cmd');
    $this->assertContains('git init .', $cmds);
  }

  /**
   * Verifies buildArtifact() adds the remote origin URL.
   */
  public function testBuildArtifactSetsRemoteOrigin(): void {
    $exposed = $this->makeExposedCommand();
    $artifactDir = $exposed->callBuildArtifact('/tmp/project', 'git@example.com:org/repo.git', 'main-build');

    $this->assertDirectoryExists($artifactDir);
    $this->removeDir($artifactDir);

    $cmds = array_column($exposed->shellLog, 'cmd');
    $originCmd = array_filter($cmds, static fn(string $c) => str_contains($c, 'git remote add origin'));
    $this->assertNotEmpty($originCmd);
    $this->assertStringContainsString('git@example.com:org/repo.git', reset($originCmd));
  }

  /**
   * Verifies buildArtifact() checks out the artifact branch.
   */
  public function testBuildArtifactChecksOutBranch(): void {
    $exposed = $this->makeExposedCommand();
    $artifactDir = $exposed->callBuildArtifact('/tmp/project', 'git@example.com:repo.git', 'feature-x-build');

    $this->assertDirectoryExists($artifactDir);
    $this->removeDir($artifactDir);

    $cmds = array_column($exposed->shellLog, 'cmd');
    $checkoutCmd = array_filter($cmds, static fn(string $c) => str_contains($c, 'git checkout -b'));
    $this->assertNotEmpty($checkoutCmd);
    $this->assertStringContainsString('feature-x-build', reset($checkoutCmd));
  }

  /**
   * Verifies buildArtifact() includes rsync --exclude= args for each path.
   */
  public function testBuildArtifactIncludesExcludeArgs(): void {
    $exposed = $this->makeExposedCommand();
    $artifactDir = $exposed->callBuildArtifact(
      '/tmp/project',
      'git@example.com:repo.git',
      'main-build',
      ['suds.local.yml', '.env'],
    );

    $this->assertDirectoryExists($artifactDir);
    $this->removeDir($artifactDir);

    $cmds = array_column($exposed->shellLog, 'cmd');
    $rsyncCmd = array_filter($cmds, static fn(string $c) => str_contains($c, 'rsync'));
    $this->assertNotEmpty($rsyncCmd, 'rsync command was not generated.');

    $rsync = reset($rsyncCmd);
    $this->assertStringContainsString('suds.local.yml', $rsync);
    $this->assertStringContainsString('.env', $rsync);
  }

  /**
   * Returns a fresh ExposedDeployCommands instance for buildArtifact() tests.
   *
   * The io() method is left un-stubbed intentionally — buildArtifact() does
   * not call it, so no mock wiring is needed for these lower-level tests.
   *
   * @return \Bounteous\Suds\Tests\Support\ExposedDeployCommands
   *   A configured testable subclass.
   */
  private function makeExposedCommand(): ExposedDeployCommands {
    return new ExposedDeployCommands();
  }

  /**
   * Removes a directory created during a test.
   *
   * @param string $dir
   *   Absolute path to the directory to remove.
   */
  private function removeDir(string $dir): void {
    if (is_dir($dir)) {
      rmdir($dir);
    }
  }

  /**
   * Builds a DeployCommands mock with standard dependencies wired up.
   *
   * Stubs io(), getCurrentBranch(), getHeadHash(), buildArtifact(), and
   * runShellCommand() with safe no-op defaults. Tests that need specific
   * getCurrentBranch or getHeadHash values should build their own mock
   * directly rather than relying on this helper.
   *
   * @return \Bounteous\Suds\Drush\Commands\DeployCommands&\PHPUnit\Framework\MockObject\MockObject
   *   A configured PHPUnit mock of DeployCommands.
   */
  private function buildCommand(): DeployCommands&MockObject {
    $command = $this->getMockBuilder(DeployCommands::class)
      ->onlyMethods(['io', 'getCurrentBranch', 'getHeadHash', 'buildArtifact', 'runShellCommand'])
      ->getMock();

    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->method('getCurrentBranch')->willReturn('main');
    $command->method('getHeadHash')->willReturn('deadbeef12345678deadbeef12345678deadbeef');
    $command->method('buildArtifact')->willReturn('/tmp/artifact-test');
    // runShellCommand has a void return type; use a callback stub.
    $command->method('runShellCommand')->willReturnCallback(static function (string $cmd, string $cwd): void {});

    return $command;
  }

  /**
   * Verifies that deploy.exclude_extra paths are merged into the rsync call.
   *
   * Paths listed in exclude_extra must be appended to exclude and passed
   * through to buildArtifact() so they are included in the rsync --exclude
   * arguments.
   */
  public function testDeployMergesExcludeExtra(): void {
    $command = $this->getMockBuilder(DeployCommands::class)
      ->onlyMethods(['io', 'getCurrentBranch', 'getHeadHash', 'buildArtifact', 'runShellCommand'])
      ->getMock();
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->method('getCurrentBranch')->willReturn('main');
    $command->method('getHeadHash')->willReturn('deadbeef12345678deadbeef12345678deadbeef');
    $command->method('runShellCommand')->willReturnCallback(static function (string $cmd, string $cwd): void {});

    $command->setConfigLoader($this->makeConfigLoader(
      exclude: ['.gitignore'],
      excludeExtra: ['web/themes/custom/mytheme/node_modules', '.env'],
    ));

    $command->expects($this->once())
      ->method('buildArtifact')
      ->with(
        $this->anything(),
        $this->anything(),
        $this->anything(),
        ['.gitignore', 'web/themes/custom/mytheme/node_modules', '.env'],
      )
      ->willReturn('/tmp/artifact-test');

    $command->deploy();
  }

  /**
   * Verifies that --tag creates a git tag and pushes it after the branch push.
   */
  public function testDeployCreatesTagAfterBranchPush(): void {
    $callLog = [];

    $command = $this->getMockBuilder(DeployCommands::class)
      ->onlyMethods(['io', 'getCurrentBranch', 'getHeadHash', 'buildArtifact', 'runShellCommand'])
      ->getMock();
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->method('getCurrentBranch')->willReturn('main');
    $command->method('getHeadHash')->willReturn('deadbeef12345678deadbeef12345678deadbeef');
    $command->method('buildArtifact')->willReturn('/tmp/artifact-test');
    $command->method('runShellCommand')
      ->willReturnCallback(static function (string $cmd) use (&$callLog): void {
        $callLog[] = $cmd;
      });
    $command->setConfigLoader($this->makeConfigLoader());

    $command->deploy(['dry-run' => FALSE, 'tag' => 'v1.2.3']);

    $pushIdx = NULL;
    $tagIdx = NULL;
    $tagPushIdx = NULL;
    foreach ($callLog as $i => $cmd) {
      if (str_contains($cmd, 'git push') && str_contains($cmd, '--force')) {
        $pushIdx = $i;
      }
      if (str_contains($cmd, 'git tag')) {
        $tagIdx = $i;
      }
      if (str_contains($cmd, 'git push') && str_contains($cmd, 'v1.2.3')) {
        $tagPushIdx = $i;
      }
    }

    $this->assertNotNull($pushIdx, 'Branch push was not called.');
    $this->assertNotNull($tagIdx, 'git tag was not called.');
    $this->assertNotNull($tagPushIdx, 'Tag push was not called.');
    $this->assertStringContainsString('v1.2.3', $callLog[$tagIdx]);
    $this->assertGreaterThan($pushIdx, $tagIdx, 'git tag must run after branch push.');
    $this->assertGreaterThan($tagIdx, $tagPushIdx, 'git push tag must run after git tag.');
  }

  /**
   * Verifies writeManifestFile is called with the correct path and content.
   */
  public function testDeployWritesManifestWithCorrectContent(): void {
    $manifestCalls = [];

    $command = $this->getMockBuilder(DeployCommands::class)
      ->onlyMethods(['io', 'getCurrentBranch', 'getHeadHash', 'buildArtifact', 'runShellCommand', 'writeManifestFile'])
      ->getMock();
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->method('getCurrentBranch')->willReturn('develop');
    $command->method('getHeadHash')->willReturn('abc1234def56789abc1234def56789abc1234def5');
    $command->method('buildArtifact')->willReturn('/tmp/artifact-test');
    $command->method('runShellCommand')->willReturnCallback(static function (): void {});
    $command->method('writeManifestFile')
      ->willReturnCallback(static function (string $path, string $content) use (&$manifestCalls): void {
        $manifestCalls[] = ['path' => $path, 'content' => $content];
      });
    $command->setConfigLoader($this->makeConfigLoader(manifest: TRUE));

    $command->deploy();

    $this->assertCount(1, $manifestCalls, 'writeManifestFile should be called exactly once.');
    $this->assertSame('/tmp/artifact-test/SUDS_BUILD.txt', $manifestCalls[0]['path']);
    $this->assertStringContainsString('branch: develop', $manifestCalls[0]['content']);
    $this->assertStringContainsString('hash: abc1234def56789abc1234def56789abc1234def5', $manifestCalls[0]['content']);
    $this->assertStringContainsString('short_hash: abc1234d', $manifestCalls[0]['content']);
    $this->assertStringContainsString('built_at:', $manifestCalls[0]['content']);
  }

  /**
   * Verifies a custom manifest_file name is respected.
   */
  public function testDeployUsesCustomManifestFilename(): void {
    $manifestCalls = [];

    $command = $this->getMockBuilder(DeployCommands::class)
      ->onlyMethods(['io', 'getCurrentBranch', 'getHeadHash', 'buildArtifact', 'runShellCommand', 'writeManifestFile'])
      ->getMock();
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->method('getCurrentBranch')->willReturn('main');
    $command->method('getHeadHash')->willReturn('deadbeef12345678deadbeef12345678deadbeef');
    $command->method('buildArtifact')->willReturn('/tmp/artifact-test');
    $command->method('runShellCommand')->willReturnCallback(static function (): void {});
    $command->method('writeManifestFile')
      ->willReturnCallback(static function (string $path, string $content) use (&$manifestCalls): void {
        $manifestCalls[] = ['path' => $path, 'content' => $content];
      });
    $command->setConfigLoader($this->makeConfigLoader(manifest: TRUE, manifestFile: 'BUILD_INFO.txt'));

    $command->deploy();

    $this->assertCount(1, $manifestCalls);
    $this->assertSame('/tmp/artifact-test/BUILD_INFO.txt', $manifestCalls[0]['path']);
  }

  /**
   * Verifies writeManifestFile is not called when manifest is disabled.
   */
  public function testDeploySkipsManifestWhenDisabled(): void {
    $command = $this->getMockBuilder(DeployCommands::class)
      ->onlyMethods(['io', 'getCurrentBranch', 'getHeadHash', 'buildArtifact', 'runShellCommand', 'writeManifestFile'])
      ->getMock();
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->method('getCurrentBranch')->willReturn('main');
    $command->method('getHeadHash')->willReturn('deadbeef12345678deadbeef12345678deadbeef');
    $command->method('buildArtifact')->willReturn('/tmp/artifact-test');
    $command->method('runShellCommand')->willReturnCallback(static function (): void {});
    $command->expects($this->never())->method('writeManifestFile');
    $command->setConfigLoader($this->makeConfigLoader(manifest: FALSE));

    $command->deploy();
  }

  /**
   * Verifies writeManifestFile is called before git add -A in the artifact.
   *
   * The manifest must be present in the artifact directory before git add
   * stages files, otherwise it would be missing from the committed artifact.
   */
  public function testDeployWritesManifestBeforeGitAdd(): void {
    $callLog = [];

    $command = $this->getMockBuilder(DeployCommands::class)
      ->onlyMethods(['io', 'getCurrentBranch', 'getHeadHash', 'buildArtifact', 'runShellCommand', 'writeManifestFile'])
      ->getMock();
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->method('getCurrentBranch')->willReturn('main');
    $command->method('getHeadHash')->willReturn('deadbeef12345678deadbeef12345678deadbeef');
    $command->method('buildArtifact')->willReturn('/tmp/artifact-test');
    $command->method('runShellCommand')
      ->willReturnCallback(static function (string $cmd) use (&$callLog): void {
        $callLog[] = ['type' => 'shell', 'value' => $cmd];
      });
    $command->method('writeManifestFile')
      ->willReturnCallback(static function () use (&$callLog): void {
        $callLog[] = ['type' => 'manifest', 'value' => '__manifest__'];
      });
    $command->setConfigLoader($this->makeConfigLoader(manifest: TRUE));

    $command->deploy();

    $manifestIdx = NULL;
    $gitAddIdx = NULL;
    foreach ($callLog as $i => $entry) {
      if ($entry['type'] === 'manifest') {
        $manifestIdx = $i;
      }
      if ($entry['type'] === 'shell' && $entry['value'] === 'git add -A .') {
        $gitAddIdx = $i;
      }
    }

    $this->assertNotNull($manifestIdx, 'writeManifestFile was not called.');
    $this->assertNotNull($gitAddIdx, 'git add was not called.');
    $this->assertLessThan($gitAddIdx, $manifestIdx, 'Manifest must be written before git add.');
  }

  /**
   * Verifies dry-run skips writeManifestFile but still prints the note.
   *
   * In dry-run mode the manifest note should appear in the output (so the
   * operator can see what would happen) but no actual file should be written.
   */
  public function testDeployDryRunSkipsManifestWrite(): void {
    $notes = [];
    $io = $this->createMock(DrushStyle::class);
    $io->method('note')->willReturnCallback(static function (string $msg) use (&$notes): void {
      $notes[] = $msg;
    });
    $io->method('title')->willReturnCallback(static function (): void {});
    $io->method('success')->willReturnCallback(static function (): void {});

    // runShellCommand is NOT mocked so the real dry-run override fires and
    // prints [dry-run] notes without executing any shell commands.
    $command = $this->getMockBuilder(DeployCommands::class)
      ->onlyMethods(['io', 'getCurrentBranch', 'getHeadHash', 'buildArtifact', 'writeManifestFile'])
      ->getMock();
    $command->method('io')->willReturn($io);
    $command->method('getCurrentBranch')->willReturn('main');
    $command->method('getHeadHash')->willReturn('deadbeef12345678deadbeef12345678deadbeef');
    $command->method('buildArtifact')->willReturn('/tmp/artifact-test');
    $command->expects($this->never())->method('writeManifestFile');
    $command->setConfigLoader($this->makeConfigLoader(manifest: TRUE));

    $command->deploy(['dry-run' => TRUE, 'tag' => NULL]);

    $manifestNotes = array_filter($notes, static fn(string $n) => str_contains($n, 'build manifest'));
    $this->assertNotEmpty($manifestNotes, 'Expected a manifest note in dry-run output.');
  }

  /**
   * Builds a mock ConfigLoaderInterface with deploy configuration.
   *
   * Manifest defaults to FALSE here (not the suds.defaults.yml default of
   * TRUE) so that existing tests asserting exact runShellCommand call counts
   * are not affected by the manifest feature.
   *
   * @param string $repoUrl
   *   The deploy.repo.url value.
   * @param string $repoBranch
   *   The deploy.repo.branch value.
   * @param string $commitMessage
   *   The deploy.commit_message value.
   * @param string $gitName
   *   The deploy.git.name value.
   * @param string $gitEmail
   *   The deploy.git.email value.
   * @param list<string> $buildSteps
   *   The deploy.build_steps value.
   * @param list<string> $exclude
   *   The deploy.exclude value.
   * @param list<string> $excludeExtra
   *   The deploy.exclude_extra value.
   * @param list<string> $preDeployHooks
   *   The deploy.hooks.pre_deploy value.
   * @param list<string> $postDeployHooks
   *   The deploy.hooks.post_deploy value.
   * @param bool $manifest
   *   The deploy.manifest value.
   * @param string $manifestFile
   *   The deploy.manifest_file value.
   *
   * @return \Bounteous\Suds\Config\ConfigLoaderInterface
   *   A mock config loader.
   */
  private function makeConfigLoader(
    string $repoUrl = 'git@github.com:example/artifact.git',
    string $repoBranch = '$SUDS_BRANCH-build',
    string $commitMessage = 'Deploy $SUDS_BRANCH [$SUDS_SHORT_HASH]',
    string $gitName = 'SUDS Deploy',
    string $gitEmail = 'suds@localhost',
    array $buildSteps = [],
    array $exclude = [],
    array $excludeExtra = [],
    array $preDeployHooks = [],
    array $postDeployHooks = [],
    bool $manifest = FALSE,
    string $manifestFile = 'SUDS_BUILD.txt',
  ): ConfigLoaderInterface {
    $loader = $this->createMock(ConfigLoaderInterface::class);
    $loader->method('getProjectRoot')->willReturn('/tmp/project');
    $loader->method('load')->willReturn([
      'deploy' => [
        'repo' => [
          'url'    => $repoUrl,
          'branch' => $repoBranch,
        ],
        'commit_message'  => $commitMessage,
        'git' => [
          'name'  => $gitName,
          'email' => $gitEmail,
        ],
        'build_steps'   => $buildSteps,
        'exclude'       => $exclude,
        'exclude_extra' => $excludeExtra,
        'manifest'      => $manifest,
        'manifest_file'  => $manifestFile,
        'hooks' => [
          'pre_deploy'  => $preDeployHooks,
          'post_deploy' => $postDeployHooks,
        ],
      ],
    ]);
    return $loader;
  }

}
