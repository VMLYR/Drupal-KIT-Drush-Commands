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
    $curl_options = [
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_HEADER         => TRUE,
      CURLOPT_FOLLOWLOCATION => TRUE,
      CURLOPT_ENCODING       => '',
      CURLOPT_AUTOREFERER    => TRUE,
      CURLOPT_CONNECTTIMEOUT => 120,
      CURLOPT_TIMEOUT        => 120,
      CURLOPT_MAXREDIRS      => 10,
      CURLOPT_SSL_VERIFYPEER => FALSE,
      CURLOPT_SSL_VERIFYHOST => FALSE,
      CURLOPT_NOBODY         => TRUE
    ];
    $url_threshold = intval($options['url-threshold']);
    $watchdog_error_threshold = (!is_null($options['watchdog-error-threshold'])) ? intval($options['watchdog-error-threshold']) : NULL;
    $watchdog_warning_threshold = (!is_null($options['watchdog-warning-threshold'])) ? intval($options['watchdog-warning-threshold']) : NULL;

    // Clean and place URLs into array with desired HTTP response keyed by URL.
    $option_urls = array_map('trim', array_filter(explode(',', $options['urls'])));
    $urls = [];
    foreach ($option_urls as $option_url) {
      list($url, $code) = explode('|', $option_url, 2);
      $urls[$url] = (isset($code)) ? intval($code) : 200;
    }

    // Clear watchdog logs if threshold is set.
    if (!is_null($watchdog_error_threshold) || !is_null($watchdog_warning_threshold)) {
      // @todo clear watchdog logs.
    }

    // Run through each URL.
    $url_failures = [];
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
        $url_failures[$url] = $returned_http_code;
      }
    }

    // Print url failures.
    if (!empty($url_failures)) {
      $this->io()->warning(dt('@count HTTP code mismatches found.', ['@count' => count($url_failures)]));

      $headers = ['URL', 'HTTP code', 'Desired HTTP code'];
      $rows = [];
      foreach ($url_failures as $url => $http_code) {
        $rows[] = [$url, $http_code, $urls[$url]];
      }
      $this->io()->table($headers, $rows);
    }

    // Throw error if URL check failures exceeded the desired threshold.
    if (count($url_failures) > $url_threshold) {
      throw new \Exception(dt('HTTP code mismatches exceeded threshold.'));
    }
    else {
      $this->io()->success(dt('Passed HTTP code check with @count mismatches.', ['@count' => count($url_failures)]));
    }

    // Check logs against threshold if threshold is set.
    if (!is_null($watchdog_error_threshold) || !is_null($watchdog_warning_threshold)) {
      // @todo get watchdog errors,
      // @todo alert user of errors and throw error.
    }
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
    if (!parse_url($url, PHP_URL_HOST)) {
      // Build a URI for the current site, if we were passed a path.
      $url = drush_get_context('DRUSH_URI') . '/' . ltrim($url, '/');
    }

    $curl_session = curl_init($url);
    curl_setopt_array($curl_session, $curl_options);
    curl_exec($curl_session);
    $http_code = curl_getinfo($curl_session, CURLINFO_HTTP_CODE);
    curl_close($curl_session);

    return $http_code;
  }
}
