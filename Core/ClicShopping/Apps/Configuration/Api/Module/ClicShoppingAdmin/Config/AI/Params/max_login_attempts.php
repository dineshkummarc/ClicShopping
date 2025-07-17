<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\Api\Module\ClicShoppingAdmin\Config\AI\Params;

class max_login_attempts extends \ClicShopping\Apps\Configuration\Api\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{

  public $default = '5';
  public int|null $sort_order = 30;
  public bool $app_configured = true;

  protected function init()
  {
    $this->title = $this->app->getDef('cfg_products_api_max_login_attempts_title');
    $this->description = $this->app->getDef('cfg_products_api_max_login_attempts_description');
  }
}
