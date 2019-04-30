<?php

namespace Drush\Commands\kit_drush_commands\Util;

/**
 * Inflection trait for figuring out if a module is enabled.
 */
trait EnabledModulesTrait {

  /**
   * Checks if a module is installed.
   *
   * @param string $alias
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
   * @param string $alias
   *   The Drush alias.
   *
   * @return mixed
   *   An array of enabled module IDs.
   */
  public function getEnabledModules($alias) {
    $command_options = [
      '--status=enabled',
    ];
    $backend_options = [
      'log' => FALSE,
      'output' => FALSE,
    ];
    $response = drush_invoke_process($alias, 'pm-list', [], $command_options, $backend_options);
    if ($response) {
      return $response['object'];
    }

    return [];
  }
}
