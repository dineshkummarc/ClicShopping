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
 * This class performs a security check on the admin backup directory listing.
 * It verifies if the backup directory is publicly accessible by making an HTTP HEAD request.
 */
class securityCheckExtended_admin_backup_directory_listing
{
  public $type = 'danger';
  public $has_doc = true;

  /**
   * Constructor method initializes the language definitions and sets the title property
   * for the admin backup directory listing security check module.
   *
   * @return void
   */
  public function __construct()
  {
    $CLICSHOPPING_Language = Registry::get('Language');

    $CLICSHOPPING_Language->loadDefinitions('modules/security_check/extended/admin_backup_directory_listing', null, null, 'Shop');

    $this->title = CLICSHOPPING::getDef('module_security_check_extended_admin_backup_directory_listing_title');
  }

  /**
   * Checks if the backup directory is not publicly accessible.
   *
   * @return bool True if the directory is not accessible (non-2xx response), false otherwise.
   */
  public function pass(): bool
  {
    return !$this->getHttpRequest(CLICSHOPPING::link('Shop/Core/ClicShopping/Work/Backups/'));
  }

  /**
   * Retrieves a formatted message definition for the admin backup directory listing.
   *
   * @return string The formatted message with placeholders replaced by the backup URL and path.
   */
  public function getMessage()
  {
    return CLICSHOPPING::getDef('module_security_check_extended_admin_backup_directory_listing_http_200', [
        'backups_url' => CLICSHOPPING::link('Shop/Core/ClicShopping/Work/Backups/'),
        'backups_path' => CLICSHOPPING::getConfig('http_path', 'Shop') . 'Core/ClicShopping/Work/Backups/'
      ]
    );
  }

  /**
   * Sends an HTTP GET request to the specified URL and returns whether it is accessible.
   *
   * @param string $url The URL to check.
   * @return bool True if the server returned a 2xx response, false otherwise.
   */
  public function getHttpRequest(string $url): bool
  {
    $data = ['url' => $url, 'method' => 'get'];

    if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
      $data['header'] = [
        'Authorization: Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $_SERVER['PHP_AUTH_PW'])
      ];
      $this->type = 'warning';
    }

    return HTTP::getResponse($data) !== false;
  }
}

