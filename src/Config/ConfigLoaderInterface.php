<?php

declare(strict_types=1);

namespace Bounteous\Suds\Config;

/**
 * Contract for loading and merging SUDS configuration.
 *
 * Implementations apply the SUDS merge chain and expose both the raw
 * defaults and the fully resolved project configuration.
 */
interface ConfigLoaderInterface {

  /**
   * Returns the raw defaults from config/suds.defaults.yml.
   *
   * @return array<string, mixed>
   *   The raw defaults array.
   */
  public function getDefaults(): array;

  /**
   * Returns the fully resolved configuration.
   *
   * Applies the full merge chain: defaults → suds.yml →
   * suds.ci.yml (when $CI is set) → suds.local.yml.
   *
   * @return array<string, mixed>
   *   The fully resolved configuration array.
   */
  public function load(): array;

  /**
   * Returns TRUE if a suds.yml project config file exists.
   *
   * Used to distinguish "no project config, showing defaults" from
   * "project config found and merged" when dumping configuration.
   */
  public function hasProjectConfig(): bool;

  /**
   * Returns unknown config keys found in user-editable config files.
   *
   * Compares suds.yml, suds.ci.yml, and suds.local.yml against the
   * defaults schema and returns one entry per unrecognised key.
   *
   * @return array<int, array{file: string, key: string}>
   *   Each entry has 'file' (filename) and 'key' (dot-notation path).
   */
  public function getUnknownKeys(): array;

  /**
   * Returns config value type errors found in user-editable config files.
   *
   * Compares suds.yml, suds.ci.yml, and suds.local.yml against the
   * defaults schema. For each key whose default is non-null, the PHP type of
   * the user value is checked against the default. Null defaults are skipped.
   * List arrays are treated as leaves and are not recursed into.
   *
   * @return array<int, array{file: string, key: string, expected: string, actual: string}>
   *   Each entry has 'file' (filename), 'key' (dot-notation path),
   *   'expected' (expected type), and 'actual' (actual type).
   */
  public function getTypeErrors(): array;

  /**
   * Returns the absolute path to the resolved project root directory.
   *
   * The project root is the directory containing suds.yml (or the CWD if
   * no suds.yml is found in the directory tree). Commands use this instead
   * of getcwd() so that the correct root is returned even when Drush is
   * invoked from a subdirectory of the project.
   *
   * @return string
   *   Absolute path to the project root, with no trailing separator.
   */
  public function getProjectRoot(): string;

}
