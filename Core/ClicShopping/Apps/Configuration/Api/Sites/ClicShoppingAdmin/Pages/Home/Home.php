<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\Api\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Configuration\Api\Api;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_Api = new Api();
    Registry::set('Api', $CLICSHOPPING_Api);

    $this->app = $CLICSHOPPING_Api;

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
