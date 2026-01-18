<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Shipping\Item\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Shipping\Item\Item;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_Item = new Item();
    Registry::set('Item', $CLICSHOPPING_Item);

    $this->app = $CLICSHOPPING_Item;

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
