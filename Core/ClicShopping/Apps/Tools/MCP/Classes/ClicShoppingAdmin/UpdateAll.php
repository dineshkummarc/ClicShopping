<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Tools\MCP\Classes\ClicShoppingAdmin;

use ClicShopping\OM\Cache;
use ClicShopping\OM\Registry;

class UpdateAll extends \ClicShopping\OM\Domains\PagesActionsAbstract
{
  public mixed $app;

  public function __construct()
  {
    $this->app = Registry::get('MCP');
  }

  public function execute()
  {
    $page = (isset($_GET['page']) && is_numeric($_GET['page'])) ? (int)$_GET['page'] : 1;

    if (!Registry::exists('McpAdmin')) {
      Registry::set('McpAdmin', new McpAdmin());
    }

    $McpAdmin = Registry::get('McpAdmin');

    $McpAdmin->updateAllMcp();

    Cache::clear('mcp');

    $this->app->redirect('Mcp&page=' . $page);
  }
}