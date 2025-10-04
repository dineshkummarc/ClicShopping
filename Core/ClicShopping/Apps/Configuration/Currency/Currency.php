<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\Currency;

use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;

class Currency extends \ClicShopping\OM\ConfigurableAppAbstract
{
  protected $api_version = 1;
  protected string $identifier = 'ClicShopping_Currency_V1';

  /**
   * Initializes the necessary components or configuration for the class.
   *
   * @return void
   */
  protected function init()
  {
  }
}
