<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\OrderTotal\SubTotal\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\OrderTotal\SubTotal\SubTotal;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_SubTotal = new SubTotal();
    Registry::set('SubTotal', $CLICSHOPPING_SubTotal);

    $this->app = $CLICSHOPPING_SubTotal;

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
