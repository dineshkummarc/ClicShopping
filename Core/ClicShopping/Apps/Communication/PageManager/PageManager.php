<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Communication\PageManager;

use AllowDynamicProperties;
use ClicShopping\OM\Domains\ConfigurableAppAbstract;

#[AllowDynamicProperties]
class PageManager extends ConfigurableAppAbstract
{
  /**
   * API version for this domain app
   * 
   * @var int
   */
  protected $api_version = 1;

  /**
   * Unique identifier for this domain app
   * 
   * Format: ClicShopping_{Vendor}_{AppName}_V{Version}
   * 
   * @var string
   */
  protected string $identifier = 'ClicShopping_PageManager_V1';

  /**
   * Initializes the necessary components or configurations for the class.
   *
   * @return void
   */
  protected function init(): void
  {
  }
}
