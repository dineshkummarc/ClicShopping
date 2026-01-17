<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Module\ClicShoppingAdmin\Config\RA\Params;

use ClicShopping\AI\Domains\CoreAI\Embedding\NewVector;
use ClicShopping\OM\HTML;

class reasoning_mode extends \ClicShopping\Apps\Configuration\ChatGpt\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{
  public $default = 'chain_of_thought';
  public int|null $sort_order = 120;

  protected function init()
  {
    $this->title = $this->app->getDef('cfg_chatgpt_reasoning_mode_title');
    $this->description = $this->app->getDef('cfg_chatgpt_reasoning_mode_description');
  }

  public function getInputField()
  {
    $value = $this->getInputValue();

    $array = self::getReasoningModoe();

    $input = HTML::selectField($this->key, $array, $value, 'id="reasoning_mode_title"');

    return $input;
  }

  /**
   * Reasonning modelisting
   *
   * @return array Tableau des modèles d'embedding disponibles
   */
  private static function getReasoningModoe(): array
  {
    $array = [
      ['id' => 'chain_of_thought', 'text' => 'Chain of thought (COT)'],
      ['id' => 'tree_of_thought', 'text' => 'Tree of thought (TOT)'],
      ['id' => 'self_consistency', 'text' => 'Self consistency (SC)'],
    ];

    return $array;
  }
}
