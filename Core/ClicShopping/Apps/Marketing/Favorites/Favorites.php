<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Marketing\Favorites;

use ClicShopping\OM\Domains\ConfigurableAppAbstract;

class Favorites extends ConfigurableAppAbstract
{
  protected $api_version = 1;
  protected string $identifier = 'ClicShopping_Favorites_V1';

  /**
   * Initializes the required setup or configuration.
   *
   * @return void
   */
  protected function init()
  {
  }
}
