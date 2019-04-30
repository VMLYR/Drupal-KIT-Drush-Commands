<?php

namespace Drush\Commands\kit_drush_commands\Util;

/**
 * Inflection trait for getting site environments.
 */
trait EnvironmentsTrait {

  /**
   * Gets the alias' site.
   *
   * @param string $alias
   *   The Drush alias.
   *
   * @return string
   *   The site of the alias.
   */
  protected function getDrushAliasSite($alias) {
    return explode('.', str_replace('@', '', $alias), 2)[0];
  }

  /**
   * A list of all available site keys.
   *
   * @return string[]
   *   An array of site keys.
   */
  protected function getSites() {
    return array_unique(array_map(function ($key) {
      return $this->getDrushAliasSite($key);
    }, array_keys($this->siteAliasManager()->getMultiple())));
  }

  /**
   * Get a site's environment keys from its Drush aliases.
   *
   * @param string $site
   *   The site key.
   *
   * @return string[]
   *   An array of a site's environment options.
   */
  protected function getSiteEnvironments($site) {
    $aliases = $this->siteAliasManager()->getMultiple("@{$site}");

    $environments = array_map(function ($alias) {
      return explode('.', $alias->name(), 2)[1];
    }, $aliases);

    return $environments;
  }
}
