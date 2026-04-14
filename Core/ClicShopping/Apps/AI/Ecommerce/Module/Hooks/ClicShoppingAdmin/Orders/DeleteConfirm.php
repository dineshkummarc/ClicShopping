<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\AI\Ecommerce\Module\Hooks\ClicShoppingAdmin\Orders;

use ClicShopping\OM\HTML;
use ClicShopping\OM\Registry;
use ClicShopping\Apps\AI\Ecommerce\Ecommerce as EcommerceApp;

class DeleteConfirm implements \ClicShopping\OM\Modules\HooksInterface
{
  public mixed $app;

  /**
   * Class constructor.
   *
   * Initializes the ChatGptApp instance in the Registry if it doesn't already exist,
   * and loads the necessary definitions for the application.
   *
   * @return void
   */
  public function __construct()
  {
    if (!Registry::exists('Ecommerce')) {
      Registry::set('Ecommerce', new EcommerceApp());
    }

    $this->app = Registry::get('Ecommerce');
  }

  /**
   * Processes the execution related to product data management and delete in the database.
   * This includes generating products_embedding, based on product information.
   *
   * @return void
   */
  public function execute()
  {
    if (isset($_GET['DeleteConfirm'], $_GET['oID'])) {
      $order_id = HTML::sanitize($_GET['oID']);
      $this->app->db->delete('orders_embedding', 'entity_id', $order_id);
    }
}
}