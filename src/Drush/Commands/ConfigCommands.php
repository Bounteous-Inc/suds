<?php

declare(strict_types=1);

namespace Bounteous\Suds\Drush\Commands;

use Bounteous\Suds\Config\ConfigLoaderAwareTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\Yaml\Yaml;

/**
 * Drush commands for inspecting SUDS configuration.
 *
 * ConfigLoader is injected via setConfigLoader() for testing. When not
 * injected, a loader is created lazily from the process CWD on first use.
 * The process CWD is the shell directory where drush was invoked, which
 * allows suds.yml to be discovered via upward directory traversal even
 * when --root points elsewhere.
 */
class ConfigCommands extends DrushCommands {

  use ConfigLoaderAwareTrait;

  /**
   * Display the resolved project configuration or SUDS's built-in defaults.
   *
   * When KEY is provided, only the value at that dot-notation path is shown
   * (e.g. sync.db.export_dir). When omitted, the full configuration tree
   * is shown as YAML.
   *
   * @param string $key
   *   Optional dot-notation key to inspect (e.g. sync.db.export_dir).
   * @param array $options
   *   An associative array of options.
   *
   * @phpstan-param array{defaults: bool} $options
   *
   * @command suds:config:dump
   * @aliases su-config-dump
   * @bootstrap none
   * @argument key Dot-notation key to inspect (e.g. sync.db.export_dir).
   * @option defaults Show built-in default values only, ignoring suds.yml.
   * @usage drush suds:config:dump
   *   Show the resolved configuration for the current project.
   * @usage drush suds:config:dump sync.db.export_dir
   *   Show the value of a single configuration key.
   * @usage drush suds:config:dump --defaults
   *   Show SUDS's built-in default configuration values.
   */
  public function dump(
    string $key = '',
    array $options = ['defaults' => FALSE],
  ): void {
    $this->warnConfigIssues();
    $loader = $this->configLoader();
    if (!$options['defaults'] && !$loader->hasProjectConfig()) {
      $this->logger()?->notice(
        'No suds.yml found; output reflects defaults only.'
      );
    }
    $config = $options['defaults'] ? $loader->getDefaults() : $loader->load();
    if ($key !== '') {
      $value = $this->getNestedValue($config, $key);
      if (is_array($value)) {
        $this->io()->writeln(Yaml::dump($value, 4, 2));
      }
      else {
        $this->io()->writeln(rtrim(Yaml::dump($value)));
      }
      return;
    }
    $this->io()->writeln(Yaml::dump($config, 4, 2));
  }

  /**
   * Returns the value at a dot-notation path within a config array.
   *
   * @param array<string, mixed> $config
   *   The configuration array to search.
   * @param string $key
   *   Dot-notation path (e.g. sync.db.export_dir).
   *
   * @return mixed
   *   The value at the given path.
   *
   * @throws \InvalidArgumentException
   *   When the key path does not exist in the configuration.
   */
  private function getNestedValue(array $config, string $key): mixed {
    $parts   = explode('.', $key);
    $current = $config;
    foreach ($parts as $part) {
      if (!is_array($current) || !array_key_exists($part, $current)) {
        throw new \InvalidArgumentException(
          sprintf('Config key not found: %s', $key),
        );
      }
      $current = $current[$part];
    }
    return $current;
  }

}
