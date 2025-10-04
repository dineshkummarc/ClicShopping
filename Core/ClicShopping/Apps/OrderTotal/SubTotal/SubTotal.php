<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\OrderTotal\SubTotal;

use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;

class SubTotal extends \ClicShopping\OM\ConfigurableAppAbstract
{
  protected $api_version = 1;
  protected string $identifier = 'ClicShopping_SubTotal_V1';

  /**
   * Initializes necessary components or settings for the class.
   *
   * @return void
   */
  protected function init()
  {
  }
}
