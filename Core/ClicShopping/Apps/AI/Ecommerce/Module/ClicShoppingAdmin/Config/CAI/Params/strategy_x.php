<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */


namespace ClicShopping\Apps\AI\Ecommerce\Module\ClicShoppingAdmin\Config\CAI\Params;

use ClicShopping\OM\HTML;

class strategy_x extends \ClicShopping\Apps\AI\Ecommerce\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{
  public $default = 'visibility';
  public int|null $sort_order = 50;

  public function getInputField()
  {
    $value = $this->getInputValue();

    $input = HTML::radioField($this->key, 'visibility', $value, 'id="' . $this->key . '1" autocomplete="off"') . $this->app->getDef('cfg_ecommerce_cockpit_ai_strategy_x_visibility') . ' ';
    $input .= HTML::radioField($this->key, 'quality', $value, 'id="' . $this->key . '2" autocomplete="off"') . $this->app->getDef('cfg_ecommerce_cockpit_ai_strategy_x_quality');
    $input .= HTML::radioField($this->key, 'completeness', $value, 'id="' . $this->key . '2" autocomplete="off"') . $this->app->getDef('cfg_ecommerce_cockpit_ai_strategy_x_completeness');

    return $input;
  }

  protected function init()
  {
    $this->title = $this->app->getDef('cfg_ecommerce_cockpit_ai_strategy_x_title');
    $this->description = $this->app->getDef('cfg_ecommerce_cockpit_ai_strategy_x_description');
  }
}