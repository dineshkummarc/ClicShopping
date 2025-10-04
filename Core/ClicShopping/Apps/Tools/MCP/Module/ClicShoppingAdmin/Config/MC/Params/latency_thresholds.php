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

use ClicShopping\OM\HTML;

class latency_thresholds extends \ClicShopping\Apps\Tools\MCP\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{
    public $default = 1000;
    public int|null $sort_order = 55;

    /**
     * Initialize the Tools parameter
     */
    protected function init()
    {
        $this->title = $this->app->getDef('cfg_mcp_alert_latency_thresholds_title');
        //Latency Threshold (ms)
        $this->description = $this->app->getDef('cfg_mcp_alert_latency_thresholds_description');
        //        $input .= '<small class="form-text text-muted">Alert when latency exceeds this value in milliseconds</small>';
    }

    public function getInputField()
    {
       $value = $this->getInputValue();

       $input = HTML::inputField($this->key, 1000, $value, 'id="' . $this->key . '" autocomplete="off" min="100" step="100"');

       return $input;
    }
}
