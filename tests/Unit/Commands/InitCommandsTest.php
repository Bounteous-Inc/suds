<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Unit\Commands;

use Bounteous\Suds\Drush\Commands\InitCommands;
use Bounteous\Suds\Tests\Support\TempDirectoryTrait;
use Drush\Style\DrushStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for InitCommands.
 */
#[CoversClass(InitCommands::class)]
class InitCommandsTest extends TestCase {

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
    $this->dir = $this->createTempDir('suds_init_');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeDirectory($this->dir);
    parent::tearDown();
  }

  /**
   * Verifies init() throws when --name is whitespace-only.
   */
  public function testInitThrowsOnWhitespaceOnlyName(): void {
    $command = $this->getMockBuilder(InitCommands::class)
      ->onlyMethods(['getTargetDir'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/whitespace/');

    $command->init([
      'name'         => '   ',
      'drupal-root'  => 'web',
      'skip-quality' => TRUE,
      'skip-ci'      => TRUE,
      'ci-provider'  => '',
    ]);
  }

  /**
   * Verifies init() calls ask() without a validator argument.
   *
   * DrushStyle throws an AssertionError when a $validator is passed.
   */
  public function testInitAsksForNameWithoutValidator(): void {
    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->once())
      ->method('ask')
      ->with('Project name')
      ->willReturn('Interactive Project');

    $command = $this->getMockBuilder(InitCommands::class)
      ->onlyMethods(['getTargetDir', 'io'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);
    $command->method('io')->willReturn($io);

    $command->init([
      'name'         => '',
      'drupal-root'  => 'web',
      'skip-quality' => TRUE,
      'skip-ci'      => TRUE,
      'ci-provider'  => '',
    ]);

    $contents = file_get_contents($this->dir . '/suds.yml');
    $this->assertIsString($contents);
    $this->assertStringContainsString('name: Interactive Project', $contents);
  }

  /**
   * Verifies init() throws when ask() returns an empty string interactively.
   */
  public function testInitThrowsWhenInteractiveNameIsEmpty(): void {
    $io = $this->createMock(DrushStyle::class);
    $io->method('ask')->willReturn('');

    $command = $this->getMockBuilder(InitCommands::class)
      ->onlyMethods(['getTargetDir', 'io'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);
    $command->method('io')->willReturn($io);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/cannot be empty/');

    $command->init([
      'name'         => '',
      'drupal-root'  => 'web',
      'skip-quality' => TRUE,
      'skip-ci'      => TRUE,
      'ci-provider'  => '',
    ]);
  }

  /**
   * Verifies init() throws when suds.yml already exists in the target dir.
   */
  public function testInitThrowsWhenConfigFileAlreadyExists(): void {
    file_put_contents($this->dir . '/suds.yml', 'project: {name: existing}');

    $command = $this->getMockBuilder(InitCommands::class)
      ->onlyMethods(['getTargetDir'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/already exists/');

    $command->init();
  }

  /**
   * Verifies init() writes suds.yml containing the project name.
   */
  public function testInitWritesConfigFileWithProjectName(): void {
    $command = $this->getMockBuilder(InitCommands::class)
      ->onlyMethods(['getTargetDir', 'io'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));

    $command->init([
      'name'         => 'My Cool Project',
      'drupal-root'  => 'web',
      'skip-quality' => TRUE,
      'skip-ci'      => TRUE,
      'ci-provider'  => '',
    ]);

    $contents = file_get_contents($this->dir . '/suds.yml');
    $this->assertIsString($contents);
    $this->assertStringContainsString('name: My Cool Project', $contents);
  }

  /**
   * Verifies init() appends suds.local.yml to an existing .gitignore.
   */
  public function testInitAppendsToGitignore(): void {
    file_put_contents($this->dir . '/.gitignore', "/vendor\n");

    $command = $this->getMockBuilder(InitCommands::class)
      ->onlyMethods(['getTargetDir', 'io'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));

    $command->init([
      'name'         => 'Test Project',
      'drupal-root'  => 'web',
      'skip-quality' => TRUE,
      'skip-ci'      => TRUE,
      'ci-provider'  => '',
    ]);

    $gitignore = file_get_contents($this->dir . '/.gitignore');
    $this->assertIsString($gitignore);
    $this->assertStringContainsString('suds.local.yml', $gitignore);
  }

  /**
   * Verifies init() does not duplicate the gitignore entry if already present.
   */
  public function testInitDoesNotDuplicateGitignoreEntry(): void {
    file_put_contents($this->dir . '/.gitignore', "/vendor\nsuds.local.yml\n");

    $command = $this->getMockBuilder(InitCommands::class)
      ->onlyMethods(['getTargetDir', 'io'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));

    $command->init([
      'name'         => 'Test Project',
      'drupal-root'  => 'web',
      'skip-quality' => TRUE,
      'skip-ci'      => TRUE,
      'ci-provider'  => '',
    ]);

    $gitignore = file_get_contents($this->dir . '/.gitignore');
    $this->assertIsString($gitignore);
    $this->assertSame(1, substr_count($gitignore, 'suds.local.yml'));
  }

  /**
   * Verifies init() creates .gitignore with suds.local.yml when absent.
   */
  public function testInitCreatesGitignoreWhenAbsent(): void {
    $command = $this->getMockBuilder(InitCommands::class)
      ->onlyMethods(['getTargetDir', 'io'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));

    $command->init([
      'name'         => 'Test Project',
      'drupal-root'  => 'web',
      'skip-quality' => TRUE,
      'skip-ci'      => TRUE,
      'ci-provider'  => '',
    ]);

    $gitignore = file_get_contents($this->dir . '/.gitignore');
    $this->assertIsString($gitignore);
    $this->assertStringContainsString('suds.local.yml', $gitignore);
  }

  /**
   * Verifies init() emits a success message containing the project name.
   */
  public function testInitOutputsSuccessMessage(): void {
    $io = $this->createMock(DrushStyle::class);
    $io->expects($this->once())->method('success')
      ->with($this->stringContains('My Project'));

    $command = $this->getMockBuilder(InitCommands::class)
      ->onlyMethods(['getTargetDir', 'io'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);
    $command->method('io')->willReturn($io);

    $command->init([
      'name'         => 'My Project',
      'drupal-root'  => 'web',
      'skip-quality' => TRUE,
      'skip-ci'      => TRUE,
      'ci-provider'  => '',
    ]);
  }

  /**
   * Verifies generated suds.yml contains the drupal.root section.
   */
  public function testInitYmlContainsDrupalRoot(): void {
    $command = $this->getMockBuilder(InitCommands::class)
      ->onlyMethods(['getTargetDir', 'io'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));

    $command->init([
      'name'         => 'My Project',
      'drupal-root'  => 'docroot',
      'skip-quality' => TRUE,
      'skip-ci'      => TRUE,
      'ci-provider'  => '',
    ]);

    $contents = file_get_contents($this->dir . '/suds.yml');
    $this->assertIsString($contents);
    $this->assertStringContainsString('drupal:', $contents);
    $this->assertStringContainsString('root: docroot', $contents);
  }

  /**
   * Verifies detectDrupalRoot() returns the name when exactly one dir exists.
   */
  public function testDetectDrupalRootReturnsSingleCandidate(): void {
    mkdir($this->dir . '/web', 0755, TRUE);
    $method = new \ReflectionMethod(InitCommands::class, 'detectDrupalRoot');
    $this->assertSame('web', $method->invoke(new InitCommands(), $this->dir));
  }

  /**
   * Verifies detectDrupalRoot() returns null when no candidate dirs exist.
   */
  public function testDetectDrupalRootReturnsNullWhenNoneFound(): void {
    $method = new \ReflectionMethod(InitCommands::class, 'detectDrupalRoot');
    $this->assertNull($method->invoke(new InitCommands(), $this->dir));
  }

  /**
   * Verifies detectDrupalRoot() returns null when multiple candidates exist.
   */
  public function testDetectDrupalRootReturnsNullWhenMultipleFound(): void {
    mkdir($this->dir . '/web', 0755, TRUE);
    mkdir($this->dir . '/docroot', 0755, TRUE);
    $method = new \ReflectionMethod(InitCommands::class, 'detectDrupalRoot');
    $this->assertNull($method->invoke(new InitCommands(), $this->dir));
  }

  /**
   * Verifies init() calls dispatchQualityScaffold() when not skipped.
   */
  public function testInitCallsDispatchQualityScaffoldByDefault(): void {
    $command = $this->getMockBuilder(InitCommands::class)
      ->onlyMethods(['getTargetDir', 'io', 'dispatchQualityScaffold'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->expects($this->once())->method('dispatchQualityScaffold');

    $command->init([
      'name'         => 'My Project',
      'drupal-root'  => 'web',
      'skip-quality' => FALSE,
      'skip-ci'      => TRUE,
      'ci-provider'  => '',
    ]);
  }

  /**
   * Verifies init() does not call dispatchQualityScaffold() when skipped.
   */
  public function testInitSkipsDispatchWhenFlagSet(): void {
    $command = $this->getMockBuilder(InitCommands::class)
      ->onlyMethods(['getTargetDir', 'io', 'dispatchQualityScaffold'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->expects($this->never())->method('dispatchQualityScaffold');

    $command->init([
      'name'         => 'My Project',
      'drupal-root'  => 'web',
      'skip-quality' => TRUE,
      'skip-ci'      => TRUE,
      'ci-provider'  => '',
    ]);
  }

  /**
   * Verifies init() notes and uses the detected webroot automatically.
   */
  public function testInitNotesAndUsesDetectedDrupalRoot(): void {
    mkdir($this->dir . '/docroot', 0755, TRUE);
    file_put_contents($this->dir . '/.gitignore', "/vendor\n");

    $notes = [];
    $io = $this->createMock(DrushStyle::class);
    $io->method('note')->willReturnCallback(
      static function (string $msg) use (&$notes): void {
        $notes[] = $msg;
      },
    );

    $command = $this->getMockBuilder(InitCommands::class)
      ->onlyMethods(['getTargetDir', 'io'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);
    $command->method('io')->willReturn($io);

    $command->init([
      'name'         => 'My Project',
      'drupal-root'  => '',
      'skip-quality' => TRUE,
      'skip-ci'      => TRUE,
      'ci-provider'  => '',
    ]);

    $rootNotes = array_filter($notes, static fn(string $n) => str_contains($n, 'docroot'));
    $this->assertNotEmpty($rootNotes, 'Expected a note about the detected docroot.');

    $contents = file_get_contents($this->dir . '/suds.yml');
    $this->assertIsString($contents);
    $this->assertStringContainsString('root: docroot', $contents);
  }

  /**
   * Verifies init() calls dispatchCiScaffold() with the given provider.
   */
  public function testInitDispatchesCiScaffoldWhenProviderSet(): void {
    $command = $this->getMockBuilder(InitCommands::class)
      ->onlyMethods(['getTargetDir', 'io', 'dispatchQualityScaffold', 'dispatchCiScaffold'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->expects($this->once())->method('dispatchCiScaffold')->with('github');

    $command->init([
      'name'         => 'My Project',
      'drupal-root'  => 'web',
      'skip-quality' => TRUE,
      'skip-ci'      => FALSE,
      'ci-provider'  => 'github',
    ]);
  }

  /**
   * Verifies init() skips dispatchCiScaffold() when --skip-ci is set.
   */
  public function testInitSkipsCiScaffoldWhenSkipCiSet(): void {
    $command = $this->getMockBuilder(InitCommands::class)
      ->onlyMethods(['getTargetDir', 'io', 'dispatchCiScaffold'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);
    $command->method('io')->willReturn($this->createMock(DrushStyle::class));
    $command->expects($this->never())->method('dispatchCiScaffold');

    $command->init([
      'name'         => 'My Project',
      'drupal-root'  => 'web',
      'skip-quality' => TRUE,
      'skip-ci'      => TRUE,
      'ci-provider'  => '',
    ]);
  }

  /**
   * Verifies init() throws when --skip-ci and --ci-provider are both set.
   */
  public function testInitThrowsWhenSkipCiAndCiProviderBothSet(): void {
    $command = $this->getMockBuilder(InitCommands::class)
      ->onlyMethods(['getTargetDir'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/mutually exclusive/');

    $command->init([
      'name'         => 'My Project',
      'drupal-root'  => 'web',
      'skip-quality' => TRUE,
      'skip-ci'      => TRUE,
      'ci-provider'  => 'github',
    ]);
  }

  /**
   * Verifies init() prompts for CI provider and dispatches on user selection.
   */
  public function testInitPromptsCiProviderInteractively(): void {
    $io = $this->createMock(DrushStyle::class);
    $io->method('choice')->willReturn('gitlab');

    $command = $this->getMockBuilder(InitCommands::class)
      ->onlyMethods(['getTargetDir', 'io', 'dispatchQualityScaffold', 'dispatchCiScaffold'])
      ->getMock();
    $command->method('getTargetDir')->willReturn($this->dir);
    $command->method('io')->willReturn($io);
    $command->expects($this->once())->method('dispatchCiScaffold')->with('gitlab');

    $command->init([
      'name'         => 'My Project',
      'drupal-root'  => 'web',
      'skip-quality' => TRUE,
      'skip-ci'      => FALSE,
      'ci-provider'  => '',
    ]);
  }

}
