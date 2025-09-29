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

use ClicShopping\Apps\Tools\MCP\Classes\MC\McpMonitor;
use ClicShopping\OM\HTML;

class alert_thresholds extends \ClicShopping\Apps\Tools\MCP\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{
    public $default = 20;
    public int|null $sort_order = 50;

    /**
     * Initialize the Tools parameter
     */
    protected function init()
    {
        $this->title = $this->app->getDef('cfg_mcp_alert_thresholds_title');
        $this->description = $this->app->getDef('cfg_mcp_alert_thresholds_description');
    }

    public function getInputField()
    {
      /*
        // Error Rate Threshold
        $input .= '<label>Error Rate Threshold (%)</label>';
        $input .= '<small class="form-text text-muted">Alert when error rate exceeds this percentage</small>';
        */
        $input = HTML::inputField($this->key, 20, null, 'id="' . $this->key . '1" autocomplete="off" min="1" max="100"') . $this->app->getDef('cfg_chatgpt_error_alert_threshold') . ' ';

        return $input;
    }
}
