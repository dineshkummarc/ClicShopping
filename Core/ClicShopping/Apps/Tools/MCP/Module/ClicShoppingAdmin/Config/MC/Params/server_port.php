<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Tools\MCP\Module\ClicShoppingAdmin\Config\MC\Params;

class server_port extends \ClicShopping\Apps\Tools\MCP\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{
  public $default = '3000';
  public int|null $sort_order = 20;

    protected function init()
    {
        $this->title = $this->app->getDef('cfg_mcp_server_port_title');
        $this->description = $this->app->getDef('cfg_mcp_server_port_description');
    }
}
