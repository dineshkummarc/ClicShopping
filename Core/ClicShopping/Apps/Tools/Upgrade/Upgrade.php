<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Tools\Upgrade;

use ClicShopping\OM\Domains\ConfigurableAppAbstract;

class Upgrade extends ConfigurableAppAbstract
{

  protected $api_version = 1;
  protected string $identifier = 'ClicShopping_Upgrade_V1';

  /**
   * Initializes the required properties or configurations for the current class.
   *
   * @return void
   */
  protected function init()
  {
  }
}
