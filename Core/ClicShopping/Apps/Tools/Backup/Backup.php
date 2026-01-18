<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Tools\Backup;

use ClicShopping\OM\Domains\ConfigurableAppAbstract;

class Backup extends ConfigurableAppAbstract
{

  protected $api_version = 1;
  protected string $identifier = 'ClicShopping_Backup_V1';

  /**
   * Initializes the necessary components or settings for the class.
   *
   * @return void
   */
  protected function init()
  {
  }
}
