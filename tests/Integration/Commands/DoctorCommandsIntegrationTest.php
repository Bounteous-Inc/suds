<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Integration\Commands;

use Bounteous\Suds\Config\ConfigLoader;
use Bounteous\Suds\Drush\Commands\DoctorCommands;
use Bounteous\Suds\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Yaml\Yaml;

/**
 * Integration tests for DoctorCommands.
 *
 * Commands are invoked through the Symfony Application API via
 * AnnotatedCommandFactory. A ConfigLoader is injected to control config state.
 * A testable subclass is used so that binary detection does not depend on the
 * real system PATH.
 */
#[CoversClass(DoctorCommands::class)]
class DoctorCommandsIntegrationTest extends IntegrationTestCase {

  /**
   * The application tester.
   *
   * @var \Symfony\Component\Console\Tester\ApplicationTester
   */
  private ApplicationTester $tester;

  /**
   * The command instance under test.
   *
   * @var \Bounteous\Suds\Tests\Integration\Commands\TestableDoctorCommands
   */
  private TestableDoctorCommands $commandInstance;

  /**
   * Temporary project directory.
   *
   * @var string
   */
  private string $projectRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->projectRoot = $this->createTempDir('suds_doctor_integration_');
    $this->commandInstance = new TestableDoctorCommands();
    $this->tester = $this->buildTester($this->commandInstance);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeDirectory($this->projectRoot);
  }

  /**
   * Tests that suds:doctor is registered and exits cleanly when all pass.
   */
  public function testDoctorExitsCleanlyWhenAllChecksPass(): void {
    mkdir($this->projectRoot . '/web/core', 0755, TRUE);
    mkdir($this->projectRoot . '/.git/hooks', 0755, TRUE);
    file_put_contents($this->projectRoot . '/grumphp.yml', '');
    file_put_contents($this->projectRoot . '/phpcs.xml.dist', '');
    file_put_contents($this->projectRoot . '/phpstan.neon', '');
    file_put_contents($this->projectRoot . '/.git/hooks/pre-commit', '#!/usr/bin/env php');
    file_put_contents(
      $this->projectRoot . '/suds.yml',
      Yaml::dump([
        'project' => ['name' => 'Integration Test'],
        'drupal'  => ['root' => 'web'],
        'deploy'  => ['repo' => ['url' => 'git@example.com:example.git']],
        'sync'    => ['default_source' => '@prod'],
      ], 4, 2),
    );

    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    $this->commandInstance->setExecutables([
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ]);
    $this->commandInstance->setKnownAliases(['@prod']);
    $this->commandInstance->setGitDir($this->projectRoot . '/.git');

    $exitCode = $this->tester->run(['command' => 'suds:doctor']);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that suds:doctor is reachable via its su-doctor alias.
   */
  public function testDoctorAliasIsRegistered(): void {
    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    $this->commandInstance->setExecutables([
      'composer' => '/usr/bin/composer',
      'rsync'    => NULL,
      'git'      => NULL,
    ]);

    $exitCode = $this->tester->run(['command' => 'su-doctor']);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that suds:doctor exits non-zero when composer is not found.
   */
  public function testDoctorFailsWhenComposerMissing(): void {
    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    $this->commandInstance->setExecutables(['composer' => NULL, 'rsync' => NULL, 'git' => NULL]);

    $exitCode = $this->tester->run(['command' => 'suds:doctor']);
    $this->assertNotSame(0, $exitCode);
  }

  /**
   * Tests that suds:doctor exits non-zero when drupal.root does not exist.
   */
  public function testDoctorFailsWhenDrupalRootMissing(): void {
    // Write suds.yml pointing to a non-existent web/ dir.
    file_put_contents(
      $this->projectRoot . '/suds.yml',
      Yaml::dump(['drupal' => ['root' => 'web']], 4, 2),
    );

    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    $this->commandInstance->setExecutables([
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ]);

    $exitCode = $this->tester->run(['command' => 'suds:doctor']);
    $this->assertNotSame(0, $exitCode);
  }

  /**
   * Tests that suds:doctor exits 0 when only warnings are present.
   */
  public function testDoctorExitsCleanlyWithOnlyWarnings(): void {
    // Write suds.yml with no project.name — triggers a WARN.
    // web/core/ exists so checkDrupalCoreDir() passes.
    // config/sync absent → WARN; rsync/git absent → WARNs. None are FAILs.
    file_put_contents(
      $this->projectRoot . '/suds.yml',
      Yaml::dump(['drupal' => ['root' => 'web']], 4, 2),
    );
    mkdir($this->projectRoot . '/web/core', 0755, TRUE);

    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    // Rsync and git absent = WARNs, not FAILs.
    $this->commandInstance->setExecutables([
      'composer' => '/usr/bin/composer',
      'rsync'    => NULL,
      'git'      => NULL,
    ]);

    $exitCode = $this->tester->run(['command' => 'suds:doctor']);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that a missing project.name produces a WARN result but exits 0.
   *
   * Isolates the checkProjectName() branch: all other checks pass so that
   * the project.name warning is the only non-OK result.
   */
  public function testDoctorWarnsWhenProjectNameMissing(): void {
    mkdir($this->projectRoot . '/web/core', 0755, TRUE);
    file_put_contents(
      $this->projectRoot . '/suds.yml',
      Yaml::dump([
        // project.name intentionally omitted.
        'drupal' => ['root' => 'web'],
        'deploy' => ['repo' => ['url' => 'git@example.com:example.git']],
        'sync'   => ['default_source' => '@prod'],
      ], 4, 2),
    );

    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    $this->commandInstance->setExecutables([
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ]);

    $exitCode = $this->tester->run(['command' => 'suds:doctor']);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that an unconfigured sync.default_source produces a WARN but exits 0.
   *
   * Isolates the checkSyncDefaultSource() branch: all other checks pass so
   * that the sync source warning is the only non-OK result.
   */
  public function testDoctorWarnsWhenSyncDefaultSourceNotSet(): void {
    mkdir($this->projectRoot . '/web/core', 0755, TRUE);
    file_put_contents(
      $this->projectRoot . '/suds.yml',
      Yaml::dump([
        'project' => ['name' => 'Integration Test'],
        'drupal'  => ['root' => 'web'],
        'deploy'  => ['repo' => ['url' => 'git@example.com:example.git']],
        // sync.default_source intentionally omitted (defaults to null).
      ], 4, 2),
    );

    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    $this->commandInstance->setExecutables([
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ]);

    $exitCode = $this->tester->run(['command' => 'suds:doctor']);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that a hint is printed below the status line for a WARN result.
   *
   * Triggers the sync.default_source warning and asserts that the remediation
   * hint appears in the output, confirming renderResults() emits hint lines.
   */
  public function testDoctorOutputsHintForWarnResult(): void {
    mkdir($this->projectRoot . '/web/core', 0755, TRUE);
    file_put_contents(
      $this->projectRoot . '/suds.yml',
      Yaml::dump([
        'project' => ['name' => 'Integration Test'],
        'drupal'  => ['root' => 'web'],
        'deploy'  => ['repo' => ['url' => 'git@example.com:example.git']],
        // sync.default_source intentionally omitted to trigger WARN + hint.
      ], 4, 2),
    );

    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    $this->commandInstance->setExecutables([
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ]);

    $this->tester->run(['command' => 'suds:doctor']);
    $this->assertStringContainsString(
      'sync.default_source',
      $this->tester->getDisplay(),
    );
    $this->assertStringContainsString(
      'Set `sync.default_source',
      $this->tester->getDisplay(),
    );
  }

  /**
   * Tests that suds:doctor warns when a configured alias is not defined.
   */
  public function testDoctorWarnsWhenSyncAliasNotDefined(): void {
    mkdir($this->projectRoot . '/web/core', 0755, TRUE);
    file_put_contents(
      $this->projectRoot . '/suds.yml',
      Yaml::dump([
        'project' => ['name' => 'Integration Test'],
        'drupal'  => ['root' => 'web'],
        'deploy'  => ['repo' => ['url' => 'git@example.com:example.git']],
        'sync'    => ['default_source' => '@prod'],
      ], 4, 2),
    );

    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    $this->commandInstance->setExecutables([
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ]);
    // @prod is undefined (no setKnownAliases call): should WARN but not FAIL.
    $exitCode = $this->tester->run(['command' => 'suds:doctor']);
    $this->assertSame(0, $exitCode);
    $this->assertStringContainsString('@prod', $this->tester->getDisplay());
  }

  /**
   * Tests that a config type error in suds.yml produces a WARN result.
   *
   * Writes a suds.yml where sync.db.sanitize (a bool by default) is set to
   * a string. The doctor command should report a WARN but still exit 0.
   */
  public function testDoctorWarnsOnConfigTypeError(): void {
    mkdir($this->projectRoot . '/web/core', 0755, TRUE);
    // Write raw YAML so sync.db.sanitize is a string instead of a bool.
    $yaml = <<<'YAML'
    project:
      name: Integration Test

    drupal:
      root: web

    sync:
      db:
        sanitize: 'yes'

    deploy:
      repo:
        url: 'git@example.com:example.git'
    YAML;
    file_put_contents($this->projectRoot . '/suds.yml', $yaml . "\n");

    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    $this->commandInstance->setExecutables([
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ]);
    $this->commandInstance->setKnownAliases([]);

    $exitCode = $this->tester->run(['command' => 'suds:doctor']);
    $this->assertSame(0, $exitCode);
    $this->assertStringContainsString('sync.db.sanitize', $this->tester->getDisplay());
  }

  /**
   * Tests that a typo'd config key in suds.yml produces a WARN result.
   *
   * Writes a suds.yml containing an unknown key (drupal.rot instead of
   * drupal.root). The doctor command should report a WARN but still exit 0.
   */
  public function testDoctorWarnsOnUnknownConfigKey(): void {
    mkdir($this->projectRoot . '/web/core', 0755, TRUE);
    file_put_contents(
      $this->projectRoot . '/suds.yml',
      Yaml::dump([
        'project' => ['name' => 'Integration Test'],
        'drupal'  => ['root' => 'web', 'rot' => 'typo'],
        'deploy'  => ['repo' => ['url' => 'git@example.com:example.git']],
        'sync'    => ['default_source' => '@prod'],
      ], 4, 2),
    );

    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    $this->commandInstance->setExecutables([
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ]);

    $exitCode = $this->tester->run(['command' => 'suds:doctor']);
    $this->assertSame(0, $exitCode);
    $this->assertStringContainsString('drupal.rot', $this->tester->getDisplay());
  }

  /**
   * Tests that suds:doctor warns when project root is not a git repository.
   *
   * Deploy is configured but the project is not inside a git repository, so
   * doctor should report a WARN and still exit 0.
   */
  public function testDoctorWarnsWhenNotInGitRepository(): void {
    mkdir($this->projectRoot . '/web/core', 0755, TRUE);
    file_put_contents(
      $this->projectRoot . '/suds.yml',
      Yaml::dump([
        'project' => ['name' => 'Integration Test'],
        'drupal'  => ['root' => 'web'],
        'deploy'  => ['repo' => ['url' => 'git@example.com:example.git']],
        'sync'    => ['default_source' => '@prod'],
      ], 4, 2),
    );

    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    $this->commandInstance->setExecutables([
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ]);
    $this->commandInstance->setKnownAliases(['@prod']);
    $this->commandInstance->setGitRepository(FALSE);

    $exitCode = $this->tester->run(['command' => 'suds:doctor']);
    $this->assertSame(0, $exitCode);
    $this->assertStringContainsString('git repository', $this->tester->getDisplay());
  }

  /**
   * Tests that suds:doctor warns when quality config files are absent.
   */
  public function testDoctorWarnsWhenQualityFilesAbsent(): void {
    mkdir($this->projectRoot . '/web/core', 0755, TRUE);
    file_put_contents(
      $this->projectRoot . '/suds.yml',
      Yaml::dump([
        'project' => ['name' => 'Integration Test'],
        'drupal'  => ['root' => 'web'],
        'sync'    => ['default_source' => '@prod'],
      ], 4, 2),
    );

    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    $this->commandInstance->setExecutables([
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ]);
    $this->commandInstance->setKnownAliases(['@prod']);

    $exitCode = $this->tester->run(['command' => 'suds:doctor']);
    $this->assertSame(0, $exitCode);
    $this->assertStringContainsString('grumphp.yml', $this->tester->getDisplay());
    $this->assertStringContainsString('suds:scaffold:quality', $this->tester->getDisplay());
  }

  /**
   * Tests that suds:doctor warns when the GrumPHP pre-commit hook is absent.
   */
  public function testDoctorWarnsWhenGrumphpHookMissing(): void {
    mkdir($this->projectRoot . '/web/core', 0755, TRUE);
    mkdir($this->projectRoot . '/.git/hooks', 0755, TRUE);
    file_put_contents($this->projectRoot . '/grumphp.yml', '');
    file_put_contents($this->projectRoot . '/phpcs.xml.dist', '');
    file_put_contents($this->projectRoot . '/phpstan.neon', '');
    // Intentionally omit pre-commit hook.
    file_put_contents(
      $this->projectRoot . '/suds.yml',
      Yaml::dump([
        'project' => ['name' => 'Integration Test'],
        'drupal'  => ['root' => 'web'],
        'sync'    => ['default_source' => '@prod'],
      ], 4, 2),
    );

    $this->commandInstance->setConfigLoader(
      new ConfigLoader($this->projectRoot, $this->packageRoot()),
    );
    $this->commandInstance->setExecutables([
      'composer' => '/usr/bin/composer',
      'rsync'    => '/usr/bin/rsync',
      'git'      => '/usr/bin/git',
    ]);
    $this->commandInstance->setKnownAliases(['@prod']);
    $this->commandInstance->setGitDir($this->projectRoot . '/.git');

    $exitCode = $this->tester->run(['command' => 'suds:doctor']);
    $this->assertSame(0, $exitCode);
    $this->assertStringContainsString('grumphp hook', $this->tester->getDisplay());
    $this->assertStringContainsString('composer install', $this->tester->getDisplay());
  }

  /**
   * Returns the package root path (contains config/suds.defaults.yml).
   */
  private function packageRoot(): string {
    return dirname(__DIR__, 3);
  }

}

/**
 * Testable subclass of DoctorCommands for integration tests.
 *
 * Overrides findExecutable() and drushAliasExists() so binary detection and
 * alias lookups are injected rather than delegated to the real system.
 */
class TestableDoctorCommands extends DoctorCommands {

  /**
   * Map of binary name to resolved path (null = not found).
   *
   * @var array<string, string|null>
   */
  private array $executables = [];

  /**
   * Aliases that drushAliasExists() should return TRUE for.
   *
   * @var list<string>
   */
  private array $knownAliases = [];

  /**
   * Sets the binary map used by findExecutable().
   *
   * @param array<string, string|null> $executables
   *   Map of binary name to path, or null if unavailable.
   */
  public function setExecutables(array $executables): void {
    $this->executables = $executables;
  }

  /**
   * Sets the aliases that drushAliasExists() treats as defined.
   *
   * @param list<string> $knownAliases
   *   Aliases to treat as defined.
   */
  public function setKnownAliases(array $knownAliases): void {
    $this->knownAliases = $knownAliases;
  }

  /**
   * Whether isGitRepository() should return TRUE.
   *
   * Defaults to TRUE so existing tests are unaffected.
   *
   * @var bool
   */
  private bool $gitRepository = TRUE;

  /**
   * The value findGitDir() should return. NULL means no .git dir found.
   *
   * @var string|null
   */
  private ?string $gitDir = NULL;

  /**
   * Sets whether the project root is treated as a git repository.
   *
   * @param bool $isGitRepository
   *   TRUE to treat as a git repository, FALSE otherwise.
   */
  public function setGitRepository(bool $isGitRepository): void {
    $this->gitRepository = $isGitRepository;
  }

  /**
   * Sets the path returned by findGitDir().
   *
   * @param string|null $gitDir
   *   Absolute path to a .git directory, or NULL to simulate no repo.
   */
  public function setGitDir(?string $gitDir): void {
    $this->gitDir = $gitDir;
  }

  /**
   * {@inheritdoc}
   */
  protected function findExecutable(string $binary): ?string {
    return $this->executables[$binary] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function drushAliasExists(string $alias): bool {
    return in_array($alias, $this->knownAliases, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function isGitRepository(string $dir): bool {
    return $this->gitRepository;
  }

  /**
   * {@inheritdoc}
   */
  protected function findGitDir(string $dir): ?string {
    return $this->gitDir;
  }

}
