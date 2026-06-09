<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Unit\Commands;

use Bounteous\Suds\Config\ConfigLoaderInterface;
use Bounteous\Suds\Drush\Commands\ScaffoldCommands;
use Bounteous\Suds\Tests\Support\TempDirectoryTrait;
use Drush\Log\DrushLoggerManager;
use Drush\Style\DrushStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ScaffoldCommands.
 */
#[CoversClass(ScaffoldCommands::class)]
class ScaffoldCommandsTest extends TestCase {

  use TempDirectoryTrait;

  /**
   * Temporary directory for each test.
   */
  private string $dir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->dir = $this->createTempDir('suds_scaffold_');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeDirectory($this->dir);
    parent::tearDown();
  }

  /**
   * Verifies scaffoldQuality() creates all three config files.
   */
  public function testScaffoldQualityCreatesAllThreeFiles(): void {
    $this->buildCommand()->scaffoldQuality(
      ['drupal-root' => 'web', 'force' => FALSE],
    );

    $this->assertFileExists($this->dir . '/grumphp.yml');
    $this->assertFileExists($this->dir . '/phpcs.xml.dist');
    $this->assertFileExists($this->dir . '/phpstan.neon');
  }

  /**
   * Verifies the drupal_root token is replaced in all scaffolded files.
   */
  public function testScaffoldQualitySubstitutesDrupalRootToken(): void {
    $this->buildCommand()->scaffoldQuality(
      ['drupal-root' => 'docroot', 'force' => FALSE],
    );

    foreach (['grumphp.yml', 'phpcs.xml.dist', 'phpstan.neon'] as $file) {
      $contents = file_get_contents($this->dir . '/' . $file);
      $this->assertIsString($contents);
      $this->assertStringNotContainsString('{{ drupal_root }}', $contents, $file);
      $this->assertStringContainsString('docroot', $contents, $file);
    }
  }

  /**
   * Verifies scaffoldQuality() skips existing files without --force.
   */
  public function testScaffoldQualitySkipsExistingFilesWithoutForce(): void {
    file_put_contents($this->dir . '/grumphp.yml', '# existing');

    $this->buildCommand()->scaffoldQuality(
      ['drupal-root' => 'web', 'force' => FALSE],
    );

    $this->assertSame('# existing', file_get_contents($this->dir . '/grumphp.yml'));
  }

  /**
   * Verifies scaffoldQuality() overwrites existing files with --force.
   */
  public function testScaffoldQualityOverwritesWithForce(): void {
    file_put_contents($this->dir . '/grumphp.yml', '# existing');

    $this->buildCommand()->scaffoldQuality(
      ['drupal-root' => 'web', 'force' => TRUE],
    );

    $this->assertNotSame('# existing', file_get_contents($this->dir . '/grumphp.yml'));
  }

  /**
   * Verifies scaffoldQuality() reads drupal.root from config when unset.
   */
  public function testScaffoldQualityReadsDrupalRootFromConfig(): void {
    $loader = $this->createMock(ConfigLoaderInterface::class);
    $loader->method('load')->willReturn(['drupal' => ['root' => 'html']]);

    $command = $this->buildCommand($loader);
    $command->scaffoldQuality(['drupal-root' => '', 'force' => FALSE]);

    foreach (['grumphp.yml', 'phpcs.xml.dist', 'phpstan.neon'] as $file) {
      $contents = file_get_contents($this->dir . '/' . $file);
      $this->assertIsString($contents);
      $this->assertStringContainsString('html', $contents, $file);
    }
  }

  /**
   * Verifies --drupal-root option takes priority over config.
   */
  public function testDrupalRootOptionOverridesConfig(): void {
    $this->buildCommand()->scaffoldQuality(
      ['drupal-root' => 'docroot', 'force' => FALSE],
    );

    $contents = file_get_contents($this->dir . '/phpcs.xml.dist');
    $this->assertIsString($contents);
    $this->assertStringContainsString('docroot', $contents);
    $this->assertStringNotContainsString('web', $contents);
  }

  /**
   * Verifies scaffoldQuality() outputs a "Created" line for each new file.
   */
  public function testScaffoldQualityOutputsCreatedForEachFile(): void {
    $io = $this->createMock(DrushStyle::class);
    $texts = [];
    $io->method('text')->willReturnCallback(
      static function (mixed $lines) use (&$texts): void {
        foreach ((array) $lines as $line) {
          $texts[] = $line;
        }
      }
    );

    $command = $this->buildCommand(io: $io);
    $command->scaffoldQuality(['drupal-root' => 'web', 'force' => FALSE]);

    $combined = implode("\n", $texts);
    $this->assertStringContainsString('grumphp.yml', $combined);
    $this->assertStringContainsString('phpcs.xml.dist', $combined);
    $this->assertStringContainsString('phpstan.neon', $combined);
  }

  /**
   * Verifies scaffoldQuality() emits a note when all files already exist.
   */
  public function testScaffoldQualityOutputsNoteWhenAllFilesExist(): void {
    file_put_contents($this->dir . '/grumphp.yml', '# existing');
    file_put_contents($this->dir . '/phpcs.xml.dist', '<!-- existing -->');
    file_put_contents($this->dir . '/phpstan.neon', '# existing');

    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->once())->method('note')
      ->with($this->stringContains('No files were written'));

    $command = $this->buildCommand(io: $io);
    $command->scaffoldQuality(['drupal-root' => 'web', 'force' => FALSE]);
  }

  /**
   * Verifies scaffoldCi() throws for an unrecognised provider.
   */
  public function testScaffoldCiThrowsForUnknownProvider(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/Unknown CI provider "azure"/');

    $this->buildCommand()->scaffoldCi('azure', ['force' => FALSE]);
  }

  /**
   * Verifies scaffoldCi() creates the pipeline file for each provider.
   *
   * @param string $provider
 *   The CI provider identifier.
   * @param string $expectedPath
 *   Path relative to project root.
   */
  #[DataProvider('ciProviderProvider')]
  public function testScaffoldCiCreatesPipelineFile(
    string $provider,
    string $expectedPath,
  ): void {
    $this->buildCommand()->scaffoldCi($provider, ['force' => FALSE]);

    $this->assertFileExists($this->dir . '/' . $expectedPath);
  }

  /**
   * Verifies scaffoldCi() always creates suds.ci.yml.
   */
  #[DataProvider('ciProviderProvider')]
  public function testScaffoldCiCreatesSudsCiYml(
    string $provider,
  ): void {
    $this->buildCommand()->scaffoldCi($provider, ['force' => FALSE]);

    $this->assertFileExists($this->dir . '/suds.ci.yml');
  }

  /**
   * Verifies the php_version token is substituted in the pipeline file.
   */
  #[DataProvider('ciProviderProvider')]
  public function testScaffoldCiSubstitutesPhpVersionToken(
    string $provider,
    string $expectedPath,
  ): void {
    $this->buildCommand()->scaffoldCi($provider, ['force' => FALSE]);

    $contents = file_get_contents($this->dir . '/' . $expectedPath);
    $this->assertIsString($contents);
    $this->assertStringNotContainsString('{{ php_version }}', $contents, $provider);
    $this->assertStringContainsString('8.3', $contents, $provider);
  }

  /**
   * Verifies scaffoldCi() reads the PHP version from composer.json.
   */
  public function testScaffoldCiDetectsPhpVersionFromComposerJson(): void {
    file_put_contents($this->dir . '/composer.json', json_encode([
      'require' => ['php' => '>=8.2'],
    ]));

    $this->buildCommand()->scaffoldCi('github', ['force' => FALSE]);

    $contents = file_get_contents(
      $this->dir . '/.github/workflows/ci.yml',
    );
    $this->assertIsString($contents);
    $this->assertStringContainsString('8.2', $contents);
    $this->assertStringNotContainsString('{{ php_version }}', $contents);
  }

  /**
   * Verifies config.platform.php takes priority over require.php.
   */
  public function testScaffoldCiPrefersConfigPlatformPhp(): void {
    file_put_contents($this->dir . '/composer.json', json_encode([
      'config'  => ['platform' => ['php' => '8.1.0']],
      'require' => ['php' => '>=8.3'],
    ]));

    $this->buildCommand()->scaffoldCi('github', ['force' => FALSE]);

    $contents = file_get_contents(
      $this->dir . '/.github/workflows/ci.yml',
    );
    $this->assertIsString($contents);
    $this->assertStringContainsString('8.1', $contents);
    $this->assertStringNotContainsString('8.3', $contents);
  }

  /**
   * Verifies scaffoldCi() runs scaffoldQuality() when user confirms the prompt.
   */
  public function testScaffoldCiRunsQualityScaffoldWhenUserConfirms(): void {
    $io = $this->createMock(DrushStyle::class);
    $io->method('confirm')->willReturn(TRUE);

    $command = $this->getMockBuilder(ScaffoldCommands::class)
      ->onlyMethods(['getTargetDir', 'io', 'scaffoldQuality'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);
    $command->method('io')->willReturn($io);
    $command->expects($this->once())->method('scaffoldQuality');

    $command->scaffoldCi('github', ['force' => FALSE]);
  }

  /**
   * Verifies scaffoldCi() logs a warning when user declines the quality prompt.
   */
  public function testScaffoldCiLogsWarningWhenUserDeclinesQualityScaffold(): void {
    $io = $this->createMock(DrushStyle::class);
    $io->method('confirm')->willReturn(FALSE);

    $mockLogger = $this->getMockBuilder(DrushLoggerManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['warning'])
      ->getMock();
    $mockLogger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('grumphp.yml not found'));

    $command = $this->getMockBuilder(ScaffoldCommands::class)
      ->onlyMethods(['getTargetDir', 'io', 'logger'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);
    $command->method('io')->willReturn($io);
    $command->method('logger')->willReturn($mockLogger);

    $command->scaffoldCi('github', ['force' => FALSE]);
  }

  /**
   * Verifies scaffoldCi() does not prompt when grumphp.yml already exists.
   */
  public function testScaffoldCiDoesNotPromptWhenGrumphpPresent(): void {
    file_put_contents($this->dir . '/grumphp.yml', 'grumphp: {}');

    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->never())->method('confirm');

    $command = $this->getMockBuilder(ScaffoldCommands::class)
      ->onlyMethods(['getTargetDir', 'io'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);
    $command->method('io')->willReturn($io);

    $command->scaffoldCi('github', ['force' => FALSE]);
  }

  /**
   * Verifies scaffoldCi() skips existing files without --force.
   */
  public function testScaffoldCiSkipsExistingFilesWithoutForce(): void {
    $pipelinePath = $this->dir . '/.github/workflows/ci.yml';
    mkdir(dirname($pipelinePath), 0755, TRUE);
    file_put_contents($pipelinePath, '# existing');

    $this->buildCommand()->scaffoldCi('github', ['force' => FALSE]);

    $this->assertSame('# existing', file_get_contents($pipelinePath));
  }

  /**
   * Verifies scaffoldCi() overwrites existing files with --force.
   */
  public function testScaffoldCiOverwritesWithForce(): void {
    $pipelinePath = $this->dir . '/.github/workflows/ci.yml';
    mkdir(dirname($pipelinePath), 0755, TRUE);
    file_put_contents($pipelinePath, '# existing');

    $this->buildCommand()->scaffoldCi('github', ['force' => TRUE]);

    $this->assertNotSame('# existing', file_get_contents($pipelinePath));
  }

  /**
   * Verifies scaffoldCi() emits a note when all files already exist.
   */
  public function testScaffoldCiOutputsNoteWhenAllFilesExist(): void {
    $pipelinePath = $this->dir . '/.github/workflows/ci.yml';
    mkdir(dirname($pipelinePath), 0755, TRUE);
    file_put_contents($pipelinePath, '# existing');
    file_put_contents($this->dir . '/suds.ci.yml', '# existing');

    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->once())->method('note')
      ->with($this->stringContains('No files were written'));

    $command = $this->buildCommand(io: $io);
    $command->scaffoldCi('github', ['force' => FALSE]);
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

  /**
   * Builds a ScaffoldCommands instance with injectable collaborators.
   *
   * @param \Bounteous\Suds\Config\ConfigLoaderInterface|null $loader
   *   Optional config loader mock.
   * @param \Drush\Style\DrushStyle|null $io
   *   Optional IO mock.
   *
   * @return \Bounteous\Suds\Drush\Commands\ScaffoldCommands
   *   The command instance with getTargetDir() returning the temp directory.
   */
  private function buildCommand(
    ?ConfigLoaderInterface $loader = NULL,
    ?DrushStyle $io = NULL,
  ): ScaffoldCommands {
    $command = $this->getMockBuilder(ScaffoldCommands::class)
      ->onlyMethods(['getTargetDir', 'io'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);
    $command->method('io')->willReturn($io ?? $this->createMock(DrushStyle::class));

    if ($loader !== NULL) {
      $command->setConfigLoader($loader);
    }

    return $command;
  }

}
