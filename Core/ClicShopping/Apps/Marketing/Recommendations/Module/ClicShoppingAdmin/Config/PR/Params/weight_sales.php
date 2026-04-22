<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Marketing\Recommendations\Module\ClicShoppingAdmin\Config\PR\Params;

class weight_sales extends \ClicShopping\Apps\Marketing\Recommendations\Module\ClicShoppingAdmin\Config\ConfigParamAbstract
{
  public $default = 0.4;
  public int|null $sort_order = 72;
  public bool $app_configured = true;

  protected function init()
  {
    $this->title = $this->app->getDef('cfg_products_recommendations_weight_sales_title');
    $this->description = $this->app->getDef('cfg_products_recommendations_weight_sales_description');
  }
}

