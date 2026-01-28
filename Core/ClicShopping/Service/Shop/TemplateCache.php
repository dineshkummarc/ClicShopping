<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Service\Shop;

use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;
use ClicShopping\Sites\Shop\TemplateCache as NewTemplateCache;

/**
 * TemplateCache class
 *
 * This class is responsible for managing the template cache functionality.
 * It initializes the cache system and provides methods to start and stop the service.
 */
class TemplateCache implements \ClicShopping\OM\Interfaces\ServiceInterface
{
  /**
   * Initializes the SpecialsClass functionality if the required file exists.
   *
   * @return bool Returns true if the SpecialsClass is successfully initialized, otherwise false.
   */
  public static function start(): bool
  {
    if (is_file(CLICSHOPPING::BASE_DIR . 'Apps/Configuration/Cache/Classes/Shop/TemplateCache.php')) {
      Registry::set('TemplateCache', new NewTemplateCache());

      return true;
    } else {
      return false;
    }

    return false;
  }

  /**
   * Stops the execution or performs the necessary termination operations.
   *
   * @return bool Returns true to indicate that the stop operation was successful.
   */
  public static function stop(): bool
  {
    return true;
  }
}
