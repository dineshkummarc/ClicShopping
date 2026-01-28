<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\AI\Ecommerce\Module\Hooks\ClicShoppingAdmin\Manufacturers;

use ClicShopping\Apps\Configuration\ChatGpt\ChatGpt as ChatGptApp;
use ClicShopping\OM\Registry;

class DeleteAll implements \ClicShopping\OM\Modules\HooksInterface
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
    if (!Registry::exists('ChatGpt')) {
      Registry::set('ChatGpt', new ChatGptApp());
    }

    $this->app = Registry::get('ChatGpt');
  }

  /**
   * Processes the execution related to product data management and delete in the database.
   * This includes generating products_embedding, based on product information.
   *
   * @return void
   */
  public function execute()
  {
    if (isset($_POST['selected']) && is_array($_POST['selected']) && isset($_GET['DeleteAll'])) {
      foreach ($_POST['selected'] as $items) {
        if (isset($items)) {
          $this->app->db->delete('manufacturers_embedding', ['entity_id' => (int)$items]);
        }
      }
    }
  }
}