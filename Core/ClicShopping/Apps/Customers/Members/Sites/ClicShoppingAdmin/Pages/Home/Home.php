<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Customers\Members\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Customers\Members\Members;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_Members = new Members();
    Registry::set('Members', $CLICSHOPPING_Members);

    $this->app = Registry::get('Members');

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
