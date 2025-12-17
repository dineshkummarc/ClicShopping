<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Module\ClicShoppingAdmin\Config\CH\Params;

use ClicShopping\OM\HTML;

/**
 * Class verbosity
 * @package ClicShopping\Apps\Configuration\ChatGpt\Module\ClicShoppingAdmin\Config\CH\Params
 *
 * This class is used to set the verbosity level of the ChatGPT responses.
 * It allows the user to choose between low, medium, and high verbosity levels.
 */
class verbosity extends \ClicShopping\Apps\Configuration\ChatGpt\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{
  public $default = 'medium';
  public int|null $sort_order = 43;

  /**
   * @return void
   */
  protected function init()
  {
    $this->title = $this->app->getDef('cfg_chatgpt_verbosity_title');
    $this->description = $this->app->getDef('cfg_chatgpt_verbosity_description');
  }

  /**
   * Get the value of the input field
   * @return string
   */
  public function getInputField()
  {
    $value = $this->getInputValue();

    $array = [
      ['id' => 'text', 'text' => $this->app->getDef('cfg_chatgpt_response_verbosity_select')],
      ['id' => 'low', 'text' => $this->app->getDef('cfg_chatgpt_response_verbosity_low')],
      ['id' => 'medium', 'text' => $this->app->getDef('cfg_chatgpt_response_verbosity_medium')],
      ['id' => 'high', 'text' => $this->app->getDef('cfg_chatgpt_response_verbosity_high')],
    ];

    $input = HTML::selectField($this->key, $array, $value, 'id="model_verbosity"');

    return $input;
  }
}