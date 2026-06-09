<?php

declare(strict_types=1);

namespace Bounteous\Suds\Config;

/**
 * Provides lazy ConfigLoader injection for Drush command classes.
 *
 * Classes using this trait gain a public setter for testing and DI, and a
 * private accessor that falls back to ConfigLoader::createFromCwd() on first
 * use. The pattern keeps commands decoupled from the concrete ConfigLoader
 * while avoiding the need for a full DI container at runtime.
 */
trait ConfigLoaderAwareTrait {

  /**
   * The configuration loader instance.
   *
   * @var \Bounteous\Suds\Config\ConfigLoaderInterface|null
   */
  private ?ConfigLoaderInterface $configLoader = NULL;

  /**
   * Injects a ConfigLoaderInterface instance (primarily for testing).
   *
   * @param \Bounteous\Suds\Config\ConfigLoaderInterface $configLoader
   *   The configuration loader to use.
   */
  public function setConfigLoader(ConfigLoaderInterface $configLoader): void {
    $this->configLoader = $configLoader;
  }

  /**
   * Emits a logger warning for each unknown key in user config files.
   *
   * Calls getUnknownKeys() on the config loader and logs one warning per
   * unrecognised key. Silently returns when no project config is present.
   */
  protected function warnUnknownConfigKeys(): void {
    $loader = $this->configLoader();
    if (!$loader->hasProjectConfig()) {
      return;
    }
    foreach ($loader->getUnknownKeys() as $entry) {
      $this->logger()?->warning(sprintf(
        'Unknown config key "%s" in %s - check for a typo.',
        $entry['key'],
        $entry['file'],
      ));
    }
  }

  /**
   * Emits a logger warning for each config value with a wrong PHP type.
   *
   * Calls getTypeErrors() on the config loader and logs one warning per
   * type mismatch. Silently returns when no project config is present.
   */
  protected function warnTypeErrors(): void {
    $loader = $this->configLoader();
    if (!$loader->hasProjectConfig()) {
      return;
    }
    foreach ($loader->getTypeErrors() as $entry) {
      $this->logger()?->warning(sprintf(
        'Config key "%s" in %s: expected %s, got %s.',
        $entry['key'],
        $entry['file'],
        $entry['expected'],
        $entry['actual'],
      ));
    }
  }

  /**
   * Emits logger warnings for all config issues: unknown keys and type errors.
   *
   * Call this at the start of each command entry point as a pre-flight check.
   */
  protected function warnConfigIssues(): void {
    $this->warnUnknownConfigKeys();
    $this->warnTypeErrors();
  }

  /**
   * Returns the ConfigLoader, creating it from CWD if not already set.
   *
   * @return \Bounteous\Suds\Config\ConfigLoaderInterface
   *   The configuration loader.
   */
  private function configLoader(): ConfigLoaderInterface {
    if ($this->configLoader === NULL) {
      $this->configLoader = ConfigLoader::createFromCwd();
    }
    return $this->configLoader;
  }

}
