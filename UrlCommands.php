<?php

namespace Drush\Commands\kit_drush;

use Drush\Commands\DrushCommands;
use Drush\Drush;

/**
 * Command to run checks response status on a list of URLs.
 */
class UrlCommands extends DrushCommands {

  /**
   * Check response status on a list of urls.
   *
   * @option urls
   *   Urls to check. Optional HTTP response code can be appended using |.
   *   example: --urls='/url/path/here|200','/as|404', 'http://site.docksal|301'
   * @option url-file
   *   Path to a yaml file with a list of URLs to check. The file should be a
   *   list of URLs as keys, with values being the desired HTTP response code.
   * @option url-threshold
   *   Number of URLs to allow to fail and still pass check.
   * @option watchdog-error-threshold
   *   Number of watchdog errors allowed during URL check and still pass check.
   * @option watchdog-warning-threshold
   *   Number of watchdog warnings allowed during URL check and still pass check.
   * @command kit-url-check
   * @usage drush url-check /cool/page,/another/cool/page
   * @aliases kuc, kcheck, url-check
   */
  public function check($options = ['urls' => '', 'url-file' => NULL, 'url-threshold' => 0, 'watchdog-error-threshold' => NULL, 'watchdog-warning-threshold' => NULL]) {
    $curl_options = array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HEADER         => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_ENCODING       => "",
      CURLOPT_AUTOREFERER    => true,
      CURLOPT_CONNECTTIMEOUT => 120,
      CURLOPT_TIMEOUT        => 120,
      CURLOPT_MAXREDIRS      => 10,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_SSL_VERIFYHOST => false,
      CURLOPT_NOBODY         => true
    );
    $url_threshold = intval($options['url-threshold']);
    $watchdog_error_threshold = (!is_null($options['watchdog-error-threshold'])) ? intval($options['watchdog-error-threshold']) : NULL;
    $watchdog_warning_threshold = (!is_null($options['watchdog-warning-threshold'])) ? intval($options['watchdog-warning-threshold']) : NULL;

    // Clean and place URLs into array with desired HTTP response keyed by URL.
    $option_urls = array_map('trim', array_filter(explode(',', $options['urls'])));
    $urls = [];
    foreach ($option_urls as $option_url) {
      list($url, $code) = explode('|', $option_url, 2);
      $urls[$url] = (isset($code)) ? $code : 200;
    }

    // Clear watchdog logs if threshold is set.
    if (!is_null($watchdog_error_threshold) || !is_null($watchdog_warning_threshold)) {
      // @todo clear watchdog logs.
    }

    // Run through each URL.
    $url_failures = [];
    foreach($urls as $url) {
      $host = parse_url($url, PHP_URL_HOST);
      if (!$host) {
        // Build a URI for the current site, if we were passed a path.
        $site = drush_get_context('DRUSH_URI');
        $host = parse_url($site, PHP_URL_HOST);
        $url = $site . '/' . ltrim($url, '/');
      }

      // @todo if fail, key by url, set data as response
    }

    // Print url failures.
    // @todo print failures.
    if (count($url_failures) > $url_threshold) {
      // If over threshhold, alert the user and fail.
      // @todo alert user, throw error.
    }

    // Check logs against threshold if threshold is set.
    if (!is_null($watchdog_error_threshold) || !is_null($watchdog_warning_threshold)) {
      // @todo get watchdog errors,
      // @todo alert user of errors and throw error.
    }

  }
}
