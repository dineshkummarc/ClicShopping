<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\Settings;

use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;

class Settings extends \ClicShopping\OM\ConfigurableAppAbstract
{
  protected $api_version = 1;
  protected string $identifier = 'ClicShopping_Settings_V1';

  /**
   * Initializes the necessary settings or configurations required for the object or process.
   *
   * @return void
   */
  protected function init()
  {
  }
}
