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

class account_lock_duration extends \ClicShopping\Apps\Configuration\Api\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{

  public $default = '1800';
  public int|null $sort_order = 80;
  public bool $app_configured = true;

  protected function init()
  {
    $this->title = $this->app->getDef('cfg_products_api_account_lock_duration_title');
    $this->description = $this->app->getDef('cfg_products_api_account_lock_duration_description');
  }
}
