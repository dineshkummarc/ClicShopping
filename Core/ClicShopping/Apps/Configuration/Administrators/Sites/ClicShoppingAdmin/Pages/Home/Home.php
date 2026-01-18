<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\Administrators\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Configuration\Administrators\Administrators;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_Administrators = new Administrators();
    Registry::set('Administrators', $CLICSHOPPING_Administrators);

    $this->app = $CLICSHOPPING_Administrators;

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
