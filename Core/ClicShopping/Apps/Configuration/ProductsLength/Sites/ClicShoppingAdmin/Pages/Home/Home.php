<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ProductsLength\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Configuration\ProductsLength\ProductsLength;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_ProductsLength = new ProductsLength();
    Registry::set('ProductsLength', $CLICSHOPPING_ProductsLength);

    $this->app = $CLICSHOPPING_ProductsLength;

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
