<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Tools\MCP\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Tools\MCP\MCP;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_MCP = new MCP();
    Registry::set('MCP', $CLICSHOPPING_MCP);

    $this->app = Registry::get('MCP');

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
