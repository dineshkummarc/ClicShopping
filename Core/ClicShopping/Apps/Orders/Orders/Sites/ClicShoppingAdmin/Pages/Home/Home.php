<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Orders\Orders\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Orders\Orders\Orders;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    if (!Registry::exists('Orders')) {
      Registry::set('Orders', new Orders());
    }

    $this->app = Registry::get('Orders');

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
