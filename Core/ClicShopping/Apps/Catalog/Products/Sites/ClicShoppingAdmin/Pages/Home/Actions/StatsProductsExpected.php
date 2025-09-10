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
 * Class StatsProductsExpected
 *
 * This action class is responsible for setting up the environment to display
 * statistics about expected products in the admin interface.
 */
class StatsProductsExpected extends \ClicShopping\OM\PagesActionsAbstract
{
  /**
   * Execute the action to display expected products statistics.
   *
   * This method sets the appropriate template file for rendering the statistics
   * and loads the necessary language definitions for the admin interface.
   */
  public function execute()
  {
    $CLICSHOPPING_Products = Registry::get('Products');

    $this->page->setFile('stats_products_expected.php');

    $CLICSHOPPING_Products->loadDefinitions('Sites/ClicShoppingAdmin/stats_products_expected');
  }
}