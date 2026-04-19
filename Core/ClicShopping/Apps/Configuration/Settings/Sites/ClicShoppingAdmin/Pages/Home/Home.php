<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\Settings\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Configuration\Settings\Settings;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_Settings = new Settings();
    Registry::set('Settings', $CLICSHOPPING_Settings);

    $this->app = $CLICSHOPPING_Settings;

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
