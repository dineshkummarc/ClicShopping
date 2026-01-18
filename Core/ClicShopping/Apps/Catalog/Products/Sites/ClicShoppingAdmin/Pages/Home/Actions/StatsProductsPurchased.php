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
 * Class StatsProductsPurchased
 *
 * This action class is responsible for setting up the stats_products_purchased page in the admin interface.
 * It loads the necessary definitions and sets the appropriate file for rendering the page.
 */
class StatsProductsPurchased extends \ClicShopping\OM\Domains\PagesActionsAbstract
{
  /**
   * Execute the action to set up the stats_products_purchased page.
   *
   * This method sets the file to be used for the stats_products_purchased page and loads
   * the necessary language definitions from the Products app.
   */
  public function execute()
  {
    $CLICSHOPPING_Products = Registry::get('Products');

    $this->page->setFile('stats_products_purchased.php');

    $CLICSHOPPING_Products->loadDefinitions('Sites/ClicShoppingAdmin/stats_products_purchased');
  }
}