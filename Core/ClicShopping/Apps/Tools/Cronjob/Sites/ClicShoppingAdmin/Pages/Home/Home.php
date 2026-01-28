<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Tools\Cronjob\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Tools\Cronjob\Cronjob;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_Cronjob = new Cronjob();
    Registry::set('Cronjob', $CLICSHOPPING_Cronjob);

    $this->app = $CLICSHOPPING_Cronjob;

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
