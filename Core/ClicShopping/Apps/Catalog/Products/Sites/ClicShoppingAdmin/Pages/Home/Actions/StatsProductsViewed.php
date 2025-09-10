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
 * Class StatsProductsViewed
 *
 * This action class is responsible for displaying the statistics of products viewed in the admin interface.
 * It sets the appropriate page file and loads the necessary language definitions.
 */
class StatsProductsViewed extends \ClicShopping\OM\PagesActionsAbstract
{
  /**
   * Execute the action to display product view statistics.
   *
   * This method sets the page file to 'stats_products_viewed.php' and loads the relevant language definitions
   * for displaying product view statistics in the admin interface.
   */
  public function execute()
  {
    $CLICSHOPPING_Products = Registry::get('Products');

    $this->page->setFile('stats_products_viewed.php');

    $CLICSHOPPING_Products->loadDefinitions('Sites/ClicShoppingAdmin/stats_products_viewed');
  }
}