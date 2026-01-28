<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Marketing\Recommendations\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Marketing\Recommendations\Recommendations;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_Recommendations = new Recommendations();
    Registry::set('Recommendations', $CLICSHOPPING_Recommendations);

    $this->app = $CLICSHOPPING_Recommendations;

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
