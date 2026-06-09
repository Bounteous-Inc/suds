<?php

declare(strict_types=1);

namespace Bounteous\Suds\Tests\Support;

use Consolidation\AnnotatedCommand\AnnotatedCommandFactory;
use Consolidation\SiteProcess\ProcessBase;
use Drush\Commands\DrushCommands;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Base class for integration tests that invoke commands via Symfony Console.
 *
 * Commands are registered through AnnotatedCommandFactory and exercised via
 * ApplicationTester — no subprocess is spawned and no Drupal bootstrap is
 * required. Extends TestCase so subclasses remain standard PHPUnit tests.
 */
abstract class IntegrationTestCase extends TestCase {

  use TempDirectoryTrait;

  /**
   * Wires a command instance into a Symfony Application and returns a tester.
   *
   * Sets input and output on the command instance (required by DrushCommands)
   * then registers all commands discovered from the class via
   * AnnotatedCommandFactory.
   *
   * Note: setCatchExceptions(FALSE) prevents the Symfony Application from
   * swallowing exceptions thrown outside of command execution (e.g. during
   * command registration). However, exceptions thrown *inside* a command
   * method are caught by AnnotatedCommand::execute() and converted to a
   * non-zero exit code — they do NOT propagate to PHPUnit. Use
   * assertNotSame(0, $exitCode) and assertStringContainsString() on
   * getDisplay() to assert error conditions in integration tests.
   *
   * @param \Drush\Commands\DrushCommands $commandInstance
   *   The Drush command class instance to register.
   *
   * @return \Symfony\Component\Console\Tester\ApplicationTester
   *   A tester for the application containing the registered commands.
   */
  protected function buildTester(DrushCommands $commandInstance): ApplicationTester {
    $commandInstance->setInput(new ArrayInput([]));
    $commandInstance->setOutput(new NullOutput());

    $app = new Application('suds-test', '1.0.0');
    $app->setAutoExit(FALSE);
    $app->setCatchExceptions(FALSE);

    $factory = new AnnotatedCommandFactory();
    foreach ($factory->createCommandsFromClass($commandInstance) as $command) {
      $app->addCommand($command);
    }

    return new ApplicationTester($app);
  }

  /**
   * Builds a reusable mock ProcessBase suitable for use as a drush() return.
   *
   * MustRun() returns $this so it can be chained; showRealtime() returns a
   * no-op closure. Both are the minimum required to satisfy the command
   * implementations without spawning real subprocesses.
   *
   * @return \Consolidation\SiteProcess\ProcessBase
   *   A mock ProcessBase instance.
   */
  protected function buildMockProcess(): ProcessBase {
    $mock = $this->getMockBuilder(ProcessBase::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['mustRun', 'showRealtime'])
      ->getMock();
    $mock->method('showRealtime')->willReturn(static function (): void {});
    $mock->method('mustRun')->willReturn($mock);
    return $mock;
  }

}
