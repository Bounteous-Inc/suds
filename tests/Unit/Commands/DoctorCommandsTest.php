<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Unit\Commands;

use Bounteous\Suds\Config\ConfigLoaderInterface;
use Bounteous\Suds\Drush\Commands\DoctorCommands;
use Drush\Style\DrushStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DoctorCommands.
 */
#[CoversClass(DoctorCommands::class)]
class DoctorCommandsTest extends TestCase {

  /**
   * Verifies doctor() has the correct @command and @aliases annotations.
   */
  public function testDoctorAnnotations(): void {
    $doc = (new \ReflectionMethod(DoctorCommands::class, 'doctor'))->getDocComment();
    $this->assertIsString($doc);
    $this->assertStringContainsString(' suds:doctor', $doc);
    $this->assertStringContainsString(' su-doctor', $doc);
    $this->assertStringContainsString('@bootstrap none', $doc);
  }

  /**
   * Verifies doctor() produces no errors or warnings when all checks pass.
   */
  public function testDoctorPassesWhenAllChecksPass(): void {
    $projectRoot = $this->createTempProjectRoot();
    mkdir($projectRoot . '/web/core', 0755, TRUE);
    mkdir($projectRoot . '/.git/hooks', 0755, TRUE);
    file_put_contents($projectRoot . '/grumphp.yml', '');
    file_put_contents($projectRoot . '/phpcs.xml.dist', '');
    file_put_contents($projectRoot . '/phpstan.neon', '');
    file_put_contents($projectRoot . '/.git/hooks/pre-commit', '#!/usr/bin/env php');

    $loader = $this->makeLoader(
      projectRoot: $projectRoot,
      hasProjectConfig: TRUE,
      config: $this->makeConfig(
        projectName: 'My Project',
        drupalRoot: 'web',
        syncDefaultSource: '@prod',
        deployRepoUrl: 'git@example.com:example.git',
      ),
    );

    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->never())->method('error');
    $io->expects($this->never())->method('warning');

    $this->buildCommand($loader, [
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ], $io, ['@prod'], gitDir: $projectRoot . '/.git')->doctor();

    $this->removeDirectory($projectRoot);
  }

  /**
   * Verifies doctor() throws when composer is not found.
   */
  public function testDoctorThrowsWhenComposerMissing(): void {
    $loader = $this->makeLoader(projectRoot: sys_get_temp_dir(), hasProjectConfig: FALSE);

    $this->expectException(\RuntimeException::class);
    $this->buildCommand($loader, [
      'composer' => NULL,
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ])->doctor();
  }

  /**
   * Verifies doctor() throws when drupal.root does not exist on disk.
   */
  public function testDoctorThrowsWhenDrupalRootMissing(): void {
    $projectRoot = $this->createTempProjectRoot();
    // Intentionally do NOT create the web/ directory.
    $loader = $this->makeLoader(
      projectRoot: $projectRoot,
      hasProjectConfig: TRUE,
      config: $this->makeConfig(drupalRoot: 'web', syncDefaultSource: '@prod'),
    );

    try {
      $this->expectException(\RuntimeException::class);
      $this->buildCommand($loader, [
        'composer' => '/usr/bin/composer',
        'rsync'    => '/usr/bin/rsync',
        'git'      => '/usr/bin/git',
      ])->doctor();
    }
    finally {
      $this->removeDirectory($projectRoot);
    }
  }

  /**
   * Verifies doctor() does not throw for WARNs; warning() called at least once.
   *
   * Rsync and git are intentionally absent to trigger WARN-level results while
   * keeping all FAIL checks clean.
   */
  public function testDoctorDoesNotThrowForWarnings(): void {
    $projectRoot = $this->createTempProjectRoot();
    // web/core must exist so checkDrupalCoreDir() does not produce a FAIL.
    mkdir($projectRoot . '/web/core', 0755, TRUE);

    $loader = $this->makeLoader(
      projectRoot: $projectRoot,
      hasProjectConfig: TRUE,
      config: $this->makeConfig(drupalRoot: 'web', syncDefaultSource: '@prod'),
    );

    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->never())->method('error');
    $io->expects($this->atLeastOnce())->method('warning');

    $this->buildCommand($loader, [
      'composer' => '/usr/bin/composer',
      'rsync'    => NULL,
      'git'      => NULL,
    ], $io)->doctor();

    $this->removeDirectory($projectRoot);
  }

  /**
   * Verifies doctor() does not throw when suds.yml is absent (WARN only).
   *
   * A missing suds.yml triggers a warning but is not a failure.
   */
  public function testDoctorDoesNotThrowWhenSudsYmlMissing(): void {
    $loader = $this->makeLoader(projectRoot: sys_get_temp_dir(), hasProjectConfig: FALSE);

    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->never())->method('error');
    $io->expects($this->atLeastOnce())->method('warning');

    $this->buildCommand($loader, [
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ], $io)->doctor();
  }

  /**
   * Verifies no FAIL output when PHP meets the minimum version requirement.
   */
  public function testDoctorPassesPhpVersionCheck(): void {
    $this->assertGreaterThanOrEqual(80300, PHP_VERSION_ID, 'Tests require PHP 8.3+');

    $loader = $this->makeLoader(projectRoot: sys_get_temp_dir(), hasProjectConfig: FALSE);

    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->never())->method('error');

    $this->buildCommand($loader, [
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ], $io)->doctor();
  }

  /**
   * Verifies doctor() produces a FAIL when PHP version is below the minimum.
   *
   * GetPhpVersionId() is mocked to simulate an older runtime without requiring
   * a real PHP downgrade.
   */
  public function testDoctorFailsWhenPhpVersionTooLow(): void {
    $loader = $this->makeLoader(projectRoot: sys_get_temp_dir(), hasProjectConfig: FALSE);

    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->atLeastOnce())->method('error');

    $command = $this->getMockBuilder(DoctorCommands::class)
      ->onlyMethods(['io', 'findExecutable', 'getPhpVersionId'])
      ->getMock();
    $command->method('io')->willReturn($io);
    $command->method('findExecutable')->willReturn('/usr/bin/fake');
    $command->method('getPhpVersionId')->willReturn(80200);
    $command->setConfigLoader($loader);

    $this->expectException(\RuntimeException::class);
    $command->doctor();
  }

  /**
   * Verifies checkDrupalCoreDir() produces a FAIL when core/ is missing.
   *
   * The root directory exists but contains no core/ subdirectory, indicating
   * the directory is not a Drupal installation.
   */
  public function testDoctorFailsWhenDrupalCoreDirMissing(): void {
    $projectRoot = $this->createTempProjectRoot();
    // Create root but NOT core/ inside it.
    mkdir($projectRoot . '/web', 0755, TRUE);

    $loader = $this->makeLoader(
      projectRoot: $projectRoot,
      hasProjectConfig: TRUE,
      config: $this->makeConfig(drupalRoot: 'web', syncDefaultSource: '@prod'),
    );

    try {
      $this->expectException(\RuntimeException::class);
      $this->buildCommand($loader, [
        'composer' => '/usr/bin/composer',
        'rsync'    => '/usr/bin/rsync',
        'git'      => NULL,
      ])->doctor();
    }
    finally {
      $this->removeDirectory($projectRoot);
    }
  }

  /**
   * Verifies checkDeployRepoUrl() warns when git is found but URL is not set.
   */
  public function testDoctorWarnsWhenDeployRepoUrlEmptyAndGitAvailable(): void {
    $projectRoot = $this->createTempProjectRoot();
    mkdir($projectRoot . '/web/core', 0755, TRUE);

    $loader = $this->makeLoader(
      projectRoot: $projectRoot,
      hasProjectConfig: TRUE,
      config: $this->makeConfig(drupalRoot: 'web', deployRepoUrl: '', syncDefaultSource: '@prod'),
    );

    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->never())->method('error');
    $io->expects($this->atLeastOnce())->method('warning');

    $this->buildCommand($loader, [
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ], $io)->doctor();

    $this->removeDirectory($projectRoot);
  }

  /**
   * Verifies checkDeployRepoUrl() does not add a FAIL when git is absent.
   *
   * When git is not found, the deploy URL check is skipped entirely (PASS),
   * so no RuntimeException is thrown even when deploy.repo.url is empty.
   * The git binary check in checkBinaries() still produces a WARN, which is
   * non-fatal.
   */
  public function testDoctorNoFailDeployRepoUrlWhenGitMissing(): void {
    $projectRoot = $this->createTempProjectRoot();
    mkdir($projectRoot . '/web/core', 0755, TRUE);

    $loader = $this->makeLoader(
      projectRoot: $projectRoot,
      hasProjectConfig: TRUE,
      config: $this->makeConfig(drupalRoot: 'web', deployRepoUrl: '', syncDefaultSource: '@prod'),
    );

    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->never())->method('error');

    // Should not throw — no FAIL-level check fires.
    $this->buildCommand($loader, [
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => NULL,
    ], $io)->doctor();

    $this->removeDirectory($projectRoot);
  }

  /**
   * Verifies checkSyncDefaultSource() warns when all sync sources are null.
   */
  public function testDoctorWarnsWhenAllSyncSourcesNull(): void {
    $projectRoot = $this->createTempProjectRoot();
    mkdir($projectRoot . '/web/core', 0755, TRUE);

    $loader = $this->makeLoader(
      projectRoot: $projectRoot,
      hasProjectConfig: TRUE,
      config: $this->makeConfig(drupalRoot: 'web', syncDefaultSource: NULL),
    );

    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->never())->method('error');
    $io->expects($this->atLeastOnce())->method('warning');

    $this->buildCommand($loader, [
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => NULL,
    ], $io)->doctor();

    $this->removeDirectory($projectRoot);
  }

  /**
   * Verifies checkSyncDefaultSource() does not warn when a source is set.
   *
   * All binaries and directories are present, deploy.repo.url is set,
   * sync.default_source is configured, and quality files are present —
   * no warnings or errors expected.
   */
  public function testDoctorNoWarnSyncDefaultSourceWhenOneSet(): void {
    $projectRoot = $this->createTempProjectRoot();
    mkdir($projectRoot . '/web/core', 0755, TRUE);
    mkdir($projectRoot . '/.git/hooks', 0755, TRUE);
    file_put_contents($projectRoot . '/grumphp.yml', '');
    file_put_contents($projectRoot . '/phpcs.xml.dist', '');
    file_put_contents($projectRoot . '/phpstan.neon', '');
    file_put_contents($projectRoot . '/.git/hooks/pre-commit', '#!/usr/bin/env php');

    $loader = $this->makeLoader(
      projectRoot: $projectRoot,
      hasProjectConfig: TRUE,
      config: $this->makeConfig(
        projectName: 'My Project',
        drupalRoot: 'web',
        deployRepoUrl: 'git@example.com:example.git',
        syncDefaultSource: '@prod',
      ),
    );

    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->never())->method('error');
    $io->expects($this->never())->method('warning');

    $this->buildCommand($loader, [
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ], $io, ['@prod'], gitDir: $projectRoot . '/.git')->doctor();

    $this->removeDirectory($projectRoot);
  }

  /**
   * Verifies checkGitRepository() warns when not in a git repo.
   *
   * Deploy is configured, git is found, but the project is not inside a
   * git repository. Expects a WARN but no FAIL and no exception.
   */
  public function testDoctorWarnsWhenNotInGitRepository(): void {
    $projectRoot = $this->createTempProjectRoot();
    mkdir($projectRoot . '/web/core', 0755, TRUE);

    $loader = $this->makeLoader(
      projectRoot: $projectRoot,
      hasProjectConfig: TRUE,
      config: $this->makeConfig(
        projectName: 'My Project',
        drupalRoot: 'web',
        deployRepoUrl: 'git@example.com:example.git',
        syncDefaultSource: '@prod',
      ),
    );

    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->never())->method('error');
    $io->expects($this->atLeastOnce())->method('warning');

    $this->buildCommand($loader, [
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ], $io, ['@prod'], isGitRepository: FALSE)->doctor();

    $this->removeDirectory($projectRoot);
  }

  /**
   * Verifies checkUnknownConfigKeys() emits a warning per unknown key.
   */
  public function testDoctorWarnsOnUnknownConfigKey(): void {
    $projectRoot = $this->createTempProjectRoot();
    mkdir($projectRoot . '/web/core', 0755, TRUE);

    $loader = $this->makeLoader(
      projectRoot: $projectRoot,
      hasProjectConfig: TRUE,
      config: $this->makeConfig(
        projectName: 'My Project',
        drupalRoot: 'web',
        syncDefaultSource: '@prod',
        deployRepoUrl: 'git@example.com:example.git',
      ),
      unknownKeys: [
        ['file' => 'suds.yml', 'key' => 'drupal.rot'],
      ],
    );

    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->never())->method('error');
    $io->expects($this->atLeastOnce())->method('warning');

    $this->buildCommand($loader, [
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ], $io)->doctor();

    $this->removeDirectory($projectRoot);
  }

  /**
   * Verifies checkConfigTypes() emits a warning per type error.
   */
  public function testDoctorWarnsOnConfigTypeError(): void {
    $projectRoot = $this->createTempProjectRoot();
    mkdir($projectRoot . '/web/core', 0755, TRUE);

    $loader = $this->makeLoader(
      projectRoot: $projectRoot,
      hasProjectConfig: TRUE,
      config: $this->makeConfig(
        projectName: 'My Project',
        drupalRoot: 'web',
        syncDefaultSource: '@prod',
        deployRepoUrl: 'git@example.com:example.git',
      ),
      typeErrors: [
        ['file' => 'suds.yml', 'key' => 'sync.db.sanitize', 'expected' => 'bool', 'actual' => 'string'],
      ],
    );

    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->never())->method('error');
    $io->expects($this->atLeastOnce())->method('warning');

    $this->buildCommand($loader, [
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ], $io, ['@prod'])->doctor();

    $this->removeDirectory($projectRoot);
  }

  /**
   * Verifies checkQualityTooling() warns when grumphp.yml is absent.
   */
  public function testDoctorWarnsWhenQualityFileMissing(): void {
    $projectRoot = $this->createTempProjectRoot();
    mkdir($projectRoot . '/web/core', 0755, TRUE);
    // Intentionally omit grumphp.yml, phpcs.xml.dist, and phpstan.neon.
    $loader = $this->makeLoader(
      projectRoot: $projectRoot,
      hasProjectConfig: TRUE,
      config: $this->makeConfig(
        projectName: 'My Project',
        drupalRoot: 'web',
        syncDefaultSource: '@prod',
        deployRepoUrl: 'git@example.com:example.git',
      ),
    );

    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->never())->method('error');
    $io->expects($this->atLeastOnce())->method('warning');

    $this->buildCommand($loader, [
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ], $io, ['@prod'])->doctor();

    $this->removeDirectory($projectRoot);
  }

  /**
   * Verifies checkQualityTooling() warns when the GrumPHP hook is missing.
   *
   * Grumphp.yml and other quality files are present; the pre-commit hook is
   * absent. Expects a WARN but no FAIL and no exception.
   */
  public function testDoctorWarnsWhenGrumphpHookNotInstalled(): void {
    $projectRoot = $this->createTempProjectRoot();
    mkdir($projectRoot . '/web/core', 0755, TRUE);
    mkdir($projectRoot . '/.git/hooks', 0755, TRUE);
    file_put_contents($projectRoot . '/grumphp.yml', '');
    file_put_contents($projectRoot . '/phpcs.xml.dist', '');
    file_put_contents($projectRoot . '/phpstan.neon', '');
    // Intentionally omit .git/hooks/pre-commit.
    $loader = $this->makeLoader(
      projectRoot: $projectRoot,
      hasProjectConfig: TRUE,
      config: $this->makeConfig(
        projectName: 'My Project',
        drupalRoot: 'web',
        syncDefaultSource: '@prod',
        deployRepoUrl: 'git@example.com:example.git',
      ),
    );

    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->never())->method('error');
    $io->expects($this->atLeastOnce())->method('warning');

    $this->buildCommand($loader, [
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ], $io, ['@prod'], gitDir: $projectRoot . '/.git')->doctor();

    $this->removeDirectory($projectRoot);
  }

  /**
   * Verifies checkQualityTooling() skips the hook check when not in a git repo.
   *
   * Quality files are present but findGitDir() returns NULL. No hook WARN
   * should be emitted, and the command should still exit cleanly.
   */
  public function testDoctorSkipsHookCheckWhenNoGitDir(): void {
    $projectRoot = $this->createTempProjectRoot();
    mkdir($projectRoot . '/web/core', 0755, TRUE);
    file_put_contents($projectRoot . '/grumphp.yml', '');
    file_put_contents($projectRoot . '/phpcs.xml.dist', '');
    file_put_contents($projectRoot . '/phpstan.neon', '');

    $loader = $this->makeLoader(
      projectRoot: $projectRoot,
      hasProjectConfig: TRUE,
      config: $this->makeConfig(
        projectName: 'My Project',
        drupalRoot: 'web',
        syncDefaultSource: '@prod',
        deployRepoUrl: 'git@example.com:example.git',
      ),
    );

    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->never())->method('error');
    // No warning expected: quality files are present, hook check skipped.
    $io->expects($this->never())->method('warning');

    $this->buildCommand($loader, [
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ], $io, ['@prod'], gitDir: NULL)->doctor();

    $this->removeDirectory($projectRoot);
  }

  /**
   * Verifies checkSyncAliases() warns when the configured alias is not defined.
   */
  public function testDoctorWarnsWhenSyncAliasNotDefined(): void {
    $projectRoot = $this->createTempProjectRoot();
    mkdir($projectRoot . '/web/core', 0755, TRUE);

    $loader = $this->makeLoader(
      projectRoot: $projectRoot,
      hasProjectConfig: TRUE,
      config: $this->makeConfig(
        projectName: 'My Project',
        drupalRoot: 'web',
        syncDefaultSource: '@prod',
        deployRepoUrl: 'git@example.com:example.git',
      ),
    );

    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->never())->method('error');
    $io->expects($this->atLeastOnce())->method('warning');

    // knownAliases is empty, so @prod triggers a WARN.
    $this->buildCommand($loader, [
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ], $io, [])->doctor();

    $this->removeDirectory($projectRoot);
  }

  /**
   * Builds a testable DoctorCommands instance with controlled binary detection.
   *
   * @param \Bounteous\Suds\Config\ConfigLoaderInterface $loader
   *   The config loader mock.
   * @param array<string, string|null> $executables
   *   Map of binary name to path (null = not found).
   * @param \Drush\Style\DrushStyle|null $io
   *   Optional pre-configured io mock. A permissive stub is used if omitted.
   * @param list<string> $knownAliases
   *   Aliases drushAliasExists() returns TRUE for. Defaults to none.
   * @param bool $isGitRepository
   *   Return value for isGitRepository(). Defaults to TRUE.
   * @param string|null $gitDir
   *   Return value for findGitDir(). NULL means no git directory found.
   *
   * @return \Bounteous\Suds\Drush\Commands\DoctorCommands
   *   The configured command mock.
   */
  private function buildCommand(
    ConfigLoaderInterface $loader,
    array $executables,
    ?DrushStyle $io = NULL,
    array $knownAliases = [],
    bool $isGitRepository = TRUE,
    ?string $gitDir = NULL,
  ): DoctorCommands {
    $command = $this->getMockBuilder(DoctorCommands::class)
      ->onlyMethods(['io', 'findExecutable', 'drushAliasExists', 'isGitRepository', 'findGitDir'])
      ->getMock();

    $command->method('io')->willReturn($io ?? $this->createMock(DrushStyle::class));
    $command->method('findExecutable')
      ->willReturnCallback(static fn (string $bin) => $executables[$bin] ?? NULL);
    $command->method('drushAliasExists')
      ->willReturnCallback(static fn (string $alias) => in_array($alias, $knownAliases, TRUE));
    $command->method('isGitRepository')->willReturn($isGitRepository);
    $command->method('findGitDir')->willReturn($gitDir);
    $command->setConfigLoader($loader);

    return $command;
  }

  /**
   * Creates a mock ConfigLoaderInterface.
   *
   * @param string $projectRoot
   *   The project root path.
   * @param bool $hasProjectConfig
   *   Whether a suds.yml exists.
   * @param array<string, mixed>|null $config
   *   The resolved config array. Defaults to minimal config.
   * @param array<int, array{file: string, key: string}> $unknownKeys
   *   Unknown key entries returned by getUnknownKeys(). Defaults to empty.
   * @param array<int, array{file: string, key: string, expected: string, actual: string}> $typeErrors
   *   Type error entries returned by getTypeErrors(). Defaults to empty.
   *
   * @return \Bounteous\Suds\Config\ConfigLoaderInterface
   *   The mock loader.
   */
  private function makeLoader(
    string $projectRoot,
    bool $hasProjectConfig,
    ?array $config = NULL,
    array $unknownKeys = [],
    array $typeErrors = [],
  ): ConfigLoaderInterface {
    $loader = $this->createMock(ConfigLoaderInterface::class);
    $loader->method('getProjectRoot')->willReturn($projectRoot);
    $loader->method('hasProjectConfig')->willReturn($hasProjectConfig);
    $loader->method('load')->willReturn($config ?? $this->makeConfig());
    $loader->method('getUnknownKeys')->willReturn($unknownKeys);
    $loader->method('getTypeErrors')->willReturn($typeErrors);
    return $loader;
  }

  /**
   * Returns a minimal resolved config array for doctor() tests.
   *
   * @param string|null $projectName
   *   Value for project.name (null = unset).
   * @param string $drupalRoot
   *   Value for drupal.root.
   * @param string $deployRepoUrl
   *   Value for deploy.repo.url.
   * @param string|null $syncDefaultSource
   *   Value for sync.default_source (null = unset).
   *
   * @return array<string, mixed>
   *   The config array.
   */
  private function makeConfig(
    ?string $projectName = NULL,
    string $drupalRoot = 'web',
    string $deployRepoUrl = '',
    ?string $syncDefaultSource = NULL,
  ): array {
    return [
      'project' => ['name' => $projectName],
      'drupal'  => ['root' => $drupalRoot],
      'deploy'  => ['repo' => ['url' => $deployRepoUrl]],
      'sync'    => [
        'default_source' => $syncDefaultSource,
        'db'             => ['default_source' => NULL],
        'files'          => ['default_source' => NULL],
      ],
    ];
  }

  /**
   * Creates a temporary directory for tests that need a real filesystem path.
   *
   * @return string
   *   Absolute path to the new directory.
   */
  private function createTempProjectRoot(): string {
    $dir = sys_get_temp_dir() . '/suds_doctor_' . uniqid();
    mkdir($dir, 0755, TRUE);
    return $dir;
  }

  /**
   * Recursively removes a directory.
   *
   * @param string $dir
   *   The directory to remove.
   */
  private function removeDirectory(string $dir): void {
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

}
