<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Catalog\Products\Sites\ClicShoppingAdmin\Pages\Home\Actions\Products;

use ClicShopping\OM\HTML;
use ClicShopping\OM\Registry;

class Insert extends \ClicShopping\OM\Domains\PagesActionsAbstract
{
  public mixed $app;
  protected ? string $currentCategoryId;
  protected ?array $moveToCategoryId;

  public function __construct()
  {
    $this->app = Registry::get('Products');

    $this->currentCategoryId = HTML::sanitize($_POST['cPath']);
    $this->moveToCategoryId = $_POST['move_to_category_id'];
  }

  public function execute()
  {
    if (isset($_GET['Insert'], $_GET['Products'])) {
      $CLICSHOPPING_Hooks = Registry::get('Hooks');
      $CLICSHOPPING_ProductsAdmin = Registry::get('ProductsAdmin');

      $categories_id = 0;

      if($this->currentCategoryId) {
        $categories_id = $this->currentCategoryId;
      }

      if($this->moveToCategoryId) {
        $categories_id = $this->moveToCategoryId;
      }

      $CLICSHOPPING_ProductsAdmin->save(null, 'Insert');

      $last_products_id = $this->app->db->lastInsertId();

      $array =  ['categories_id ' => $categories_id, 'products_id' => $last_products_id];

      $CLICSHOPPING_Hooks->call('Products', 'Insert', $array);

      $this->app->redirect('Products&cPath=' . (int)$categories_id);
    } else {
      $this->app->redirect('Products');
    }
  }
}