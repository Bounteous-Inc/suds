<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Integration\Commands;

use Bounteous\Suds\Drush\Commands\ScaffoldCommands;
use Bounteous\Suds\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Integration tests for ScaffoldCommands.
 *
 * Commands are invoked through the Symfony Application API via
 * AnnotatedCommandFactory — no subprocess is spawned and no Drupal bootstrap
 * is required. File-system assertions use a temporary directory injected via
 * TestableScaffoldCommands to avoid mutating the process-global working
 * directory.
 */
#[CoversClass(ScaffoldCommands::class)]
class ScaffoldCommandsIntegrationTest extends IntegrationTestCase {

  /**
   * The application tester.
   *
   * @var \Symfony\Component\Console\Tester\ApplicationTester
   */
  private ApplicationTester $tester;

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
    $this->tmpDir = $this->createTempDir('suds_scaffold_int_');
    $this->tester = $this->buildTester(
      new TestableScaffoldCommands($this->tmpDir),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeDirectory($this->tmpDir);
  }

  /**
   * Tests that suds:scaffold:quality exits cleanly.
   */
  public function testScaffoldQualityExitsCleanly(): void {
    $exitCode = $this->tester->run([
      'command'       => 'suds:scaffold:quality',
      '--drupal-root' => 'web',
    ]);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that suds:scaffold:quality creates all three config files.
   */
  public function testScaffoldQualityCreatesAllThreeFiles(): void {
    $this->tester->run([
      'command'       => 'suds:scaffold:quality',
      '--drupal-root' => 'web',
    ]);

    $this->assertFileExists($this->tmpDir . '/grumphp.yml');
    $this->assertFileExists($this->tmpDir . '/phpcs.xml.dist');
    $this->assertFileExists($this->tmpDir . '/phpstan.neon');
  }

  /**
   * Tests that the drupal root token is substituted in every scaffolded file.
   */
  public function testScaffoldQualitySubstitutesDrupalRoot(): void {
    $this->tester->run([
      'command'       => 'suds:scaffold:quality',
      '--drupal-root' => 'docroot',
    ]);

    foreach (['grumphp.yml', 'phpcs.xml.dist', 'phpstan.neon'] as $file) {
      $contents = file_get_contents($this->tmpDir . '/' . $file);
      $this->assertIsString($contents);
      $this->assertStringNotContainsString('{{ drupal_root }}', $contents, $file);
      $this->assertStringContainsString('docroot', $contents, $file);
    }
  }

  /**
   * Tests that existing files are not overwritten without --force.
   */
  public function testScaffoldQualitySkipsExistingFiles(): void {
    file_put_contents($this->tmpDir . '/grumphp.yml', '# existing');

    $this->tester->run([
      'command'       => 'suds:scaffold:quality',
      '--drupal-root' => 'web',
    ]);

    $this->assertSame(
      '# existing',
      file_get_contents($this->tmpDir . '/grumphp.yml'),
    );
  }

  /**
   * Tests that --force overwrites existing files.
   */
  public function testScaffoldQualityOverwritesWithForce(): void {
    file_put_contents($this->tmpDir . '/grumphp.yml', '# existing');

    $this->tester->run([
      'command'       => 'suds:scaffold:quality',
      '--drupal-root' => 'web',
      '--force'       => TRUE,
    ]);

    $this->assertNotSame(
      '# existing',
      file_get_contents($this->tmpDir . '/grumphp.yml'),
    );
  }

  /**
   * Tests that output confirms files were created.
   */
  public function testScaffoldQualityOutputMentionsCreatedFiles(): void {
    $this->tester->run([
      'command'       => 'suds:scaffold:quality',
      '--drupal-root' => 'web',
    ]);

    $display = $this->tester->getDisplay();
    $this->assertStringContainsString('grumphp.yml', $display);
    $this->assertStringContainsString('phpcs.xml.dist', $display);
    $this->assertStringContainsString('phpstan.neon', $display);
  }

  /**
   * Tests that output notes when all quality files already exist.
   */
  public function testScaffoldQualityOutputsNoteWhenAllExist(): void {
    file_put_contents($this->tmpDir . '/grumphp.yml', '# existing');
    file_put_contents($this->tmpDir . '/phpcs.xml.dist', '<!-- existing -->');
    file_put_contents($this->tmpDir . '/phpstan.neon', '# existing');

    $this->tester->run([
      'command'       => 'suds:scaffold:quality',
      '--drupal-root' => 'web',
    ]);

    $this->assertStringContainsString(
      'No files were written',
      $this->tester->getDisplay(),
    );
  }

  /**
   * Pre-creates an empty grumphp.yml stub.
   *
   * Prevents scaffoldCi from reaching the interactive io()->confirm() call,
   * which requires a real input stream not available in ApplicationTester.
   */
  private function createGrumphpStub(): void {
    file_put_contents($this->tmpDir . '/grumphp.yml', '');
  }

  /**
   * Tests that suds:scaffold:ci exits non-zero for an unknown provider.
   */
  public function testScaffoldCiExitsNonZeroForUnknownProvider(): void {
    $exitCode = $this->tester->run([
      'command'   => 'suds:scaffold:ci',
      'provider'  => 'azure',
    ]);
    $this->assertNotSame(0, $exitCode);
  }

  /**
   * Tests that suds:scaffold:ci exits cleanly for each provider.
   *
   * @param string $provider
 *   CI provider identifier.
   */
  #[DataProvider('ciProviderProvider')]
  public function testScaffoldCiExitsCleanly(string $provider): void {
    $this->createGrumphpStub();
    $exitCode = $this->tester->run([
      'command'  => 'suds:scaffold:ci',
      'provider' => $provider,
    ]);
    $this->assertSame(0, $exitCode);
  }

  /**
   * Tests that suds:scaffold:ci creates the pipeline file for each provider.
   *
   * @param string $provider
 *   CI provider identifier.
   * @param string $expectedPath
 *   Expected file path relative to project root.
   */
  #[DataProvider('ciProviderProvider')]
  public function testScaffoldCiCreatesPipelineFile(
    string $provider,
    string $expectedPath,
  ): void {
    $this->createGrumphpStub();
    $this->tester->run([
      'command'  => 'suds:scaffold:ci',
      'provider' => $provider,
    ]);

    $this->assertFileExists($this->tmpDir . '/' . $expectedPath);
  }

  /**
   * Tests that suds:scaffold:ci always creates suds.ci.yml.
   *
   * @param string $provider
 *   CI provider identifier.
   */
  #[DataProvider('ciProviderProvider')]
  public function testScaffoldCiCreatesSudsCiYml(string $provider): void {
    $this->createGrumphpStub();
    $this->tester->run([
      'command'  => 'suds:scaffold:ci',
      'provider' => $provider,
    ]);

    $this->assertFileExists($this->tmpDir . '/suds.ci.yml');
  }

  /**
   * Tests that the php_version token is substituted in the pipeline file.
   *
   * @param string $provider
 *   CI provider identifier.
   * @param string $expectedPath
 *   Expected file path relative to project root.
   */
  #[DataProvider('ciProviderProvider')]
  public function testScaffoldCiSubstitutesPhpVersionToken(
    string $provider,
    string $expectedPath,
  ): void {
    $this->createGrumphpStub();
    $this->tester->run([
      'command'  => 'suds:scaffold:ci',
      'provider' => $provider,
    ]);

    $contents = file_get_contents($this->tmpDir . '/' . $expectedPath);
    $this->assertIsString($contents);
    $this->assertStringNotContainsString('{{ php_version }}', $contents);
  }

  /**
   * Tests that existing CI files are not overwritten without --force.
   */
  public function testScaffoldCiSkipsExistingFiles(): void {
    $this->createGrumphpStub();
    $pipelinePath = $this->tmpDir . '/.github/workflows/ci.yml';
    mkdir(dirname($pipelinePath), 0755, TRUE);
    file_put_contents($pipelinePath, '# existing');

    $this->tester->run([
      'command'  => 'suds:scaffold:ci',
      'provider' => 'github',
    ]);

    $this->assertSame('# existing', file_get_contents($pipelinePath));
  }

  /**
   * Tests that --force overwrites existing CI files.
   */
  public function testScaffoldCiOverwritesWithForce(): void {
    $this->createGrumphpStub();
    $pipelinePath = $this->tmpDir . '/.github/workflows/ci.yml';
    mkdir(dirname($pipelinePath), 0755, TRUE);
    file_put_contents($pipelinePath, '# existing');

    $this->tester->run([
      'command'  => 'suds:scaffold:ci',
      'provider' => 'github',
      '--force'  => TRUE,
    ]);

    $this->assertNotSame('# existing', file_get_contents($pipelinePath));
  }

  /**
   * Tests that output confirms CI files were created.
   */
  public function testScaffoldCiOutputMentionsCreatedFiles(): void {
    $this->createGrumphpStub();
    $this->tester->run([
      'command'  => 'suds:scaffold:ci',
      'provider' => 'github',
    ]);

    $display = $this->tester->getDisplay();
    $this->assertStringContainsString('.github/workflows/ci.yml', $display);
    $this->assertStringContainsString('suds.ci.yml', $display);
  }

  /**
   * Tests that output notes when all CI files already exist.
   */
  public function testScaffoldCiOutputsNoteWhenAllExist(): void {
    $this->createGrumphpStub();
    $pipelinePath = $this->tmpDir . '/.github/workflows/ci.yml';
    mkdir(dirname($pipelinePath), 0755, TRUE);
    file_put_contents($pipelinePath, '# existing');
    file_put_contents($this->tmpDir . '/suds.ci.yml', '# existing');

    $this->tester->run([
      'command'  => 'suds:scaffold:ci',
      'provider' => 'github',
    ]);

    $this->assertStringContainsString(
      'No files were written',
      $this->tester->getDisplay(),
    );
  }

  /**
   * Data provider: CI provider identifier and expected target path.
   *
   * @return array<string, array{string, string}>
   *   Keyed by provider name; values are [provider, expectedPath].
   */
  public static function ciProviderProvider(): array {
    return [
      'github'    => ['github', '.github/workflows/ci.yml'],
      'gitlab'    => ['gitlab', '.gitlab-ci.yml'],
      'bitbucket' => ['bitbucket', 'bitbucket-pipelines.yml'],
    ];
  }

}

/**
 * Testable subclass of ScaffoldCommands with an injectable target directory.
 *
 * Overrides getTargetDir() so integration tests can write scaffolded files to
 * a temporary directory without chdir().
 */
class TestableScaffoldCommands extends ScaffoldCommands {

  /**
   * The directory to use as the target for file operations.
   *
   * @var string
   */
  private string $targetDir;

  /**
   * Constructs a TestableScaffoldCommands instance.
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

}
