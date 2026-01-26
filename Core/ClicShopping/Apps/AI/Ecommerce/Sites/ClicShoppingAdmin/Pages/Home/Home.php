<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\AI\Ecommerce\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\AI\Ecommerce\Ecommerce;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_Ecommerce = new Ecommerce();
    Registry::set('Ecommerce', $CLICSHOPPING_Ecommerce);

    $this->app = $CLICSHOPPING_Ecommerce;

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
