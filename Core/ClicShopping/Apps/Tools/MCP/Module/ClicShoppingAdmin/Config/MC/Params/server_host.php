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

class server_host extends \ClicShopping\Apps\Tools\MCP\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{
    public $default = 'localhost';
    public int|null $sort_order = 10;

    protected function init()
    {
        $this->title = $this->app->getDef('cfg_mcp_server_host_title');
        $this->description = $this->app->getDef('cfg_mcp_server_host_description');
    }
}
