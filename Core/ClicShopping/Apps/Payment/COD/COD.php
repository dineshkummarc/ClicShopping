<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Payment\COD;

use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;

/**
 * Class COD
 *
 * This class extends the abstract class \ClicShopping\OM\AppAbstract and provides functionalities
 * related to the configuration and management of Cash on Delivery (COD) payment modules within
 * the ClicShopping application. It includes methods for retrieving configuration modules, module
 * information, API version, and identifier.
 */
class COD extends \ClicShopping\OM\ConfigurableAppAbstract
{
  protected $api_version = 1;
  protected string $identifier = 'ClicShopping_COD_V1';

  protected function init()
  {
  }
}
