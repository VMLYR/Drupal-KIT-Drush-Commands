<?php

namespace Drush\Commands\kit_drush;

use Consolidation\AnnotatedCommand\CommandError;
use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Command to run configuration import or export as a specific environment.
 */
class ConfCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use EnabledModulesTrait;
  use EnvironmentsTrait;
  use SiteAliasManagerAwareTrait;

  /**
   * Import or export configuration as an environment.
   *
   * @param null|string $operation
   *   The operation to perform.
   * @param null|string $site
   *   The site to perform the operation on.
   * @param null|string $environment
   *   The environment to perform the operation as.
   *
   * @command kit-conf
   * @usage drush conf import www local
   *   Imports config as local environment.
   * @aliases kc, conf
   */
  public function conf($operation = NULL, $site = NULL, $environment = NULL) {
    // Get operation if not passed in.
    $operations = ['export' => 'Export', 'import' => 'Import'];
    if (!in_array($operation, array_keys($operations))) {
      $operation = $this->io()->choice('Please select an operation to perform', $operations);
      $operation = strtolower($operation);
    }

    if (empty($operation)) {
      $this->io()->error(dt('Operation required.'));
      return new CommandError();
    }

    // Get site if not passed in, or site doesn't exist in available sites.
    $sites = $this->getSites();
    $sites = array_combine($sites, $sites);
    if (!is_null($site) && !in_array($site, $sites)) {
      $this->io()->note("Site {$site} is not an available option.");
      $site = NULL;
    }
    if (is_null($site)) {
      $site = $this->io()->choice("Please select the site to {$operation} as", $sites, 'www');
    }

    // Get environment if not passed in, or site doesn't exist in available
    // environment for the specified site.
    $environments = $this->getSiteEnvironments($site);
    if (!is_null($environment) && !in_array($environment, $environments)) {
      $this->io()->note("Environment {$environment} is not an available option for site {$site}.");
      $environment = NULL;
    }
    if (is_null($environment)) {
      $alias_id = $this->io()->choice("Please select the environment to {$operation} as", $environments, 'local');
    }
    else {
      $alias_id = array_search($environment, $environments);
    }
    $environment = $environments[$alias_id];

    // Confirm that the user wants to perform the specified option.
    $question = "{$operations[$operation]} site {$site} as {$environment} environment?";
    $confirmed = $this->doAsk(new ConfirmationQuestion($this->formatQuestion($question . ' (y/n)')));

    if ($operation && $confirmed) {
      $this->io()->success("Running {$operation} on site:{$site} as environment:{$environment}.");

      // Load self to import locally.
      // Load 'as' alias to get environment variable and relevant site uri.
      $alias = $this->siteAliasManager()->getSelf();
      $alias_as = $this->siteAliasManager()->get($alias_id);

      // Find environment and uri from 'as' alias, and add to self.
      $alias_options = $alias->get('options', []);
      $alias_options['#env-vars']['SITE_ENVIRONMENT'] = $alias_as->get('site-env', $alias_id);
      if ($uri = $alias_as->get('uri', NULL)) {
        $alias_options['uri'] = $uri;
      }
      $alias->set('options', $alias_options);

      // Clear cache as environment to make sure we have the most recent cache.
      $this->io()->newLine();
      $this->io()->title('Clearing cache');
      drush_invoke_process($alias, 'cr');

      if ($operation === 'import') {
        // Import configuration using environment and site.
        $this->io()->newLine();
        $this->io()->title('Importing configuration');
        drush_invoke_process($alias, 'config-import', [], ['-y']);

        // If structure sync module exists, import blocks and taxonomies.
        if ($this->checkEnabledModule($alias, 'structure_sync')) {
          $this->io()->newLine();
          $this->io()->title('Syncing Blocks');
          drush_invoke_process($alias, 'import-blocks', [], ['--choice=full']);

          $this->io()->newLine();
          $this->io()->title('Syncing Taxonomies');
          drush_invoke_process($alias, 'import-taxonomies', [], ['--choice=full']);
        }

        // If default content deploy module exists, import default content.
        if ($this->checkEnabledModule($alias, 'default_content_deploy')) {
          $this->io()->newLine();
          $this->io()->title('Deploying Content');
          drush_invoke_process($alias, 'default-content-deploy-import', [], ['-y']);
        }
      }
      elseif ($operation === 'export') {
        // Export configuration using environment and site.
        $this->io()->newLine();
        $this->io()->title('Exporting configuration');
        drush_invoke_process($alias, 'config-export', [], ['-y']);

        // If structure sync module exists, import blocks and taxonomies.
        if ($this->checkEnabledModule($alias, 'structure_sync')) {
          $this->io()->newLine();
          $this->io()->title('Syncing Blocks');
          drush_invoke_process($alias, 'export-blocks', [], ['--choice=full']);

          $this->io()->newLine();
          $this->io()->title('Syncing Taxonomies');
          drush_invoke_process($alias, 'export-taxonomies', [], ['--choice=full']);
        }
      }
    }

    $this->io()->newLine();
  }

}
