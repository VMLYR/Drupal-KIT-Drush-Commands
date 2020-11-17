<?php

namespace Drush\Commands\kit_drush_commands\Util;

use Drush\Drush;

/**
 * Inflection trait for figuring out if a module is enabled.
 */
trait EnabledModulesTrait {

  /**
   * Checks if a module is installed.
   *
   * @param \Consolidation\SiteAlias\SiteAlias $alias
   *   The Drush alias.
   * @param string $module
   *   The module name.
   *
   * @return bool
   *   True if module is enabled. False otherwise.
   */
  protected function checkEnabledModule($alias, $module) {
    return array_key_exists($module, $this->getEnabledModules($alias));
  }

  /**
   * Get enabled modules from a specific alias.
   *
   * @param \Consolidation\SiteAlias\SiteAlias $alias1
   *   The Drush alias.
   *
   * @return mixed
   *   An array of enabled module IDs.
   */
  public function getEnabledModules($alias) {
    $command_options = [
      'status' => 'enabled',
      'format' => 'json',
    ];

    $process = Drush::drush($alias, 'pm:list', [], $command_options);
    $success = $process->run(null, $alias->get('envs'));
    if ($success === 0) {
      return $process->getOutputAsJson();
    }

    return [];
  }
}
