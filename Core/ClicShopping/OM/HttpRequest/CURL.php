<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\OM\HttpRequest;
use ClicShopping\OM\HTTP;

/**
 * CURL class for handling HTTP requests using cURL
 *
 * This class provides methods to execute HTTP requests using cURL,
 * including handling redirects and SSL verification.
 */
class Curl {
  /**
   * Executes an HTTP request using cURL.
   *
   * @param array $parameters An associative array containing the request parameters:
   *                          - 'server': An array with 'scheme', 'host', 'port', 'path', and optionally 'query'.
   *                          - 'method': The HTTP method ('get' or 'post').
   *                          - 'parameters': The request body for POST requests.
   *                          - 'header': An array of headers to include in the request.
   *                          - 'cafile': Path to the CA file for SSL verification.
   *                          - 'certificate': Path to the client certificate for SSL.
   *                          - 'redir_counter': Counter for redirects (optional).
   *
   * @return string The response body from the HTTP request.
   */
  public static function execute($parameters)
  {
    $url = $parameters['server']['scheme'] . '://' .
      $parameters['server']['host'] .
      $parameters['server']['path'] .
      (isset($parameters['server']['query']) ? '?' . $parameters['server']['query'] : '');

    $curl = curl_init($url);

    $curl_options = [
      CURLOPT_PORT => $parameters['server']['port'],
      CURLOPT_HEADER => true,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FORBID_REUSE => true,
      CURLOPT_FRESH_CONNECT => true,
      CURLOPT_FOLLOWLOCATION => false
    ];

    if (!empty($parameters['header'])) {
      $curl_options[CURLOPT_HTTPHEADER] = $parameters['header'];
    }

    if (isset($parameters['cafile']) && file_exists($parameters['cafile'])) {
      $curl_options[CURLOPT_CAINFO] = $parameters['cafile'];
    }

    if (isset($parameters['certificate'])) {
      $curl_options[CURLOPT_SSLCERT] = $parameters['certificate'];
    }

    if ($parameters['method'] === 'post') {
      $curl_options[CURLOPT_POST] = true;
      $curl_options[CURLOPT_POSTFIELDS] = $parameters['parameters'];
    }

    curl_setopt_array($curl, $curl_options);

    $result = curl_exec($curl);

    if ($result === false) {
      trigger_error(curl_error($curl));
      curl_close($curl);
      return false;
    }

    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $headers = trim(substr($result, 0, $header_size));
    $body = trim(substr($result, $header_size));

    curl_close($curl);

    if (($http_code === 301 || $http_code === 302) && (!isset($parameters['redir_counter']) || $parameters['redir_counter'] < 6)) {

      if (!isset($parameters['redir_counter'])) {
        $parameters['redir_counter'] = 0;
      }

      if (preg_match('/(Location:|URI:)(.*?)\n/i', $headers, $matches)) {
        $redir_url = trim(array_pop($matches));
        $parameters['redir_counter']++;

        $parsed = parse_url($redir_url);
        $scheme = $parsed['scheme'] ?? 'http';

        $redir_parameters = [
          'server' => [
            'scheme' => $scheme,
            'host'   => $parsed['host'],
            'port'   => $parsed['port'] ?? ($scheme === 'https' ? 443 : 80),
            'path'   => $parsed['path'] ?? '/',
            'query'  => $parsed['query'] ?? ''
          ],
          'method'         => $parameters['method'],
          'parameters'     => $parameters['parameters'] ?? '',
          'header'         => $parameters['header'] ?? [],
          'cafile'         => $parameters['cafile'] ?? null,
          'certificate'    => $parameters['certificate'] ?? null,
          'redir_counter'  => $parameters['redir_counter']
        ];

        $response = HTTP::getResponse($redir_parameters);
        $body = $response['body'] ?? '';
      }
    }

    return $body;
  }

  /**
   * Checks if cURL is available.
   *
   * @return bool True if cURL is available, false otherwise.
   */
  public static function canUse() {
    return function_exists('curl_init');
  }
}