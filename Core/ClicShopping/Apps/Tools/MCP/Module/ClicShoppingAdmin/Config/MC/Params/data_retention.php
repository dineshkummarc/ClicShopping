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

use ClicShopping\Apps\Tools\MCP\Classes\ClicShoppingAdmin\McpMonitor;
use ClicShopping\OM\HTML;

class data_retention extends \ClicShopping\Apps\Tools\MCP\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{
  public $default = 7;
  public int|null $sort_order = 65;

  /**
   * Initialize the Tools parameter
   */
  protected function init()
  {
      $this->title = $this->app->getDef('cfg_mcp_retention_title');
      $this->description = $this->app->getDef('cfg_mcp_retention_description');
  }

  /**
   * Get the input field for the data retention setting
   *
   * @return string HTML select field for data retention options
   */
  public function getInputField()
  {
    $value = $this->getInputValue();

    $array = McpMonitor::getRetentionOptions();

    $input = HTML::selectField($this->key, $array, $value, 'id="retention_period"');

    return $input;
  }
}
