<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Catalog\Products\Sites\ClicShoppingAdmin\Pages\Home\Actions;

use ClicShopping\OM\Registry;

/**
 * Class StatsProductsLowStock
 *
 * This action class is responsible for displaying the low stock products statistics page in the admin interface.
 * It sets the appropriate template file and loads the necessary language definitions.
 */
class StatsProductsLowStock extends \ClicShopping\OM\PagesActionsAbstract
{
  /**
   * Execute the action to display low stock products statistics.
   *
   * This method sets the template file for the low stock statistics page and loads the relevant language definitions.
   */
  public function execute()
  {
    $CLICSHOPPING_Products = Registry::get('Products');

    $this->page->setFile('stats_products_low_stock.php');

    $CLICSHOPPING_Products->loadDefinitions('Sites/ClicShoppingAdmin/stats_products_low_stock');
  }
}