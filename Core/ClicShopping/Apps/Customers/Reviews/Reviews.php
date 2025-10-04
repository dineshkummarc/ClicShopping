<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Customers\Reviews;

use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;

class Reviews extends \ClicShopping\OM\ConfigurableAppAbstract
{
  protected $api_version = 1;
  protected string $identifier = 'ClicShopping_Reviews_V1';

  /**
   * Initializes the necessary components or state required for the method.
   *
   * @return void
   */
  protected function init()
  {
  }
}
