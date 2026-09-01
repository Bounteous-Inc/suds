<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Unit\Commands;

use Bounteous\Suds\Config\ConfigLoaderInterface;
use Bounteous\Suds\Drush\Commands\SyncCommands;
use Consolidation\SiteAlias\SiteAlias;
use Consolidation\SiteAlias\SiteAliasManagerInterface;
use Drush\Style\DrushStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SyncCommands.
 *
 * @phpstan-type SyncOptions array{
 *   'skip-sanitize': bool,
 *   'force-files': bool,
 *   'skip-files': bool,
 *   'db-file': string,
 *   latest: bool,
 * }
 */
#[CoversClass(SyncCommands::class)]
class SyncCommandsTest extends TestCase {

  /**
   * Verifies suds:sync has the correct @command and option annotations.
   */
  public function testSyncAnnotations(): void {
    $doc = (new \ReflectionMethod(SyncCommands::class, 'sync'))->getDocComment();
    $this->assertIsString($doc);
    $this->assertStringContainsString(' suds:sync', $doc);
    $this->assertStringContainsString(' su-sync', $doc);
    $this->assertStringContainsString('@bootstrap none', $doc);
    $this->assertStringContainsString('@option skip-sanitize', $doc);
    $this->assertStringContainsString('@option force-files', $doc);
    $this->assertStringContainsString('@option skip-files', $doc);
    $this->assertStringContainsString('@option db-file', $doc);
    $this->assertStringContainsString('@option latest', $doc);
  }

  /**
   * Verifies sync() option defaults include force-files and skip-files.
   */
  public function testSyncDefaultOptions(): void {
    $params = (new \ReflectionMethod(SyncCommands::class, 'sync'))->getParameters();
    $this->assertCount(2, $params);

    $default = $params[1]->getDefaultValue();
    $this->assertIsArray($default);
    $this->assertFalse($default['skip-sanitize']);
    $this->assertFalse($default['force-files']);
    $this->assertFalse($default['skip-files']);
    $this->assertSame('', $default['db-file']);
    $this->assertFalse($default['latest']);
  }

  /**
   * Verifies sync() filters sync-specific flags before forwarding to children.
   *
   * --skip-sanitize, --force-files, --skip-files, and --db-file must not be
   * forwarded to child commands (e.g. suds:update) that do not accept them.
   */
  public function testSyncFiltersOwnOptionsFromSubCommandOptions(): void {
    $capturedOptions = [];
    $command = $this->buildCommand(
      $this->makeConfigLoader($this->makeConfig(defaultSource: '@prod', sanitize: FALSE)),
      redispatchOptions: [
        'skip-sanitize' => FALSE,
        'force-files'   => FALSE,
        'skip-files'    => FALSE,
        'file'          => '/tmp/backup.sql',
        'latest'        => TRUE,
        'yes'           => TRUE,
      ],
      drushOptions: $capturedOptions,
    );

    // Source provided, $options['db-file'] and $options['latest'] default to
    // their zero values — uses source path, so sync-specific keys from
    // redispatchOptions are filtered and not re-added to any child command.
    $command->sync('@prod');

    // None of the sync-specific flags should appear in child command options.
    foreach ($capturedOptions as $cmd => $opts) {
      $this->assertArrayNotHasKey('skip-sanitize', $opts, "skip-sanitize must not be forwarded to $cmd");
      $this->assertArrayNotHasKey('force-files', $opts, "force-files must not be forwarded to $cmd");
      $this->assertArrayNotHasKey('skip-files', $opts, "skip-files must not be forwarded to $cmd");
      $this->assertArrayNotHasKey('db-file', $opts, "file must not be forwarded to $cmd");
      $this->assertArrayNotHasKey('latest', $opts, "latest must not be forwarded to $cmd");
      // The global --yes flag must still be forwarded.
      $this->assertArrayHasKey('yes', $opts, "--yes must be forwarded to $cmd");
    }
  }

  /**
   * Verifies sync() throws a RuntimeException when composer is not on PATH.
   */
  public function testSyncThrowsWhenComposerNotFound(): void {
    $command = $this->buildCommand(
      $this->makeConfigLoader($this->makeConfig(defaultSource: '@prod', sanitize: FALSE)),
      composerPath: NULL,
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/composer not found on PATH/');

    $command->sync('@prod');
  }

  /**
   * Verifies sync() throws when no source is given and none is configured.
   */
  public function testSyncThrowsWhenNoSource(): void {
    $command = $this->buildCommand(
      $this->makeConfigLoader($this->makeConfig()),
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/sync\.db\.default_source/');

    $command->sync('');
  }

  /**
   * Verifies sync() uses default_source from config when no source given.
   */
  public function testSyncUsesDefaultSourceFromConfig(): void {
    $dispatchedArgs = [];
    $command = $this->buildCommand(
      $this->makeConfigLoader($this->makeConfig(defaultSource: '@prod', sanitize: FALSE)),
      drushArgs: $dispatchedArgs,
    );

    // No source argument — should fall back to @prod from config.
    $command->sync('');

    $this->assertSame(['@prod'], $dispatchedArgs['suds:db:sync'] ?? []);
    $this->assertArrayHasKey('suds:update', $dispatchedArgs);
  }

  /**
   * Verifies sync() dispatches db:sync, db:sanitize, and suds:update.
   */
  public function testSyncDispatchesDbSyncSanitizeAndUpdate(): void {
    $dispatchedCommands = [];
    $command = $this->buildCommand(
      $this->makeConfigLoader($this->makeConfig(defaultSource: '@prod', sanitize: TRUE)),
      drushCommands: $dispatchedCommands,
    );

    $command->sync('@prod');

    $this->assertContains('suds:db:sync', $dispatchedCommands);
    $this->assertContains('suds:db:sanitize', $dispatchedCommands);
    $this->assertContains('suds:update', $dispatchedCommands);
  }

  /**
   * Verifies sync() skips db:sanitize when sync.db.sanitize is false.
   */
  public function testSyncSkipsSanitizeWhenConfigDisabled(): void {
    $dispatchedCommands = [];
    $command = $this->buildCommand(
      $this->makeConfigLoader($this->makeConfig(defaultSource: '@prod', sanitize: FALSE)),
      drushCommands: $dispatchedCommands,
    );

    $command->sync('@prod');

    $this->assertNotContains('suds:db:sanitize', $dispatchedCommands);
    $this->assertContains('suds:db:sync', $dispatchedCommands);
    $this->assertContains('suds:update', $dispatchedCommands);
  }

  /**
   * Verifies sync() dispatches files:sync when --force-files is set.
   */
  public function testSyncRunsFilesWhenForceFilesOptionSet(): void {
    $dispatchedCommands = [];
    $command = $this->buildCommand(
      $this->makeConfigLoader(
        $this->makeConfig(defaultSource: '@prod', sanitize: FALSE, filesEnabled: FALSE),
      ),
      drushCommands: $dispatchedCommands,
    );

    $command->sync('@prod', $this->syncOptions(forceFiles: TRUE));

    $this->assertContains('suds:files:sync', $dispatchedCommands);
  }

  /**
   * Verifies --skip-files overrides sync.files.enabled in config.
   */
  public function testSyncSkipsFilesWhenSkipFilesSetEvenIfConfigEnabled(): void {
    $dispatchedCommands = [];
    $command = $this->buildCommand(
      $this->makeConfigLoader(
        $this->makeConfig(defaultSource: '@prod', sanitize: FALSE, filesEnabled: TRUE),
      ),
      drushCommands: $dispatchedCommands,
    );

    $command->sync('@prod', $this->syncOptions(skipFiles: TRUE));

    $this->assertNotContains('suds:files:sync', $dispatchedCommands);
  }

  /**
   * Verifies sync() throws when --force-files and --skip-files are both set.
   */
  public function testSyncThrowsWhenBothFilesOptionsSet(): void {
    $command = $this->buildCommand(
      $this->makeConfigLoader($this->makeConfig(defaultSource: '@prod', sanitize: FALSE)),
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/mutually exclusive/');

    $command->sync('@prod', $this->syncOptions(forceFiles: TRUE, skipFiles: TRUE));
  }

  /**
   * Verifies sync() calls runShellCommand per pre_sync hook before composer.
   *
   * Pre-sync hooks run before composer install so they can prepare the
   * environment (e.g. set up credentials) before dependencies are installed.
   */
  public function testSyncRunsPreSyncHooksBeforeComposer(): void {
    $shellCommands = [];
    $command = $this->buildCommand(
      $this->makeConfigLoader($this->makeConfig(
        defaultSource: '@prod',
        sanitize: FALSE,
        preSync: ['echo pre-a', 'echo pre-b'],
      )),
      shellCommands: $shellCommands,
    );

    $command->sync('@prod', $this->syncOptions());

    // pre_sync commands run first, then composer install.
    $this->assertSame('echo pre-a', $shellCommands[0] ?? NULL);
    $this->assertSame('echo pre-b', $shellCommands[1] ?? NULL);
    $this->assertSame('composer install', $shellCommands[2] ?? NULL);
  }

  /**
   * Verifies sync() runs composer install and post_sync shell commands.
   *
   * Composer install is always the first shell command after pre_sync hooks.
   * Post_sync hook commands follow after suds:update completes.
   */
  public function testSyncRunsComposerInstallAndPostSyncHooks(): void {
    $shellCommands = [];
    $command = $this->buildCommand(
      $this->makeConfigLoader($this->makeConfig(
        defaultSource: '@prod',
        sanitize: FALSE,
        postSync: ['npm run deploy', 'drush cr'],
      )),
      shellCommands: $shellCommands,
    );

    $command->sync('@prod', $this->syncOptions());

    // Composer install is always first; the two post_sync hooks follow.
    $this->assertSame('composer install', $shellCommands[0] ?? NULL);
    $this->assertContains('npm run deploy', $shellCommands);
    $this->assertCount(3, $shellCommands);
  }

  /**
   * Verifies sync() uses sync.db.default_source over sync.default_source.
   */
  public function testSyncUsesDbDefaultSourceOverTopLevel(): void {
    $dispatchedArgs = [];
    $command = $this->buildCommand(
      $this->makeConfigLoader($this->makeConfig(
        defaultSource: '@prod',
        sanitize: FALSE,
        dbDefaultSource: '@stage',
      )),
      drushArgs: $dispatchedArgs,
    );

    // No CLI source — @stage (per-db) should win over @prod (top-level).
    $command->sync('');

    $this->assertSame(['@stage'], $dispatchedArgs['suds:db:sync'] ?? []);
  }

  /**
   * Verifies sync() uses sync.files.default_source over sync.default_source.
   */
  public function testSyncUsesFilesDefaultSourceOverTopLevel(): void {
    $dispatchedArgs = [];
    $command = $this->buildCommand(
      $this->makeConfigLoader($this->makeConfig(
        defaultSource: '@prod',
        sanitize: FALSE,
        filesEnabled: TRUE,
        filesDefaultSource: '@stage',
      )),
      drushArgs: $dispatchedArgs,
    );

    // No CLI source — files step should use @stage, not @prod.
    $command->sync('');

    $this->assertSame(['@stage'], $dispatchedArgs['suds:files:sync'] ?? []);
    $this->assertSame(['@prod'], $dispatchedArgs['suds:db:sync'] ?? []);
  }

  /**
   * Verifies sync() CLI source arg overrides per-section defaults.
   */
  public function testSyncCliArgOverridesPerSectionDefaults(): void {
    $dispatchedArgs = [];
    $command = $this->buildCommand(
      $this->makeConfigLoader($this->makeConfig(
        defaultSource: '@prod',
        sanitize: FALSE,
        filesEnabled: TRUE,
        dbDefaultSource: '@stage',
        filesDefaultSource: '@stage',
      )),
      drushArgs: $dispatchedArgs,
    );

    // CLI @hotfix should beat both @stage (per-section) and @prod (top-level).
    $command->sync('@hotfix');

    $this->assertSame(['@hotfix'], $dispatchedArgs['suds:db:sync'] ?? []);
    $this->assertSame(['@hotfix'], $dispatchedArgs['suds:files:sync'] ?? []);
  }

  /**
   * Verifies sync() skips db:sanitize when --skip-sanitize is set.
   */
  public function testSyncSkipsSanitizeWhenSkipSanitizeSet(): void {
    $dispatchedCommands = [];
    $command = $this->buildCommand(
      // sanitize: TRUE in config — but --skip-sanitize should override it.
      $this->makeConfigLoader($this->makeConfig(defaultSource: '@prod', sanitize: TRUE)),
      drushCommands: $dispatchedCommands,
    );

    $command->sync('@prod', $this->syncOptions(skipSanitize: TRUE));

    $this->assertNotContains('suds:db:sanitize', $dispatchedCommands);
    $this->assertContains('suds:db:sync', $dispatchedCommands);
    $this->assertContains('suds:update', $dispatchedCommands);
  }

  /**
   * Verifies sync() does not require a source alias when --db-file is provided.
   *
   * With --db-file, the database step uses the local file instead of pulling
   * from a remote alias. suds:db:sync and suds:update are still
   * dispatched; no source alias validation is triggered.
   */
  public function testSyncWithFileDoesNotRequireSourceAlias(): void {
    $dispatchedCommands = [];
    $command = $this->buildCommand(
      // No default_source configured anywhere.
      $this->makeConfigLoader($this->makeConfig(sanitize: FALSE, filesEnabled: FALSE)),
      drushCommands: $dispatchedCommands,
    );

    // --db-file provided, no source — db step uses the file, no alias needed.
    $command->sync('', $this->syncOptions(file: '/tmp/backup.sql'));

    $this->assertContains('suds:db:sync', $dispatchedCommands);
    $this->assertContains('suds:update', $dispatchedCommands);
  }

  /**
   * Verifies sync() passes --db-file to the suds:db:sync sub-command.
   *
   * When --db-file is provided, suds:db:sync must receive the file option
   * rather than a positional source alias argument.
   */
  public function testSyncPassesFileToDbSync(): void {
    $capturedOptions = [];
    $capturedArgs = [];
    $command = $this->buildCommand(
      $this->makeConfigLoader($this->makeConfig(sanitize: FALSE, filesEnabled: FALSE)),
      drushArgs: $capturedArgs,
      drushOptions: $capturedOptions,
    );

    $command->sync('', $this->syncOptions(file: '/tmp/backup.sql'));

    // suds:db:sync receives --db-file, not a positional source alias.
    $this->assertSame([], $capturedArgs['suds:db:sync'] ?? NULL);
    $this->assertSame('/tmp/backup.sql', $capturedOptions['suds:db:sync']['db-file'] ?? NULL);
  }

  /**
   * Verifies sync() with --latest does not require a source alias.
   *
   * The db source validation is bypassed when --latest is set, and
   * suds:db:sync is dispatched with ['latest' => TRUE] in its options.
   */
  public function testSyncWithLatestDoesNotRequireSourceAlias(): void {
    $dispatchedCommands = [];
    $command = $this->buildCommand(
      $this->makeConfigLoader($this->makeConfig(sanitize: FALSE, filesEnabled: FALSE)),
      drushCommands: $dispatchedCommands,
    );

    $command->sync('', $this->syncOptions(latest: TRUE));

    $this->assertContains('suds:db:sync', $dispatchedCommands);
    $this->assertContains('suds:update', $dispatchedCommands);
  }

  /**
   * Verifies sync() passes --latest to the suds:db:sync sub-command.
   */
  public function testSyncPassesLatestToDbSync(): void {
    $capturedOptions = [];
    $capturedArgs = [];
    $command = $this->buildCommand(
      $this->makeConfigLoader($this->makeConfig(sanitize: FALSE, filesEnabled: FALSE)),
      drushArgs: $capturedArgs,
      drushOptions: $capturedOptions,
    );

    $command->sync('', $this->syncOptions(latest: TRUE));

    // suds:db:sync receives latest=TRUE with no positional source alias.
    $this->assertSame([], $capturedArgs['suds:db:sync'] ?? NULL);
    $this->assertTrue($capturedOptions['suds:db:sync']['latest'] ?? FALSE);
  }

  /**
   * Returns a sync() options array with named overrides applied.
   *
   * Centralises the 5-key options array so call sites stay within line-length
   * limits and only the option under test needs to be specified explicitly.
   *
   * @param bool $skipSanitize
   *   Value for skip-sanitize.
   * @param bool $forceFiles
   *   Value for force-files.
   * @param bool $skipFiles
   *   Value for skip-files.
   * @param string $file
   *   Value for file.
   * @param bool $latest
   *   Value for latest.
   *
   * @return SyncOptions
   *   Options array suitable for passing to sync().
   */
  private function syncOptions(
    bool $skipSanitize = FALSE,
    bool $forceFiles = FALSE,
    bool $skipFiles = FALSE,
    string $file = '',
    bool $latest = FALSE,
  ): array {
    return [
      'skip-sanitize' => $skipSanitize,
      'force-files'   => $forceFiles,
      'skip-files'    => $skipFiles,
      'db-file'       => $file,
      'latest'        => $latest,
    ];
  }

  /**
   * Builds a SyncCommands mock with standard dependencies wired up.
   *
   * Tracking arrays (passed by reference) are populated by the runDrushCommand
   * and runShellCommand stubs so individual tests can assert on call behaviour
   * without building separate ProcessManager mocks.
   *
   * @param \Bounteous\Suds\Config\ConfigLoaderInterface $loader
   *   Config loader to inject.
   * @param array<string, mixed> $redispatchOptions
   *   Value returned by redispatchOptions(). Defaults to empty array.
   * @param list<string> $drushCommands
   *   Receives each drush command name in call order.
   * @param array<string, list<mixed>> $drushArgs
   *   Receives positional args keyed by command name.
   * @param array<string, array<string, mixed>> $drushOptions
   *   Receives options keyed by command name.
   * @param list<string> $shellCommands
   *   Receives each shell command string in call order.
   * @param string|null $composerPath
   *   Value returned by findExecutable('composer'). Defaults to a fake path.
   *
   * @return \Bounteous\Suds\Drush\Commands\SyncCommands&\PHPUnit\Framework\MockObject\MockObject
   *   A configured PHPUnit mock of SyncCommands.
   */
  private function buildCommand(
    ConfigLoaderInterface $loader,
    array $redispatchOptions = [],
    array &$drushCommands = [],
    array &$drushArgs = [],
    array &$drushOptions = [],
    array &$shellCommands = [],
    ?string $composerPath = '/usr/bin/composer',
  ): SyncCommands&MockObject {
    $mockAlias = $this->createMock(SiteAlias::class);
    $mockAliasManager = $this->createMock(SiteAliasManagerInterface::class);
    $mockAliasManager->method('getSelf')->willReturn($mockAlias);

    $command = $this->getMockBuilder(SyncCommands::class)
      ->onlyMethods([
        'io',
        'siteAliasManager',
        'redispatchOptions',
        'runDrushCommand',
        'runShellCommand',
        'findExecutable',
      ])
      ->getMock();
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->method('siteAliasManager')->willReturn($mockAliasManager);
    // Mirror the real redispatchOptions(): honour the $except list, so tests
    // exercise the exclusions each command declares rather than bypassing the
    // filtering that now lives in ProcessHelperTrait.
    $command->method('redispatchOptions')
      ->willReturnCallback(
        static fn (array $except = []): array => array_diff_key($redispatchOptions, array_flip($except)),
      );
    $command->method('findExecutable')->willReturn($composerPath);
    $command->method('runDrushCommand')
      ->willReturnCallback(
        static function (mixed $alias, string $cmd, array $args = [], array $opts = []) use (
          &$drushCommands,
          &$drushArgs,
          &$drushOptions,
        ): void {
          $drushCommands[] = $cmd;
          $drushArgs[$cmd] = $args;
          $drushOptions[$cmd] = $opts;
        }
      );
    $command->method('runShellCommand')
      ->willReturnCallback(
        static function (string $cmd) use (&$shellCommands): void {
          $shellCommands[] = $cmd;
        }
      );
    $command->setConfigLoader($loader);

    return $command;
  }

  /**
   * Builds a mock ConfigLoaderInterface wrapping the given config array.
   *
   * @param array<string, mixed> $config
   *   The config array to return from load().
   *
   * @return \Bounteous\Suds\Config\ConfigLoaderInterface
   *   A mock config loader.
   */
  private function makeConfigLoader(array $config): ConfigLoaderInterface {
    $loader = $this->createMock(ConfigLoaderInterface::class);
    $loader->method('getProjectRoot')->willReturn('/tmp');
    $loader->method('load')->willReturn($config);
    return $loader;
  }

  /**
   * Returns a minimal config array for sync() tests.
   *
   * @param string $defaultSource
   *   Value of sync.default_source; empty string means key is absent.
   * @param bool $sanitize
   *   Value of sync.db.sanitize.
   * @param bool $filesEnabled
   *   Value of sync.files.enabled.
   * @param list<string> $preSync
   *   Commands for sync.hooks.pre_sync.
   * @param list<string> $postSync
   *   Commands for sync.hooks.post_sync.
   * @param string|null $dbDefaultSource
   *   Value of sync.db.default_source; null = not set.
   * @param string|null $filesDefaultSource
   *   Value of sync.files.default_source; null = not set.
   *
   * @return array<string, mixed>
   *   The config array.
   */
  private function makeConfig(
    string $defaultSource = '',
    bool $sanitize = TRUE,
    bool $filesEnabled = FALSE,
    array $preSync = [],
    array $postSync = [],
    ?string $dbDefaultSource = NULL,
    ?string $filesDefaultSource = NULL,
  ): array {
    $config = [
      'sync' => [
        'db' => [
          'default_source'    => $dbDefaultSource,
          'sanitize'          => $sanitize,
          'truncate_tables'   => [],
          'sanitize_email'    => 'user@localhost',
          'sanitize_password' => 'password',
        ],
        'files' => [
          'default_source' => $filesDefaultSource,
          'enabled'        => $filesEnabled,
          'paths'          => [],
        ],
        'hooks' => [
          'pre_sync'  => $preSync,
          'post_sync' => $postSync,
        ],
      ],
    ];
    if ($defaultSource !== '') {
      $config['sync']['default_source'] = $defaultSource;
    }
    return $config;
  }

}
