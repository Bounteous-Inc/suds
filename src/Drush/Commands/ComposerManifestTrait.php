<?php

declare(strict_types=1);

namespace Bounteous\Suds\Drush\Commands;

/**
 * Reads a project's composer.json for commands that inspect project layout.
 *
 * Kept separate from ProcessHelperTrait so that commands which only need to
 * read the manifest do not also inherit the shell-out helpers.
 */
trait ComposerManifestTrait {

  /**
   * Reads and decodes a project's composer.json.
   *
   * @param string $projectRoot
   *   Absolute path to the directory containing composer.json.
   *
   * @return array<string, mixed>|null
   *   The decoded manifest, or NULL when it is absent, unreadable, or not
   *   valid JSON describing an object. A project without a readable manifest
   *   is a legitimate state for callers to handle rather than an error, since
   *   Composer itself reports a broken manifest far more clearly than we can.
   */
  protected function readComposerManifest(string $projectRoot): ?array {
    $path = $projectRoot . '/composer.json';
    if (!is_file($path)) {
      return NULL;
    }
    $raw = file_get_contents($path);
    if ($raw === FALSE) {
      return NULL;
    }
    $decoded = json_decode($raw, TRUE);
    return is_array($decoded) ? $decoded : NULL;
  }

}
