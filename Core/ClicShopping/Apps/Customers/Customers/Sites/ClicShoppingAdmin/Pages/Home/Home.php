<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Customers\Customers\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Customers\Customers\Customers;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_Customers = new Customers();
    Registry::set('Customers', $CLICSHOPPING_Customers);

    $this->app = Registry::get('Customers');

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
