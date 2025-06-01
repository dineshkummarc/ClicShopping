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

use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\NewVector;
use ClicShopping\OM\HTML;

class embedding_model extends \ClicShopping\Apps\Configuration\ChatGpt\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{
  public $default = 'GPT‑4.1-nano';
  public int|null $sort_order = 35;

  protected function init()
  {
    $this->title = $this->app->getDef('cfg_chatgpt_embedding_model_title');
    $this->description = $this->app->getDef('cfg_chatgpt_embedding_model_description');
  }

  public function getInputField()
  {
    $array = NewVector::getEmbeddingModel();

    $input = HTML::selectField($this->key, $array, $this->getInputValue(), 'id="embedding_model_title"');

    return $input;
  }
}
