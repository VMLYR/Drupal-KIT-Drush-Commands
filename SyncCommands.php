<?php

namespace Drush\Commands\kit_drush_commands;

use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drush\Backend\BackendPathEvaluator;
use Drush\Commands\DrushCommands;
use Drush\Commands\kit_drush_commands\Util\EnabledModulesTrait;
use Drush\Commands\kit_drush_commands\Util\EnvironmentsTrait;
use Drush\Commands\kit_drush_commands\Util\WriteWrapperTrait;
use Drush\Drush;
use Drush\SiteAlias\HostPath;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Command to sync the current environment with another environment.
 */
class SyncCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use EnabledModulesTrait;
  use EnvironmentsTrait;
  use SiteAliasManagerAwareTrait;
  use WriteWrapperTrait;

  /**
   * The path evaluator.
   *
   * @var \Drush\Backend\BackendPathEvaluator
   */
  protected $pathEvaluator;

  /**
   * @inheritdoc
   */
  public function __construct() {
    $this->pathEvaluator = new BackendPathEvaluator();
  }

  /**
   * Sync with an environment.
   *
   * @param null|string $site
   *   The site to perform the operation on.
   * @param null|string $environment_from
   *   The environment to perform the operation as.
   * @param null|string $environment_as
   *   The environment to perform the operation as.
   *
   * @option dump-dir
   *   The database dump directory, relative to docroot.
   * @option skip-composer
   *   Skip composer install.
   * @option skip-config
   *   Skip configuration import.
   * @option skip-db-dump
   *   Skip the database dump and use existing database-dump for import.
   * @option skip-db-import
   *   Skip thd database import.
   * @command kit:sync
   * @usage drush sync www remote_prod local
   *   Syncs local environment from another environment.
   * @aliases ks, ksync, sync
   */
  public function sync($site = NULL, $environment_from = NULL, $environment_as = NULL, $options = ['dump-dir' => NULL, 'skip-composer' => FALSE, 'skip-config' => FALSE, 'skip-db-dump' => FALSE, 'skip-db-import' => FALSE]) {
    $skip_composer = (isset($options['skip-composer']) && $options['skip-composer']);
    $skip_config = (isset($options['skip-config']) && $options['skip-config']);
    $skip_db_dump = (isset($options['skip-db-dump']) && $options['skip-db-dump']);
    $skip_db_import = (isset($options['skip-db-import']) && $options['skip-db-import']);

    // Get site if not passed in, or site doesn't exist in available sites.
    $sites = $this->getSites();
    $sites = array_combine($sites, $sites);
    if (!is_null($site) && !in_array($site, $sites)) {
      $this->io()->note("Site {$site} is not an available option.");
      $site = NULL;
    }
    if (is_null($site)) {
      $site = $this->io()->choice("Please select the site to sync", $sites, 'www');
    }

    $local_alias = $this->siteAliasManager()->getAlias("@{$site}.local");
    $environments = $this->getSiteEnvironments($site);

    // Get environment if not passed in, or site doesn't exist in available
    // environment for the specified site.
    if (!is_null($environment_from) && !in_array($environment_from, $environments)) {
      $this->io()->note("Environment {$environment_from} is not an available option for site {$site}.");
      $environment_from = NULL;
    }
    if (is_null($environment_from)) {
      $environment_from_alias_id = $this->io()->choice("Please select the environment to import from", $environments, 'remote_prod');
    }
    else {
      $environment_from_alias_id = array_search($environment_from, $environments);
    }
    $environment_from = $environments[$environment_from_alias_id];

    // Get environment if not passed in, or site doesn't exist in available
    // environment for the specified site.
    if (!is_null($environment_as) && !in_array($environment_as, $environments)) {
      $this->io()->note("Environment {$environment_as} is not an available option for site {$site}.");
      $environment_as = NULL;
    }
    if (is_null($environment_as)) {
      $environment_as_alias_id = $this->io()->choice('Please select the environment to import as', $environments, 'local');
    }
    else {
      $environment_as_alias_id = array_search($environment_as, $environments);
    }
    $environment_as = $environments[$environment_as_alias_id];

    // Confirm that the user wants to perform the specified option.
    $question = "Sync {$site} from {$environment_from} and import as {$environment_as}?";
    $confirmed = $this->doAsk(new ConfirmationQuestion($this->formatQuestion($question . ' (y/n)')));

    // Exit early if not confirmed.
    if (!$confirmed) {
      return;
    }

    $this->io()->success("Syncing {$site} from {$environment_from} and importing as {$environment_as}.");

    // Run or skip composer import.
    $this->sectionComposer($skip_composer);

    // Run or skip database sync.
    $this->sectionDatabase($site, $environment_from, $options['dump-dir'], $skip_db_dump, $skip_db_import);

    // Run or skip configuration sync.
    $this->sectionConfig($site, $environment_as, $skip_config);
  }

  /**
   * Run the Composer dependency installation step.
   *
   * @param bool $skip
   *   Whether the section should be skipped.
   */
  protected function sectionComposer($skip = FALSE) {
    $this->io()->newLine();
    $this->io()->title('Composer dependencies');

    // Run or skip composer import.
    if ($skip) {
      $this->write('Skipping Composer dependency installation.', 'notice');
    }
    else {
      $this->write('Installing Composer dependencies.');

      $process = $this->processManager()->shell('composer install --prefer-dist -v -o', '/var/www');
      $success = ($this->io()->isVerbose()) ? $process->run($process->showRealtime()) : $process->run();

      if ($success === 0) {
        $this->write('Installed Composer dependencies.', 'success', TRUE);
      }
      else {
        $this->write('Failure installing Composer dependencies. Run as verbose to see full output.', 'error', TRUE);
      }
    }
  }

  /**
   * Run the database sync step.
   *
   * @param string $site
   *   The site to pull the database from.
   * @param string $environment_from
   *   The environment to pull the database from.
   * @param null $dump_directory
   *   The directory where the database dump should be saved.
   * @param bool $skip_db_dump
   *   Whether the database dump section should be skipped.
   * @param bool $skip_db_import
   *   Whether the database import section should be skipped.
   */
  protected function sectionDatabase($site, $environment_from, $dump_directory = NULL, $skip_db_dump = FALSE, $skip_db_import = FALSE) {
    $this->io()->newLine();
    $this->io()->title('Database');

    // Determine local site docroot.
    $local_alias_id = "@{$site}.local";
    $local_alias = $this->siteAliasManager()->getAlias($local_alias_id);
    $evaluatedPath = HostPath::create($this->siteAliasManager(), $local_alias_id);
    $this->pathEvaluator->evaluate($evaluatedPath);
    $docroot =  $evaluatedPath->fullyQualifiedPath();

    // Determine dump directory, dump filename, and dump file path.
    $dump_dir = (is_null($dump_directory)) ? '../database_backups' : trim($dump_directory, '/');
    $dump_dir_abs = $docroot . '/' . $dump_dir;
    $dump_file_name = $site . '.' . $environment_from . '.sql';
    $dump_file = $dump_dir . '/' . $dump_file_name;
    $dump_file_abs = $dump_dir_abs . '/' . $dump_file_name;


    // Run or skip database dump.
    if ($skip_db_dump) {
      $this->write('Skipping database dump.', 'notice');
    }
    else {
      // Make sure the database dump directory exists and is writeable.
      if (!file_exists($dump_dir_abs)) {
        $this->write('Creating database dump directory.');
        $success = mkdir($dump_dir_abs);
        if ($success) {
          $this->write('Created database dump directory.', 'success', TRUE);
        }
        else {
          $this->write('Failure creating database dump directory.', 'error', TRUE);
        }
      }
      else {
        $this->write('Verifying database dump directory permissions are writeable.');
        $success = chmod($dump_dir_abs, 0777);
        if ($success) {
          $this->write('Verified database dump directory permissions.', 'success', TRUE);
        }
        else {
          $this->write('Failure adjusting database dump directory permissions.', 'error', TRUE);
        }
      }

      // Skip dump if directory is not accessible.
      if (!$success) {
        $this->write('Skipping database dump. Import will use old file if one exists.', 'warning');
      }
      else {
        $this->write('Dumping database to file.');

        $process = Drush::process("drush @{$site}.{$environment_from} sql:dump --gzip > {$dump_file_abs}");
        $success = ($this->io()->isVerbose()) ? $process->run($process->showRealtime()) : $process->run();

        if ($success === 0) {
          $this->write('Dumping database to file.', 'success', TRUE);
        }
        else {
          $this->write('Error dumping database.', 'error', TRUE);
          $this->write($process->getErrorOutput());
          $this->write('Import will use old file if one exists.', 'warning');
        }
      }
    }

    // Run or skip database import.
    if ($skip_db_import) {
      $this->write('Skipping database import.', 'notice');
    }
    else {
      $dump_file_abs = realpath($dump_file_abs);
      if (!file_exists($dump_file_abs)) {
        $this->write('Database dump file does not exist.', 'error');
        $this->write('Skipping database import.', 'warning');
      }
      else {
        $this->write('Dropping local database.');
        $process = Drush::drush($local_alias, 'sql:drop', [], ['yes' => TRUE]);
        $success = ($this->io()->isVerbose()) ? $process->run($process->showRealtime()) : $process->run();
        if ($success !== 0) {
          $this->write('Failure dropping local database.', 'error', TRUE);
          $this->write($process->getErrorOutput());
          $this->write('Skipping database import.', 'warning');
        }
        else {
          $this->write('Dropped local database.', 'success', TRUE);
          $this->write('Importing database from file.');

          $process = $this->processManager()->shell("gunzip -c {$dump_file_abs} | drush sqlc", '/var/www/docroot');
          $success = ($this->io()->isVerbose()) ? $process->run($process->showRealtime()) : $process->run();
          if ($success === 0) {
            $this->write('Imported database from file.', 'success', TRUE);
          }
          else {
            $this->write('Failure importing database from file.', 'error', TRUE);
            $this->write($process->getErrorOutput());
          }
        }
      }
    }
  }

  /**
   * Run the config sync step.
   *
   * @param string $site
   *   The site to sync config for.
   * @param string $environment
   *   The environment to import as.
   * @param bool $skip
   *   Whether the section should be skipped.
   */
  protected function sectionConfig($site, $environment = 'local', $skip = FALSE) {
    $this->io()->newLine();
    $this->io()->title('Configuration');

    // Run or skip configuration sync.
    if ($skip) {
      $this->write('Skipping configuration sync.', 'notice');
    }
    else {
      $this->write("Syncing configuration for {$site} as {$environment}.");
      $alias = $this->siteAliasManager()->get("@{$site}.local");
      $process = Drush::drush($alias, 'kit:conf', ['import', $environment], ['yes' => TRUE]);
      $success = ($this->io()->isVerbose()) ? $process->run($process->showRealtime()) : $process->run();
      if ($success === 0) {
        $this->write("Imported configuration for {$site} as {$environment}.", 'success', TRUE);
      }
      else {
        $this->write('Failure importing configuration.', 'error', TRUE);
        $this->write($process->getErrorOutput());
      }
    }
  }
}
