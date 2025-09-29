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

class display_browser_json extends \ClicShopping\Apps\Tools\MCP\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{
    public $default = 'False';
    public int|null $sort_order = 45;

    protected function init()
    {
      $this->title = $this->app->getDef('cfg_mcp_display_browser_json_title');
      $this->description = $this->app->getDef('cfg_mcp_display_browser_json_description');
    }

  public function getInputField()
  {
    $value = $this->getInputValue();

    $input = HTML::radioField($this->key, 'True', $value, 'id="' . $this->key . '1" autocomplete="off"') . $this->app->getDef('cfg_chatgpt_display_browser_json_status_true') . ' ';
    $input .= HTML::radioField($this->key, 'False', $value, 'id="' . $this->key . '2" autocomplete="off"') . $this->app->getDef('cfg_chatgpt_display_browser_json_status_false');

    return $input;
  }
}