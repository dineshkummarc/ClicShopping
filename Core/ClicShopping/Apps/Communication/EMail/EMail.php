<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Communication\EMail;

use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;

class EMail extends \ClicShopping\OM\ConfigurableAppAbstract
{

  protected $api_version = 1;
  protected string $identifier = 'ClicShopping_Email_V1';

  protected function init()
  {
  }
}
