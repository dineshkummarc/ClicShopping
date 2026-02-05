<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\AI\Ecommerce\Module\ClicShoppingAdmin\Config\ACP\Params;

use ClicShopping\OM\HTML;

class api_key_openai_retail extends \ClicShopping\Apps\AI\Ecommerce\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{

  public $default = '';
  public int|null $sort_order = 20;

  protected function init()
  {
    $this->title = $this->app->getDef('cfg_ecommerce_acp_api_key_openai_retails_title');
    $this->description = $this->app->getDef('cfg_ecommerce_acp_api_key_openai_retails_description');
  }

  public function getInputField()
  {
    $value = $this->getInputValue();

    $input = HTML::passwordField($this->key,  $value, 'id="' . $this->key . '" autocomplete="off"');

    return $input;
  }
}
