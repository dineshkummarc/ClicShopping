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

use ClicShopping\AI\Domain\Embedding\NewVector;
use ClicShopping\OM\HTML;

class embedding_model extends \ClicShopping\Apps\Configuration\ChatGpt\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{
  public $default = 'GPT‑4.1-mini';
  public int|null $sort_order = 40;

  protected function init()
  {
    $this->title = $this->app->getDef('cfg_chatgpt_embedding_model_title');
    $this->description = $this->app->getDef('cfg_chatgpt_embedding_model_description');
  }

  public function getInputField()
  {
    $value = $this->getInputValue();

    $array = NewVector::getEmbeddingModel();

    $input = HTML::selectField($this->key, $array, $value, 'id="embedding_model_title"');

    return $input;
  }
}
