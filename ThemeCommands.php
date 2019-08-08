<?php

namespace Drush\Commands\kit_drush_commands;

use Consolidation\AnnotatedCommand\CommandError;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drupal\Component\Utility\Html;
use Drush\Backend\BackendPathEvaluator;
use Drush\Commands\DrushCommands;
use Drush\Commands\kit_drush_commands\Util\WriteWrapperTrait;
use Drush\SiteAlias\HostPath;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Command to scaffold starter theme code and source files.
 */
class ThemeCommands extends DrushCommands implements SiteAliasManagerAwareInterface  {

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
   * An array of default included scaffold theme config.
   *
   * @return array
   */
  protected function getDefaultScaffoldThemeConfig () {
    return [];
  }

  /**
   * Returns user supplied scaffold theme config merged with default.
   *
   * @return array
   */
  protected function getScaffoldThemeConfig() {
    return array_merge_recursive($this->getDefaultScaffoldThemeConfig(), $this->getConfig()->get('kit.theme.scaffold_themes', []));
  }

  /**
   * Create a drupal theme and source files from scaffold projects.
   *
   * @param null|string $theme
   *   The theme to scaffold.
   * @param null|string $name
   *   The name of theme to be created.
   *
   * @command kit:theme
   * @usage drush kit:theme
   *   Create a drupal theme and source files from scaffold projects.
   * @aliases kt, kit-theme
   */
  public function create($theme = NULL, $name = NULL) {
    $theme_options = $this->getScaffoldThemeConfig();

    // Figure out source directory path.
    $evaluatedPath = HostPath::create($this->siteAliasManager(), '@self');
    $this->pathEvaluator->evaluate($evaluatedPath);
    $docroot =  $evaluatedPath->fullyQualifiedPath();
    $project_root = realpath($docroot . '/../');
    $source_dir = $this->getConfig()->get('kit.theme.source_directory', NULL);
    if (is_null($source_dir)) {
      $source_dir_name = 'source/themes';
      $source_dir = $project_root . '/' . $source_dir_name;
    }

    // Figure out which theme to scaffold.
    if (is_null($theme) || !array_key_exists($theme, $theme_options)) {
      $options = [];
      foreach($theme_options as $id => $theme_option) {
        $options[$id] = "{$theme_option['title']}: {$theme_option['description']}";
      }

      $theme = $this->io()->choice("Which theme would you like to scaffold from?", $options);
    }

    // Exit if we don't have a valid choice.
    if (!$theme || !array_key_exists($theme, $theme_options)) {
      $this->write("Please select an available theme option.", 'error');
      return new CommandError();
    }

    // Ask what we should call the new theme.
    if (is_null($name)) {
      $name = $this->ask('What should the new theme be called?');
    }

    // Exit if we don't have a valid name.
    if (!$name) {
      $this->write("A new theme name is required.", 'error');
      return new CommandError();
    }

    // Clean the name to a proper machine name / directory.
    $name_cleaned = Html::cleanCssIdentifier(strtolower($name), [
      ' ' => '_',
      '-' => '_',
      '_' => '_',
      '/' => '_',
      '[' => '_',
      ']' => '_',
    ]);

    // Confirm that the user wants to perform the specified option.
    $question = "Scaffold from {$theme_options[$theme]['title']} theme as {$name_cleaned}?";
    $confirmed = $this->doAsk(new ConfirmationQuestion($this->formatQuestion($question . ' (y/n)')));

    //Exit early if not confirmed.
    if (!$confirmed) {
      return;
    }

    // Figure out source directory path.
    $evaluatedPath = HostPath::create($this->siteAliasManager(), '@self');
    $this->pathEvaluator->evaluate($evaluatedPath);
    $docroot =  $evaluatedPath->fullyQualifiedPath();
    $project_root = realpath($docroot . '/../');
    $theme_dir = $docroot . '/themes/custom';
    $source_dir = $this->getConfig()->get('kit.theme.source_directory', NULL);
    if (is_null($source_dir)) {
      $source_dir_name = 'source/themes/custom';
      $source_dir = $project_root . '/' . $source_dir_name;
    }

    // Validate directories.
    $this->io()->title('Directories');

    // Validate custom theme parent directory.
    if (!file_exists($theme_dir)) {
      $this->write('Creating custom themes directory');
      $success = mkdir($theme_dir, 0755, TRUE);
      if ($success) {
        $this->write('Created custom themes directory.', 'success', TRUE);
      }
      else {
        $this->write('Failure creating custom themes directory.', 'error', TRUE);
        return new CommandError();
      }
    }
    else {
      $this->write('Verifying custom themes directory permissions are writeable');
      $success = chmod($theme_dir, 0755);
      if ($success) {
        $this->write('Verified custom themes directory permissions.', 'success', TRUE);
      }
      else {
        $this->write('Failure adjusting custom themes directory permissions.', 'error', TRUE);
        return new CommandError();
      }
    }

    // Validate source directory.
    if (!file_exists($source_dir)) {
      $this->write('Creating source themes directory');
      $success = mkdir($source_dir, 0755, TRUE);
      if ($success) {
        $this->write('Created source themes directory.', 'success', TRUE);
      }
      else {
        $this->write('Failure creating source themes directory.', 'error', TRUE);
        return new CommandError();
      }
    }
    else {
      $this->write('Verifying source themes directory permissions are writeable');
      $success = chmod($source_dir, 0755);
      if ($success) {
        $this->write('Verified source themes directory permissions.', 'success', TRUE);
      }
      else {
        $this->write('Failure adjusting source themes directory permissions.', 'error', TRUE);
        return new CommandError();
      }
    }

    // Creating theme.
    $this->io()->title('Theme files');

    $this->write('Cloning theme.');
    $theme_title = isset($theme_options[$theme]['title']) ? $theme_options[$theme]['title'] : $theme;
    $theme_repo_address = isset($theme_options[$theme]['theme_repo']) ? $theme_options[$theme]['theme_repo'] : NULL;
    $theme_repo_branch = isset($theme_options[$theme]['theme_repo_branch']) ? $theme_options[$theme]['theme_repo_branch'] : 'master';
    $process = $this->processManager()->shell("git clone --depth=1 --branch={$theme_repo_branch} {$theme_repo_address} {$name_cleaned}", $theme_dir);
    $success = ($this->io()->isVerbose()) ? $process->run($process->showRealtime()) : $process->run();
    if ($success === 0) {
      $this->write('Cloned theme.', 'success', TRUE);
    }
    else {
      $this->write('Failure cloning theme.', 'error', TRUE);
      $this->write($process->getErrorOutput());
      return new CommandError();
    }

    // Clean and rename old name to new.
    $this->write('Cleaning and renaming theme.');
    $theme_cloning_command = [
      'rm -rf .git',
      'rm -rf .gitignore',
      'rm -rf README.md',
      "find . -type f -exec sed -i 's/{$theme_title}/{$name}/g' {} +",
      "find . -type f -exec sed -i 's/{$theme}/{$name_cleaned}/g' {} +",
      "rename 's/{$theme}/{$name_cleaned}/g' *"
    ];
    $process = $this->processManager()->shell(implode(' && ', $theme_cloning_command), "{$theme_dir}/$name_cleaned");
    $success = ($this->io()->isVerbose()) ? $process->run($process->showRealtime()) : $process->run();
    if ($success === 0) {
      $this->write('Cleaned and renamed theme.', 'success', TRUE);
    }
    else {
      $this->write('Failure cleaning and renaming theme.', 'error', TRUE);
      $this->write($process->getErrorOutput());
      return new CommandError();
    }

    // Creating theme.
    $this->io()->title('Theme source files');
    $this->write('Cloning source files.');
    $source_repo_address = isset($theme_options[$theme]['source_repo']) ? $theme_options[$theme]['source_repo'] : NULL;
    $source_repo_branch = isset($theme_options[$theme]['source_repo_branch']) ? $theme_options[$theme]['source_repo_branch'] : 'master';
    $process = $this->processManager()->shell("git clone --depth=1 --branch={$source_repo_branch} {$source_repo_address} {$name_cleaned}", $source_dir);
    $success = ($this->io()->isVerbose()) ? $process->run($process->showRealtime()) : $process->run();
    if ($success === 0) {
      $this->write('Cloned theme source files.', 'success', TRUE);
    }
    else {
      $this->write('Failure theme source files.', 'error', TRUE);
      $this->write($process->getErrorOutput());
      return new CommandError();
    }

    // Clean and rename old name to new.
    $this->write('Cleaning and renaming theme source files.');
    $source_cloning_command = [
      'rm -rf .git',
      'rm -rf .gitignore',
      'rm -rf README.md',
      "find . -type f -exec sed -i 's/{$theme_title}/{$name}/g' {} +",
      "find . -type f -exec sed -i 's/{$theme}/{$name_cleaned}/g' {} +",
      "rename 's/{$theme}/{$name_cleaned}/g' *"
    ];
    $process = $this->processManager()->shell(implode(' && ', $source_cloning_command), "{$source_dir}/$name_cleaned");
    $success = ($this->io()->isVerbose()) ? $process->run($process->showRealtime()) : $process->run();
    if ($success === 0) {
      $this->write('Cleaned and renamed theme source theme files.', 'success', TRUE);
    }
    else {
      $this->write('Failure cleaning and renaming source theme files.', 'error', TRUE);
      $this->write($process->getErrorOutput());
      return new CommandError();
    }

  }
}
