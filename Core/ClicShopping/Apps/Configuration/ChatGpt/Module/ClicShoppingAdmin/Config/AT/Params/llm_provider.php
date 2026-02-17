<?php
/**
 * LLM Provider Parameter
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Module\ClicShoppingAdmin\Config\AT\Params;

use ClicShopping\OM\HTML;

/**
 * LLM Provider Parameter
 * 
 * Controls which LLM provider to use for agent operations.
 * Options: OpenAI (GPT), Ollama (local), Anthropic (Claude).
 * 
 * @package ClicShopping\Apps\Configuration\ChatGpt\Module\ClicShoppingAdmin\Config\AT\Params
 * @since 4.2.0
 */
class llm_provider extends \ClicShopping\Apps\Configuration\ChatGpt\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{
  public $default = 'openai';
  public int|null $sort_order = 40;

  /**
   * Initialize parameter configuration
   */
  protected function init()
  {
    $this->title = $this->app->getDef('cfg_chatgpt_llm_provider_title');
    $this->description = $this->app->getDef('cfg_chatgpt_llm_provider_description');
  }

  /**
   * Get input field HTML
   * 
   * @return string HTML for dropdown select
   */
  public function getInputField()
  {
    $value = $this->getInputValue();
    
    $providers = [
      ['id' => 'openai', 'text' => 'OpenAI'],
      ['id' => 'ollama', 'text' => 'Ollama (Local Models)'],
      ['id' => 'LmStudio', 'text' => 'LmStudio (Local Models)'],
      ['id' => 'anthropic', 'text' => 'Anthropic (Claude)']
    ];
    
    return HTML::selectField($this->key, $providers, $value, 'id="' . $this->key . '"');
  }
}
