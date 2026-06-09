<?php

declare(strict_types=1);

namespace Bounteous\Suds\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads and merges SUDS configuration.
 *
 * Merge chain (each layer replaces conflicting keys from the previous):
 *   config/suds.defaults.yml  (shipped with SUDS)
 *   → suds.yml                (project config, committed)
 *   → suds.ci.yml             (CI overrides; loaded when $CI env var is set)
 *   → suds.local.yml          (local overrides, gitignored)
 *
 * Merge semantics:
 *   - Associative arrays are merged recursively, so a partial override (e.g.
 *     overriding only drupal.root) preserves all other keys in that section.
 *   - Indexed (list) arrays replace the default entirely. Set a list to []
 *     to opt out of SUDS's defaults for that key.
 */
class ConfigLoader implements ConfigLoaderInterface {

  /**
   * Cached defaults array, populated on first call to getDefaults().
   *
   * @var array<string, mixed>|null
   */
  private ?array $defaults = NULL;

  /**
   * Cached parsed user config files, keyed by filename.
   *
   * Populated on first call to getUserConfigs() so that getUnknownKeys() and
   * getTypeErrors() share a single parse pass over suds.yml et al.
   *
   * @var array<string, array<string, mixed>>|null
   */
  private ?array $userConfigs = NULL;

  /**
   * Constructs a ConfigLoader.
   *
   * @param string $projectRoot
   *   Absolute path to the project root (directory containing suds.yml).
   * @param string $packageRoot
   *   Absolute path to the SUDS package root (contains config/).
   */
  public function __construct(
    private readonly string $projectRoot,
    private readonly string $packageRoot,
  ) {}

  /**
   * Creates a ConfigLoader by discovering the project root from the CWD.
   *
   * Walks up the directory tree from getcwd() looking for the nearest
   * suds.yml, identical to how Composer locates composer.json. Falls
   * back to getcwd() itself if no suds.yml is found in any ancestor.
   *
   * @throws \RuntimeException
   *   If the current working directory cannot be determined.
   */
  public static function createFromCwd(): self {
    $cwd = getcwd();
    if ($cwd === FALSE) {
      throw new \RuntimeException(
        'Unable to determine the current working directory.'
      );
    }
    // __DIR__        = src/Config
    // dirname($, 2)  = <package root> (contains composer.json, config/)
    return new self(
      self::findProjectRoot($cwd),
      dirname(__DIR__, 2),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaults(): array {
    if ($this->defaults === NULL) {
      $this->defaults = Yaml::parseFile(
        $this->packageRoot . '/config/suds.defaults.yml'
      ) ?? [];
    }
    return $this->defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function load(): array {
    $config = $this->getDefaults();
    $config = $this->mergeFile($config, $this->projectRoot . '/suds.yml');
    if (getenv('CI') !== FALSE && getenv('CI') !== '') {
      $config = $this->mergeFile($config, $this->projectRoot . '/suds.ci.yml');
    }
    return $this->mergeFile($config, $this->projectRoot . '/suds.local.yml');
  }

  /**
   * {@inheritdoc}
   */
  public function hasProjectConfig(): bool {
    return file_exists($this->projectRoot . '/suds.yml');
  }

  /**
   * {@inheritdoc}
   */
  public function getUnknownKeys(): array {
    $knownKeys = $this->flattenKeys($this->getDefaults());
    $unknown   = [];

    foreach ($this->getUserConfigs() as $filename => $userConfig) {
      foreach ($this->flattenKeys($userConfig) as $key) {
        if (!in_array($key, $knownKeys, TRUE)) {
          $unknown[] = ['file' => $filename, 'key' => $key];
        }
      }
    }

    return $unknown;
  }

  /**
   * {@inheritdoc}
   */
  public function getTypeErrors(): array {
    $defaults = $this->getDefaults();
    $errors   = [];

    foreach ($this->getUserConfigs() as $filename => $userConfig) {
      $errors = array_merge($errors, $this->collectTypeErrors($defaults, $userConfig, '', $filename));
    }

    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function getProjectRoot(): string {
    return $this->projectRoot;
  }

  /**
   * Recursively finds type mismatches between defaults and a user config array.
   *
   * @param array<string, mixed> $defaults
   *   The defaults (or sub-defaults) to walk.
   * @param array<string, mixed> $userConfig
   *   The user config (or sub-config) to compare against.
   * @param string $prefix
   *   Dot-notation prefix accumulated from parent levels.
   * @param string $filename
   *   The config filename (used in error entries).
   *
   * @return array<int, array{file: string, key: string, expected: string, actual: string}>
   *   Type error entries for this level and all descendants.
   */
  private function collectTypeErrors(array $defaults, array $userConfig, string $prefix, string $filename): array {
    $errors = [];
    foreach ($defaults as $key => $defaultValue) {
      $fullKey = $prefix !== '' ? $prefix . '.' . $key : (string) $key;
      if (!array_key_exists($key, $userConfig)) {
        continue;
      }
      $userValue = $userConfig[$key];

      // Null defaults cannot be type-checked.
      if ($defaultValue === NULL) {
        continue;
      }

      // Assoc array default: recurse into it, or report if user gave a scalar.
      if (is_array($defaultValue) && !array_is_list($defaultValue)) {
        if (is_array($userValue) && !array_is_list($userValue)) {
          $errors = array_merge(
            $errors,
            $this->collectTypeErrors($defaultValue, $userValue, $fullKey, $filename),
          );
        }
        elseif (!is_array($userValue)) {
          $errors[] = [
            'file'     => $filename,
            'key'      => $fullKey,
            'expected' => 'array',
            'actual'   => get_debug_type($userValue),
          ];
        }
        continue;
      }

      // List array default: check user value is also a list array.
      if (is_array($defaultValue) && array_is_list($defaultValue)) {
        if (!is_array($userValue) || !array_is_list($userValue)) {
          $errors[] = [
            'file'     => $filename,
            'key'      => $fullKey,
            'expected' => 'array',
            'actual'   => get_debug_type($userValue),
          ];
        }
        continue;
      }

      // Scalar default: compare PHP types.
      $expectedType = get_debug_type($defaultValue);
      $actualType   = get_debug_type($userValue);
      if ($expectedType !== $actualType) {
        $errors[] = ['file' => $filename, 'key' => $fullKey, 'expected' => $expectedType, 'actual' => $actualType];
      }
    }
    return $errors;
  }

  /**
   * Walks up from $startDir looking for a directory containing suds.yml.
   *
   * Returns the first ancestor directory that contains suds.yml, or
   * $startDir itself if no suds.yml is found in any ancestor.
   *
   * @param string $startDir
   *   The directory to start walking up from.
   *
   * @return string
   *   The resolved project root path.
   */
  private static function findProjectRoot(string $startDir): string {
    $dir = $startDir;
    while ($dir !== dirname($dir)) {
      if (file_exists($dir . '/suds.yml')) {
        return $dir;
      }
      $dir = dirname($dir);
    }
    return $startDir;
  }

  /**
   * Returns parsed user config files, keyed by filename, from a shared cache.
   *
   * Reads and parses suds.yml, suds.ci.yml, and suds.local.yml once
   * and caches the result so that getUnknownKeys() and getTypeErrors() share
   * a single disk read per file rather than each triggering their own.
   *
   * @return array<string, array<string, mixed>>
   *   Map of filename → parsed config array, containing only files that exist.
   */
  private function getUserConfigs(): array {
    if ($this->userConfigs === NULL) {
      $this->userConfigs = [];
      foreach (['suds.yml', 'suds.ci.yml', 'suds.local.yml'] as $filename) {
        $path = $this->projectRoot . '/' . $filename;
        if (file_exists($path)) {
          $this->userConfigs[$filename] = Yaml::parseFile($path) ?? [];
        }
      }
    }
    return $this->userConfigs;
  }

  /**
   * Loads a YAML file and merges it onto $base if the file exists.
   *
   * @param array<string, mixed> $base
   *   The base configuration array.
   * @param string $path
   *   Absolute path to the YAML file to merge.
   *
   * @return array<string, mixed>
   *   The merged configuration array.
   */
  private function mergeFile(array $base, string $path): array {
    if (!file_exists($path)) {
      return $base;
    }
    $override = Yaml::parseFile($path) ?? [];
    return $this->merge($base, $override);
  }

  /**
   * Returns a flat list of dot-notation key paths for an associative array.
   *
   * List values are treated as leaves so that items within lists such as
   * sync.db.truncate_tables are not reported as unknown keys.
   *
   * @param array<string, mixed> $config
   *   The configuration array to flatten.
   * @param string $prefix
   *   The dot-notation prefix accumulated from parent levels.
   *
   * @return list<string>
   *   Flat list of dot-notation key paths.
   */
  private function flattenKeys(array $config, string $prefix = ''): array {
    $keys = [];
    foreach ($config as $key => $value) {
      $fullKey = $prefix !== '' ? $prefix . '.' . $key : (string) $key;
      $keys[]  = $fullKey;
      if (is_array($value) && !array_is_list($value)) {
        $keys = array_merge($keys, $this->flattenKeys($value, $fullKey));
      }
    }
    return $keys;
  }

  /**
   * Deep merges $override onto $base.
   *
   * Associative arrays are merged recursively. Indexed (list) arrays and
   * scalar values replace the corresponding value in $base entirely.
   *
   * @param array<string, mixed> $base
   *   The base configuration array.
   * @param array<string, mixed> $override
   *   The override configuration array.
   *
   * @return array<string, mixed>
   *   The merged configuration array.
   */
  private function merge(array $base, array $override): array {
    foreach ($override as $key => $value) {
      if (
        is_array($value)
        && isset($base[$key])
        && is_array($base[$key])
        && !array_is_list($value)
        && !array_is_list($base[$key])
      ) {
        $base[$key] = $this->merge($base[$key], $value);
      }
      else {
        $base[$key] = $value;
      }
    }
    return $base;
  }

}
