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

class delivery_latest extends \ClicShopping\Apps\AI\Ecommerce\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{
  public $default ='+3 days';
  public int|null $sort_order = 30;

  protected function init()
  {
    $this->title = $this->app->getDef('cfg_ecommerce_acp_delivery_latest_title');
    $this->description = $this->app->getDef('cfg_ecommerce_acp_delivery_latest_description');
  }
}
