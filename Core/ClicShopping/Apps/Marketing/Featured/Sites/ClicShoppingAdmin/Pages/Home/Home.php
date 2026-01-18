<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Marketing\Featured\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Marketing\Featured\Featured;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_Featured = new Featured();
    Registry::set('Featured', $CLICSHOPPING_Featured);

    $this->app = $CLICSHOPPING_Featured;

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
