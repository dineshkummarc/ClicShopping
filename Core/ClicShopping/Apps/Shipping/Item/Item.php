<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Shipping\Item;

use ClicShopping\OM\Domains\ConfigurableAppAbstract;

class Item extends ConfigurableAppAbstract
{

  protected $api_version = 1;
  protected string $identifier = 'ClicShopping_Item_V1';

  /**
   * Initializes the necessary setup or configurations for the current instance.
   *
   * @return void
   */
  protected function init()
  {
  }
}
