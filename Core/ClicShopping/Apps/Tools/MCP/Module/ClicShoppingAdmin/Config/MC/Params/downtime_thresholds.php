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

class downtime_thresholds extends \ClicShopping\Apps\Tools\MCP\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{
    public $default = 300;
    public int|null $sort_order = 60;

    /**
     * Initialize the Tools parameter
     */
    protected function init()
    {
        $this->title = $this->app->getDef('cfg_mcp_alert_downtime_thresholds_title');
        //downtime Threshold (seconds)
        $this->description = $this->app->getDef('cfg_mcp_alert_downtime_thresholds_description');
      //$input .= '<small class="form-text text-muted">Alert after this many seconds of downtime</small>';
    }

    public function getInputField()
    {
      $value = $this->getInputValue();

      $input = HTML::inputField($this->key, 300, $value, 'id="' . $this->key . '" autocomplete="off" min="60" step="60"');

       return $input;
    }
}
