<?php

namespace Drush\Commands\kit_drush_commands;

use Consolidation\AnnotatedCommand\CommandError;
use Consolidation\SiteAlias\SiteAlias;
use Consolidation\SiteAlias\SiteAliasInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Consolidation\SiteProcess\SiteProcess;
use Drush\Commands\DrushCommands;
use Drush\Commands\kit_drush_commands\Util\EnabledModulesTrait;
use Drush\Commands\kit_drush_commands\Util\EnvironmentsTrait;
use Drush\Commands\kit_drush_commands\Util\WriteWrapperTrait;
use Drush\Drush;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Drush\Exceptions\CommandFailedException;

/**
 * Command to run configuration import or export as a specific environment.
 */
class ConfCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use EnabledModulesTrait;
  use EnvironmentsTrait;
  use SiteAliasManagerAwareTrait;
  use WriteWrapperTrait;

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
   * @command kit:conf
   * @usage drush kit:conf import www local
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

    // Exit early if operation is required
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
      $site = $this->io()->choice("Please select the site to {$operation} as", $sites);
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
    $this->io()->newLine();

    // Exit early if command wasn't confirmed.
    if (!$confirmed) {
      return;
    }

    // Load self to import locally.
    // Load 'as' alias to get environment variable and relevant site uri.
    $alias = $this->getMockEnvAlias($alias_id);

    try {
      switch ($operation) {
        case 'export':
          $this->runExport($alias);
          break;

        case 'import':
          $this->runImport($alias);
          break;
      }
    } catch (\Exception $e) {
      $this->io()->newLine();
      return new CommandError($e->getMessage());
    }
  }

  /**
   * Gets an alias that mocks another alias to run the commands as.
   *
   * @param string $alias_id
   *   The alias to create a mock alias from.
   *
   * @return \Consolidation\SiteAlias\SiteAlias
   *   The modified local alias with mock-alias overrides.
   */
  protected function getMockEnvAlias($alias_id) {
    // Load self to import locally.
    // Load 'as' alias to get environment variable and relevant site uri.
    $alias = $this->siteAliasManager()->getSelf();
    $alias_as = $this->siteAliasManager()->get($alias_id);

    // Find environment and uri from 'as' alias, and add to self.
    $alias_options = $alias->get('options', []);
    $environment = $alias_as->get('site-env', array_pop(explode('.', $alias_as->name(), 2)));
    $alias_options['#env-vars']['SITE_ENVIRONMENT'] = $environment;
    if ($uri = $alias_as->get('uri', NULL)) {
      $alias_options['uri'] = $uri;
      $alias->set('uri', $uri);
    }
    $alias->set('options', $alias_options);
    $alias->set('envs', $alias_options['#env-vars']);

    return $alias;
  }

  /**
   * Export as an environment.
   *
   * @param \Consolidation\SiteAlias\SiteAlias $alias
   *   The alias to run the export as.
   */
  protected function runExport(SiteAlias $alias) {
    // Clear cache.
    $this->runDrushCommand('Clearing cache', 'Cleared cache', $alias, 'cr');

    $this->runDrushCommand('Exporting configuration', 'Exported configuration', $alias, 'config-export', [], ['yes' => TRUE]);

    // If structure sync module exists, export blocks and taxonomies.
    if ($this->checkEnabledModule($alias, 'structure_sync')) {
      $this->runDrushCommand('Exporting blocks', 'Exported blocks', $alias,'export-blocks', [], ['choice' => 'full']);
      $this->runDrushCommand('Exporting taxonomies', 'Exported taxonomies', $alias, 'export-taxonomies', [], ['choice' => 'full']);
    }
    // Clear cache.
    $this->runDrushCommand('Clearing cache', 'Cleared cache', $alias, 'cr');

  }

  /**
   * Import as an environment.
   *
   * @param \Consolidation\SiteAlias\SiteAlias $alias
   *   The alias to run the import as.
   */
  protected function runImport(SiteAlias $alias) {
    // Clear cache.
    $this->runDrushCommand('Clearing cache', 'Cleared cache', $alias, 'cr');

    $this->runDrushCommand('Importing configuration', 'Imported configuration', $alias, 'config-import', [], ['yes' => TRUE]);

    // If structure sync module exists, import blocks and taxonomies.
    if ($this->checkEnabledModule($alias, 'structure_sync')) {
      $this->runDrushCommand('Importing blocks', 'Imported bloacks', $alias, 'import-blocks', [], ['choice' => 'full']);
      $this->runDrushCommand('Importing taxonomies', 'Imported taxonomies', $alias, 'import-taxonomies', [], ['choice' => 'full']);
    }

    // If default content deploy module exists, import default content.
    if ($this->checkEnabledModule($alias, 'default_content_deploy')) {
      $this->runDrushCommand('Importing content', 'Imported content', $alias, 'default-content-deploy-import', [], ['yes' => TRUE]);
    }

    // Clear cache.
    $this->runDrushCommand('Clearing cache', 'Cleared cache', $alias, 'cr');

  }

  /**
   * Run a command.
   */
  protected function runDrushCommand($title, $success_message, $alias, $command, $args = [], $options = [], $options_double_dash = []) {
    $this->io()->title($title);
    $process = Drush::drush($alias, $command, $args, $options, $options_double_dash);
    $success = ($this->io()->isVerbose()) ? $process->run($process->showRealtime(), $alias->get('envs')) : $process->run(null, $alias->get('envs'));
    if ($success === 0) {
      $this->write($success_message, 'success', TRUE);
    }
    else {
      $this->write($process->getErrorOutput());
      throw new CommandFailedException('Failed ' . $title);
    }
  }
}
