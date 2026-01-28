<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Marketing\Recommendations;

use ClicShopping\OM\Domains\ConfigurableAppAbstract;

class Recommendations extends ConfigurableAppAbstract
{
  protected $api_version = 1;
  protected string $identifier = 'ClicShopping_Recommendations_V1';

  /**
   * Initializes the object or performs setup tasks.
   *
   * @return void
   */
  protected function init()
  {
  }
}
