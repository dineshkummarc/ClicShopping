<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Shipping\Table\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Shipping\Table\Table;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_Table = new Table();
    Registry::set('Table', $CLICSHOPPING_Table);

    $this->app = $CLICSHOPPING_Table;

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
