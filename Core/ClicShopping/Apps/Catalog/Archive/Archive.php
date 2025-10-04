<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Catalog\Archive;

use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;

/**
 * Represents the Archive class that extends the AppAbstract class and provides
 * methods for managing configuration modules and retrieving metadata such as
 * API version and instance identifier.
 */
class Archive extends \ClicShopping\OM\ConfigurableAppAbstract
{
  protected $api_version = 1;
  protected string $identifier = 'ClicShopping_Archive_V1';

  protected function init()
  {
  }
}
