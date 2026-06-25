<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Integration\Commands;

use Bounteous\Suds\Drush\Commands\InitCommands;
use Bounteous\Suds\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Integration tests for InitCommands.
 *
 * Commands are invoked through the Symfony Application API via
 * AnnotatedCommandFactory — no subprocess is spawned and no Drupal bootstrap
 * is required. File-system assertions use a temporary directory injected via
 * TestableInitCommands to avoid mutating the process-global working directory.
 */
#[CoversClass(InitCommands::class)]
class InitCommandsIntegrationTest extends IntegrationTestCase {

  /**
   * The application tester.
   *
   * @var \Symfony\Component\Console\Tester\ApplicationTester
   */
  private ApplicationTester $tester;

  /**
   * The command instance under test.
   *
   * @var \Bounteous\Suds\Tests\Integration\Commands\TestableInitCommands
   */
  private TestableInitCommands $commandInstance;

  /**
   * Temporary directory used as the fake project root.
   *
   * @var string
   */
  private string $tmpDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->tmpDir          = $this->createTempDir('suds_init_integration_');
    $this->commandInstance = new TestableInitCommands($this->tmpDir);
    $this->tester          = $this->buildTester($this->commandInstance);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeDirectory($this->tmpDir);
  }

  /**
   * Tests that suds:init exits cleanly when --name is provided.
   */
  public function testInitExitsCleanlyWithName(): void {
    $exitCode = $this->tester->run([
      'command' => 'suds:init',
      '--name' => 'Integration Test Project',
      '--drupal-root' => 'web',
    ]);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that suds:init creates suds.yml in the target directory.
   */
  public function testInitCreatesSudsYml(): void {
    $this->tester->run([
      'command' => 'suds:init',
      '--name' => 'My Project',
      '--drupal-root' => 'web',
    ]);
    $this->assertFileExists($this->tmpDir . '/suds.yml');
  }

  /**
   * Tests that the generated suds.yml contains the supplied project name.
   */
  public function testInitYmlContainsProjectName(): void {
    $this->tester->run([
      'command' => 'suds:init',
      '--name' => 'Acme Site',
      '--drupal-root' => 'web',
    ]);
    $contents = file_get_contents($this->tmpDir . '/suds.yml');
    $this->assertIsString($contents);
    $this->assertStringContainsString('name: Acme Site', $contents);
  }

  /**
   * Tests that the generated suds.yml includes header comments.
   */
  public function testInitYmlContainsHeaderComment(): void {
    $this->tester->run([
      'command' => 'suds:init',
      '--name' => 'Acme Site',
      '--drupal-root' => 'web',
    ]);
    $contents = file_get_contents($this->tmpDir . '/suds.yml');
    $this->assertIsString($contents);
    $this->assertStringContainsString('suds:config:dump', $contents);
  }

  /**
   * Tests that suds:init appends suds.local.yml to .gitignore.
   */
  public function testInitAppendsToExistingGitignore(): void {
    file_put_contents($this->tmpDir . '/.gitignore', "/vendor\n");

    $this->tester->run([
      'command' => 'suds:init',
      '--name' => 'Acme Site',
      '--drupal-root' => 'web',
    ]);

    $gitignore = file_get_contents($this->tmpDir . '/.gitignore');
    $this->assertIsString($gitignore);
    $this->assertStringContainsString('suds.local.yml', $gitignore);
  }

  /**
   * Tests that suds:init does not duplicate the gitignore entry.
   */
  public function testInitDoesNotDuplicateGitignoreEntry(): void {
    file_put_contents($this->tmpDir . '/.gitignore', "/vendor\nsuds.local.yml\n");

    $this->tester->run([
      'command' => 'suds:init',
      '--name' => 'Acme Site',
      '--drupal-root' => 'web',
    ]);

    $gitignore = file_get_contents($this->tmpDir . '/.gitignore');
    $this->assertIsString($gitignore);
    $this->assertSame(1, substr_count($gitignore, 'suds.local.yml'));
  }

  /**
   * Tests that suds:init creates .gitignore when none exists.
   */
  public function testInitCreatesGitignoreWhenAbsent(): void {
    $this->tester->run([
      'command' => 'suds:init',
      '--name' => 'Acme Site',
      '--drupal-root' => 'web',
    ]);
    $gitignore = file_get_contents($this->tmpDir . '/.gitignore');
    $this->assertIsString($gitignore);
    $this->assertStringContainsString('suds.local.yml', $gitignore);
  }

  /**
   * Tests that suds:init detects a single webroot and uses it automatically.
   */
  public function testInitDetectsSingleDrupalRoot(): void {
    mkdir($this->tmpDir . '/docroot', 0755, TRUE);

    $exitCode = $this->tester->run([
      'command' => 'suds:init',
      '--name'  => 'Auto Detect Project',
    ]);
    $this->assertSame(0, $exitCode);

    $contents = file_get_contents($this->tmpDir . '/suds.yml');
    $this->assertIsString($contents);
    $this->assertStringContainsString('root: docroot', $contents);
  }

  /**
   * Tests that the generated suds.yml contains a drupal.root section.
   */
  public function testInitYmlContainsDrupalRootSection(): void {
    $this->tester->run([
      'command'        => 'suds:init',
      '--name'         => 'Acme Site',
      '--drupal-root'  => 'html',
    ]);

    $contents = file_get_contents($this->tmpDir . '/suds.yml');
    $this->assertIsString($contents);
    $this->assertStringContainsString('drupal:', $contents);
    $this->assertStringContainsString('root: html', $contents);
  }

  /**
   * Tests that --drupal-root option takes priority over auto-detection.
   */
  public function testInitDrupalRootOptionOverridesDetection(): void {
    mkdir($this->tmpDir . '/web', 0755, TRUE);

    $this->tester->run([
      'command'        => 'suds:init',
      '--name'         => 'Acme Site',
      '--drupal-root'  => 'docroot',
    ]);

    $contents = file_get_contents($this->tmpDir . '/suds.yml');
    $this->assertIsString($contents);
    $this->assertStringContainsString('root: docroot', $contents);
  }

  /**
   * Tests that suds:init exits non-zero when suds.yml already exists.
   *
   * AnnotatedCommand catches exceptions inside execute() and converts them to
   * non-zero exit codes, so we assert the exit code rather than an exception.
   */
  public function testInitThrowsWhenSudsYmlExists(): void {
    file_put_contents($this->tmpDir . '/suds.yml', 'project: {name: existing}');

    $exitCode = $this->tester->run([
      'command' => 'suds:init',
      '--name' => 'Acme Site',
      '--drupal-root' => 'web',
    ]);

    $this->assertNotSame(0, $exitCode);
    $this->assertStringContainsString('already exists', $this->tester->getDisplay());
  }

  /**
   * Tests that suds:init output mentions the project name on success.
   */
  public function testInitOutputMentionsProjectName(): void {
    $this->tester->run([
      'command' => 'suds:init',
      '--name' => 'Acme Site',
      '--drupal-root' => 'web',
    ]);
    $this->assertStringContainsString('Acme Site', $this->tester->getDisplay());
  }

  /**
   * Tests that suds:init dispatches quality scaffold by default.
   */
  public function testInitDispatchesQualityScaffoldByDefault(): void {
    $this->tester->run([
      'command'      => 'suds:init',
      '--name'       => 'Acme Site',
      '--drupal-root' => 'web',
    ]);

    $this->assertTrue($this->commandInstance->wasQualityScaffoldDispatched());
  }

  /**
   * Tests that --skip-quality prevents the quality scaffold dispatch.
   */
  public function testInitSkipsQualityScaffoldWhenFlagSet(): void {
    $this->tester->run([
      'command'        => 'suds:init',
      '--name'         => 'Acme Site',
      '--drupal-root'  => 'web',
      '--skip-quality' => TRUE,
    ]);

    $this->assertFalse($this->commandInstance->wasQualityScaffoldDispatched());
  }

  /**
   * Tests that --ci-provider dispatches the CI scaffold with the given value.
   */
  public function testInitDispatchesCiScaffoldWhenProviderSet(): void {
    $this->tester->run([
      'command'        => 'suds:init',
      '--name'         => 'Acme Site',
      '--drupal-root'  => 'web',
      '--skip-quality' => TRUE,
      '--ci-provider'  => 'github',
    ]);

    $this->assertSame('github', $this->commandInstance->getCiScaffoldProvider());
  }

  /**
   * Tests that --skip-ci prevents the CI scaffold dispatch.
   */
  public function testInitSkipsCiScaffoldWhenSkipCiSet(): void {
    $this->tester->run([
      'command'        => 'suds:init',
      '--name'         => 'Acme Site',
      '--drupal-root'  => 'web',
      '--skip-quality' => TRUE,
      '--skip-ci'      => TRUE,
    ]);

    $this->assertNull($this->commandInstance->getCiScaffoldProvider());
  }

  /**
   * Tests that passing both --skip-ci and --ci-provider exits non-zero.
   */
  public function testInitExitsNonZeroWhenSkipCiAndCiProviderBothSet(): void {
    $exitCode = $this->tester->run([
      'command'        => 'suds:init',
      '--name'         => 'Acme Site',
      '--drupal-root'  => 'web',
      '--skip-quality' => TRUE,
      '--skip-ci'      => TRUE,
      '--ci-provider'  => 'github',
    ]);

    $this->assertNotSame(0, $exitCode);
    $this->assertStringContainsString('mutually exclusive', $this->tester->getDisplay());
  }

}

/**
 * Testable subclass of InitCommands with an injectable target directory.
 *
 * Overrides getTargetDir() so integration tests can write suds.yml to a
 * temporary directory without chdir(). Overrides dispatchQualityScaffold()
 * and dispatchCiScaffold() as no-ops so tests do not require a live Drush DI
 * container, and tracks whether each dispatch was requested.
 */
class TestableInitCommands extends InitCommands {

  /**
   * The directory to use as the target for file operations.
   *
   * @var string
   */
  private string $targetDir;

  /**
   * Whether dispatchQualityScaffold() was called.
   *
   * @var bool
   */
  private bool $qualityScaffoldDispatched = FALSE;

  /**
   * The provider passed to dispatchCiScaffold(), or NULL if not called.
   *
   * @var string|null
   */
  private ?string $ciScaffoldProvider = NULL;

  /**
   * Constructs a TestableInitCommands instance.
   *
   * @param string $targetDir
   *   Absolute path to the temporary directory that stands in for CWD.
   */
  public function __construct(string $targetDir) {
    $this->targetDir = $targetDir;
  }

  /**
   * {@inheritdoc}
   */
  protected function getTargetDir(): string {
    return $this->targetDir;
  }

  /**
   * {@inheritdoc}
   *
   * No-op in integration tests — no live Drush DI container is present.
   */
  protected function dispatchQualityScaffold(): void {
    $this->qualityScaffoldDispatched = TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * No-op in integration tests — no live Drush DI container is present.
   *
   * @param string $provider
   *   The CI provider identifier.
   */
  protected function dispatchCiScaffold(string $provider): void {
    $this->ciScaffoldProvider = $provider;
  }

  /**
   * Returns whether dispatchQualityScaffold() was called.
   *
   * @return bool
   *   TRUE if the dispatch was triggered, FALSE otherwise.
   */
  public function wasQualityScaffoldDispatched(): bool {
    return $this->qualityScaffoldDispatched;
  }

  /**
   * Returns the provider passed to dispatchCiScaffold(), or NULL.
   *
   * @return string|null
   *   The provider string, or NULL if the dispatch was not triggered.
   */
  public function getCiScaffoldProvider(): ?string {
    return $this->ciScaffoldProvider;
  }

}
