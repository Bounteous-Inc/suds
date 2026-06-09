<?php

declare(strict_types=1);

namespace Bounteous\Suds\Drush\Commands;

use Bounteous\Suds\Config\ConfigLoaderAwareTrait;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drush\Commands\DrushCommands;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;

/**
 * Drush commands for syncing files across environments.
 */
class FilesCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use SiteAliasManagerAwareTrait;
  use ConfigLoaderAwareTrait;
  use ProcessHelperTrait;

  /**
   * Sync files from a source environment alias.
   *
   * @param string $source
   *   The source environment alias (e.g. @prod).
   *
   * @command suds:files:sync
   * @aliases su-files-sync
   * @argument source The source environment alias (e.g. @prod).
   * @bootstrap none
   * @usage drush suds:files:sync @prod
   *   Rsync each path in sync.files.paths from @prod to @self.
   */
  public function filesSync(string $source): void {
    if ($source === '') {
      throw new \InvalidArgumentException('A source alias is required (e.g. @prod).');
    }

    $config = $this->configLoader()->load();
    $alias  = $this->siteAliasManager()->getSelf();

    $this->io()->title('SUDS: Syncing Files');
    $this->warnConfigIssues();

    foreach ($config['sync']['files']['paths'] as $path) {
      $this->io()->note(sprintf('Syncing path: %s', $path));
      $this->runDrushCommand(
        $alias,
        'rsync',
        [
          sprintf('%s:%%%s', $source, $path),
          sprintf('@self:%%%s', $path),
          '--',
          '--stats',
        ],
        $this->redispatchOptions(),
      );
    }

    $this->io()->success('Files synced.');
  }

}
