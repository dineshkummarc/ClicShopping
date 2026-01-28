<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Tools\Backup\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Tools\Backup\Backup;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_Backup = new Backup();
    Registry::set('Backup', $CLICSHOPPING_Backup);

    $this->app = Registry::get('Backup');

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
