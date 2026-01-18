<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Tools\Apps\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Tools\Apps\Apps;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_Apps = new Apps();
    Registry::set('Apps', $CLICSHOPPING_Apps);

    $this->app = Registry::get('Apps');

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
