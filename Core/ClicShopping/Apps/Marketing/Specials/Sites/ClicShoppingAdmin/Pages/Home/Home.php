<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Marketing\Specials\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Marketing\Specials\Specials;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_Specials = new Specials();
    Registry::set('Specials', $CLICSHOPPING_Specials);

    $this->app = Registry::get('Specials');

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
