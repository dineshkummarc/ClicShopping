<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Catalog\Categories;

use ClicShopping\OM\Domains\ConfigurableAppAbstract;

/**
 * Handles operations related to the Categories application module.
 * Provides functionalities like retrieving configuration modules, fetching
 * configuration module information, and accessing metadata such as API versions
 * and identifiers. This class extends the AppAbstract base class to ensure
 * consistency and shared functionalities across application modules.
 */
class Categories extends ConfigurableAppAbstract
{
  protected $api_version = 1;
  protected string $identifier = 'ClicShopping_Categories_V1';

  /**
   * Initializes the necessary configurations or setups required by the implementing class.
   *
   * @return void
   */
  protected function init()
  {
  }
}
