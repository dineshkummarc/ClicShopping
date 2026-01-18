<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Catalog\Products\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Catalog\Products\Products;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_Products = new Products();
    Registry::set('Products', $CLICSHOPPING_Products);

    $this->app = Registry::get('Products');

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
