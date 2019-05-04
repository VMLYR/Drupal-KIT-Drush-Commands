<?php

namespace Drush\Commands\kit_drush_commands;

use Consolidation\AnnotatedCommand\CommandError;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drush\Commands\DrushCommands;
use Drush\Commands\kit_drush_commands\Util\WriteWrapperTrait;
use Drush\Internal\Config\Yaml\Yaml;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;

/**
 * Command that checks response status on a list of URLs.
 */
class UrlCommands extends DrushCommands implements SiteAliasManagerAwareInterface  {

  use SiteAliasManagerAwareTrait;
  use WriteWrapperTrait;

  /**
   * Check response status on a list of urls.
   *
   * @option fail-500
   *   Force URL check to fail if any of the URLs return a 500 error.
   * @option file
   *   Path to a yaml file with a list of URLs to check. The file should be a
   *   list of URLs as keys, with values being the desired HTTP response code.
   * @option urls
   *   Urls to check. Optional HTTP response code can be appended using |.
   *   example: --urls='/url/path/here|200','/as|404', 'http://site.docksal|301'
   * @option url-threshold
   *   Number of URLs to allow to fail and still pass check.
   * @option log-error-threshold
   *   Number of log errors allowed during URL check and still pass check.
   * @option log-warning-threshold
   *   Number of log warnings allowed during URL check and still pass check.
   * @command kit:check-url
   * @usage drush check-url /cool/page,/another/cool/page
   * @aliases kcu, check-url
   */
  public function check($options = ['fail-500' => TRUE, 'file' => NULL, 'urls' => '', 'url-threshold' => 0, 'log-error-threshold' => NULL, 'log-warning-threshold' => NULL]) {
    $log_error_threshold = (!is_null($options['log-error-threshold'])) ? intval($options['log-error-threshold']) : NULL;
    $log_warning_threshold = (!is_null($options['log-warning-threshold'])) ? intval($options['log-warning-threshold']) : NULL;
    $url_threshold = intval($options['url-threshold']);
    $curl_options = [
      CURLOPT_AUTOREFERER    => TRUE,
      CURLOPT_CONNECTTIMEOUT => 120,
      CURLOPT_ENCODING       => '',
      CURLOPT_FOLLOWLOCATION => TRUE,
      CURLOPT_FORBID_REUSE   => TRUE,
      CURLOPT_FRESH_CONNECT  => TRUE,
      CURLOPT_HEADER         => TRUE,
      CURLOPT_HTTPHEADER     => [''],
      CURLOPT_MAXREDIRS      => 10,
      CURLOPT_NOBODY         => TRUE,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_SSL_VERIFYPEER => FALSE,
      CURLOPT_SSL_VERIFYHOST => FALSE,
      CURLOPT_TIMEOUT        => 120,
      CURLOPT_USERAGENT      => 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1)',
    ];

    $urls = [];

    // Clean and place file URLs into array.
    if (!empty($options['file'])) {
      $file_path = realpath($this->getConfig()->cwd() . '/' . $options['file']);
      $file_config = Yaml::parse(file_get_contents($file_path));
      if (isset($file_config['kit']['url_check']['urls'])) {
        foreach ($file_config['kit']['url_check']['urls'] as $url => $code) {
          $urls[$url] = intval($code);
        }
      }
    }

    // Clean and place URLs into array with desired HTTP response keyed by URL.
    $option_urls = array_map('trim', array_filter(explode(',', $options['urls'])));
    foreach ($option_urls as $option_url) {
      list($url, $code) = explode('|', $option_url, 2);
      $urls[$url] = (isset($code)) ? intval($code) : 200;
    }

    // If no URLs have been passed to the command, use drush config urls.
    if (empty($urls)) {
      $urls = $this->getConfig()->get('kit.url_check.urls', []);
    }

    // Clear logs if threshold is set.
    if (!is_null($log_error_threshold) || !is_null($log_warning_threshold)) {
      $this->io()->title(dt('Clearing cache and logs.'));

      $backend_options = ['log' => TRUE, 'output' => FALSE];
      drush_invoke_process('@self', 'cache:rebuild', [], [], $backend_options);
      drush_invoke_process('@self', 'watchdog:delete', ['all'], ['-y'], $backend_options);

      $this->io()->newLine();
    }

    // URL check section start.
    $this->io()->title(dt('Running URL check'));

    // Run through each URL.
    $fail_500 = FALSE;
    $url_mismatch = [];
    foreach($urls as $url => $desired_http_code) {
      // Disable redirects if looking for a redirect.
      if (in_array($desired_http_code, [301, 302, 303, 307, 308])) {
        $curl_options[CURLOPT_MAXREDIRS] = 0;
      }
      // Otherwise set max redirects to 10 (10 is a high threshold; if a 301 is
      // still returned then something is not right on the page).
      else {
        $curl_options[CURLOPT_MAXREDIRS] = 10;
      }

      // Check http code.
      $returned_http_code = $this->getHttpCode($url, $curl_options);

      // Track is http code isn't same as desired http code.
      if ($returned_http_code !== $desired_http_code) {
        $url_mismatch[$url] = $returned_http_code;
      }

      // Track 500 errors if the option to fail on 500 errors is set.
      if ($options['fail-500'] && $returned_http_code === 500) {
        $fail_500 = TRUE;
      }
    }

    // Notify the user and potentially error.
    if (empty($url_mismatch)) {
      $this->io()->success(dt('URL check succeeded. No HTTP code mismatches found.'));
    }
    else {
      // Print mismatches to the user.
      $headers = ['URL', 'HTTP code', 'Desired HTTP code'];
      $rows = [];
      foreach ($url_mismatch as $url => $http_code) {
        $rows[] = [$url, $http_code, $urls[$url]];
      }
      $this->io()->table($headers, $rows);

      $message_params = [
        '@count' => count($url_mismatch),
        '@threshold' => $url_threshold
      ];
      if (count($url_mismatch) > $url_threshold) {
        $this->io()->error(dt('URL check failed. Total number of HTTP code mismatches (@count) exceeded threshold of @threshold.', $message_params));
        return new CommandError();
      }
      else if ($fail_500) {
        $this->io()->error(dt('URL check failed. Atleast one of the HTTP code responses was a 500.', $message_params));
        return new CommandError();
      }
      else {
        $this->io()->warning(dt('URL check succeeded. Total number of HTTP code mismatches (@count) didn\'t exceed threshold of @threshold.', $message_params));
      }
    }

    // Check log warnings against threshold if threshold is set.
    if (!is_null($log_warning_threshold)) {
      $this->io()->title(dt('Running log warning check'));

      $logs = $this->getLogs('Warning');

      // Notify the user and potentially error.
      if (empty($logs)) {
        $this->io()->success(dt('Log warning check succeeded. No log warnings found.'));
      }
      else {
        // Print logs to the user.
        $headers = ['Type', 'Message', 'Location'];
        $rows = [];
        foreach ($logs as $log) {
          $rows[] = [
            $log['type'],
            $log['message'],
            $log['location'],
          ];
        }
        $this->io()->table($headers, $rows);

        $message_params = [
          '@count' => count($logs),
          '@threshold' => $log_warning_threshold
        ];
        if (count($logs) > $log_warning_threshold) {
          $this->io()->error(dt('Log warning check failed. Total number of log warnings (@count) exceeded threshold of @threshold.', $message_params));
          return new CommandError();
        }
        else {
          $this->io()->warning(dt('Log warning check  succeeded. Total number of log warnings (@count) didn\'t exceed threshold of @threshold.', $message_params));
        }
      }
    }

    // Check log errors against threshold if threshold is set.
    if (!is_null($log_error_threshold)) {
      $this->io()->title(dt('Running log error check'));

      $logs = $this->getLogs('Critical');

      // Notify the user and potentially error.
      if (empty($logs)) {
        $this->io()->success(dt('Log error check succeeded. No log errors found.'));
      }
      else {
        // Print logs to the user.
        $headers = ['Type', 'Message', 'Location'];
        $rows = [];
        foreach ($logs as $log) {
          $rows[] = [
            $log['type'],
            $log['message'],
            $log['location'],
          ];
        }
        $this->io()->table($headers, $rows);

        $message_params = [
          '@count' => count($logs),
          '@threshold' => $log_error_threshold
        ];
        if (count($logs) > $log_error_threshold) {
          $this->io()->error(dt('Log error check failed. Total number of log errors (@count) exceeded threshold of @threshold.', $message_params));
          return new CommandError();
        }
        else {
          $this->io()->warning(dt('Log error check  succeeded. Total number of log errors (@count) didn\'t exceed threshold of @threshold.', $message_params));
        }
      }
    }

    // Command done, put space between us and the next command.
    $this->io()->newLine();
  }

  /**
   * Return a list of watchdog logs based on severity.
   *
   * @param null|string $severity
   *   The severity level of logs to return.
   *
   * @return array
   *   An array of logs.
   */
  protected function getLogs($severity = NULL) {
    $command_options[] = '-y';
    if (!empty($severity)) {
      $command_options[] = "--severity={$severity}";
    }
    $backend_options = [
      'log' => FALSE,
      'output' => FALSE,
    ];
    $response = drush_invoke_process('@self', 'watchdog:list', [], $command_options, $backend_options);
    if ($response) {
      return $response['object'];
    }

    return [];
  }

  /**
   * Hits a URL and returns the HTTP code from the response.
   *
   * @param string $url
   *   The URL.
   * @param array $curl_options
   *   The options to pass to CURL.
   *
   * @return mixed
   */
  protected function getHttpCode($url, $curl_options) {
    // Add host if host is missing.
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) {
      $host = '';

      // Get URI from alias.
      if ($drush_uri = $this->siteAliasManager()->getSelf()->uri()) {
        $host = parse_url($drush_uri, PHP_URL_HOST);
      }

      // Get URI from drush conext.
      if (empty($host) &&  $drush_uri = drush_get_context('DRUSH_URI', NULL)) {
        $host = parse_url($drush_uri, PHP_URL_HOST);
      }

      // Get URI from server variable.
      if (empty($host) && isset($_SERVER['VIRTUAL_HOST'])) {
        $host = $_SERVER['VIRTUAL_HOST'];
      }

      $url = $host . '/' . ltrim($url, '/');
    }

    $curl_session = curl_init($url);
    curl_setopt_array($curl_session, $curl_options);
    curl_exec($curl_session);
    $http_code = curl_getinfo($curl_session, CURLINFO_HTTP_CODE);
    if ($http_code === 0) {
      $http_code = curl_error($curl_session);
    }
    curl_close($curl_session);

    return $http_code;
  }
}
