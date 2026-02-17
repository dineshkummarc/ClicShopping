<?php
/**
 * WebSearch Global Enable Parameter
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Module\ClicShoppingAdmin\Config\ASY\Params;

use ClicShopping\OM\HTML;

/**
 * WebSearch Global Enable/Disable Parameter
 * 
 * Controls whether WebSearch functionality is globally enabled.
 * When disabled, no agents can perform web searches regardless of individual settings.
 * 
 * @package ClicShopping\Apps\Configuration\ChatGpt\Module\ClicShoppingAdmin\Config\ASY\Params
 * @since 4.2.0
 */
class websearch_global_status extends \ClicShopping\Apps\Configuration\ChatGpt\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{
  public $default = 'True';
  public int|null $sort_order = 40;
  public bool $app_configured = true;

  /**
   * Initialize parameter configuration
   */
  protected function init()
  {
    $this->title = $this->app->getDef('cfg_chatgpt_websearch_global_status_title');
    $this->description = $this->app->getDef('cfg_chatgpt_websearch_global_status_description');
  }

  /**
   * Get input field HTML
   * 
   * @return string HTML for radio button input
   */
  public function getInputField()
  {
    $value = $this->getInputValue();

    $input = HTML::radioField($this->key, 'True', $value, 'id="' . $this->key . '1" autocomplete="off"') . $this->app->getDef('cfg_chatgpt_status_true') . ' ';
    $input .= HTML::radioField($this->key, 'False', $value, 'id="' . $this->key . '2" autocomplete="off"') . $this->app->getDef('cfg_chatgpt_status_false');

    return $input;
  }
}
