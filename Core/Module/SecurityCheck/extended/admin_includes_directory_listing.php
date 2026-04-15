<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\HTTP;
use ClicShopping\OM\Registry;

/**
 * Class that performs a security check to verify if the "admin/Core" directory is correctly secured.
 * It verifies that the directory does not provide a directory listing by checking the HTTP response code.
 */
class securityCheckExtended_admin_includes_directory_listing
{
  public $type = 'warning';
  public $has_doc = true;

  /**
   * Constructor method to initialize the security check module.
   * Loads necessary language definitions and sets the title property.
   *
   * @return void
   */
  public function __construct()
  {
    $CLICSHOPPING_Language = Registry::get('Language');

    $CLICSHOPPING_Language->loadDefinitions('modules/security_check/extended/admin_includes_directory_listing', null, null, 'Shop');
    /**
     *
     */
      $this->title = CLICSHOPPING::getDef('module_security_check_extended_admin_includes_directory_listing_http_200');
  }

  /**
   *
   * @return bool Returns true if the HTTP response code is not 200, otherwise returns false.
   */
  public function pass()
  {
    $request = $this->getHttpRequest(CLICSHOPPING::link('Core/'));

    return $request['http_code'] != 200;
  }

  /**
   * Retrieves the message associated with the security check.
   *
   * @return string The message defined in the context of the module's security check.
   */
  public function getMessage()
  {
    return CLICSHOPPING::getDef('module_security_check_extended_admin_includes_directory_listing_http_200');
  }

  /**
   * Sends an HTTP HEAD request to a given URL and returns information about the request.
   *
   * @param string $url The target URL for the HTTP request.
   * @return mixed An array of information about the HTTP request on success, or the string 'error' if the request fails.
   */
  public function getHttpRequest(string $url): array
  {
    $result = HTTP::getResponse(['url' => $url, 'method' => 'head']);

    return ['http_code' => $result !== false ? 200 : 0];
  }
}