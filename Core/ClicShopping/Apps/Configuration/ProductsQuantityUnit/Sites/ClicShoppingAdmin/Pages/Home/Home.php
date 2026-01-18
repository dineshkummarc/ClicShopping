<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ProductsQuantityUnit\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Configuration\ProductsQuantityUnit\ProductsQuantityUnit;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_ProductsQuantityUnit = new ProductsQuantityUnit();
    Registry::set('ProductsQuantityUnit', $CLICSHOPPING_ProductsQuantityUnit);

    $this->app = $CLICSHOPPING_ProductsQuantityUnit;

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
