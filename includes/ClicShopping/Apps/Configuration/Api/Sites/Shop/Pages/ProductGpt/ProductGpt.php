<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\Api\Sites\Shop\Pages\ProductGpt;

use ClicShopping\Apps\Configuration\Api\Classes\Shop\ApiShop;
use ClicShopping\OM\HTML;
use ClicShopping\OM\Registry;

class ProductGpt extends \ClicShopping\OM\PagesAbstract
{
  protected string|null $file = null;
  protected bool $use_site_template = false;
  private mixed $lang;
  private mixed $Db;

  /**
   * Initializes the API handling logic based on the HTTP request method.
   * This method sets up necessary dependencies from the registry and processes the API call
   * according to the request method (GET, POST, DELETE, etc.). It includes functionality for
   * authentication and permission checks using tokens and status validation.
   *
   * @return bool|void Returns false if the API status is disabled or on invalid requests,
   *                   and outputs the response body for valid requests.
   */
  protected function init()
  {
    $this->lang = Registry::get('Language');
    $this->Db = Registry::get('Db');

    if (!\defined('CLICSHOPPING_APP_API_AI_STATUS') && CLICSHOPPING_APP_API_AI_STATUS == 'False') {
      return false;
    }

    $requestMethod = ApiShop::requestMethod();

// Handle the event
    switch ($requestMethod) {
      case 'GET':
        $token = HTML::sanitize($_GET['token']);
        $result = ApiShop::checkToken($token);
        $check = $this->statusCheck('get_product_status', $token);

        if (empty($result) || $check == 0) {
          $response = ApiShop::notFoundResponse();
          Registry::get('Session')->kill();
        } else {
          $response = self::getProductGpt();
        }
        break;
      case 'DELETE':
        break;
      case 'POST':
        break;
      case 'PUT':
        break;
      default:
        $response = ApiShop::notFoundResponse();
        Registry::get('Session')->kill();
        break;
    }

    if ($response['body']) {
      echo $response['body'];
    }

    exit;
  }

  /**
   * Retrieves the product data through the API and returns an appropriate response.
   *
   * @return array The HTTP response containing either the product data or a not-found message.
   */
  private static function getProductGpt(): array
  {
    $CLICSHOPPING_Hooks = Registry::get('Hooks');

    $result = $CLICSHOPPING_Hooks->call('Api', 'ApiGetProductGpt');

    if (empty($result)) {
      $response = ApiShop::notFoundResponse();
    } else {
      $response = ApiShop::HttpResponseOk($result);
    }

    ApiShop::clearCache();

    return $response;
  }

  /**
   * Executes a database query to retrieve an integer value associated with a given column and token.
   *
   * @param string $string The column name to select in the query.
   * @param string $token The session identifier used to bind the query parameter.
   * @return int Returns the integer value retrieved from the specified column in the database.
   */
  private function statusCheck(string $string, string $token): int
  {
    $QstatusCheck = $this->Db->prepare('select a.' . $string . '
                                          from :table_api a,
                                               :table_api_session ase
                                          where a.api_id = ase.api_id
                                          and ase.session_id = :session_id  
                                        ');
    $QstatusCheck->bindValue('session_id', $token);

    $QstatusCheck->execute();

    $result = $QstatusCheck->valueInt($string);

    return $result;
  }
}
