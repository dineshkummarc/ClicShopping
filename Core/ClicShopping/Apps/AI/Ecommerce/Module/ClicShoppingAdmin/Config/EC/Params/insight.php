<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */


namespace ClicShopping\Apps\AI\Ecommerce\Module\ClicShoppingAdmin\Config\EC\Params;

use ClicShopping\OM\HTML;

class insight extends \ClicShopping\Apps\AI\Ecommerce\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{
  public $default = 'False';
  public int|null $sort_order = 10;

  protected function init()
  {
    $this->title = $this->app->getDef('cfg_ecommerce_insight_title');
    $this->description = $this->app->getDef('cfg_ecommerce_insight_description');
  }

  public function getInputField()
  {
    $value = $this->getInputValue();

    $input = HTML::radioField($this->key, 'True', $value, 'id="' . $this->key . '1" autocomplete="off"') . $this->app->getDef('cfg_ecommerce_insight_true') . ' ';
    $input .= HTML::radioField($this->key, 'False', $value, 'id="' . $this->key . '2" autocomplete="off"') . $this->app->getDef('cfg_ecommerce_insight_false');

    return $input;
  }
}