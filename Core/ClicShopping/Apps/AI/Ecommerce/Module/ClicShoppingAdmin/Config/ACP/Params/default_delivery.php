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

class default_delivery extends \ClicShopping\Apps\AI\Ecommerce\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{
  public $default ='3-5 jours ouvrés';
  public int|null $sort_order = 40;

  protected function init()
  {
    $this->title = $this->app->getDef('cfg_ecommerce_acp_default_delivery_title');
    $this->description = $this->app->getDef('cfg_ecommerce_acp_default_delivery_description');
  }
}
