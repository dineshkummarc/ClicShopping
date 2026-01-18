<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\Cache\Sites\ClicShoppingAdmin\Pages\Home\Actions\Cache;

use ClicShopping\Apps\Configuration\Cache\Classes\ClicShoppingAdmin\CacheAdmin;
use ClicShopping\OM\Registry;

class ResetOpCache extends \ClicShopping\OM\Domains\PagesActionsAbstract
{
  public mixed $app;

  public function __construct()
  {
    $this->app = Registry::get('Cache');
  }

  public function execute()
  {
    $CLICSHOPPING_MessageStack = Registry::get('MessageStack');

    if(CacheAdmin::checkOpCache() === true) {
      $result = CacheAdmin::resetOpCache();

      if (function_exists('opcache_reset')) {
        if ($result === true) {
          $CLICSHOPPING_MessageStack->add($this->app->getDef('success_opcache_reset'), 'success', 'main');
        } else {
          $CLICSHOPPING_MessageStack->add($this->app->getDef('error_opcache_reset'), 'error', 'main');
        }
      } else {
        $CLICSHOPPING_MessageStack->add($this->app->getDef('warning_opcache_function'), 'warning', 'main');
      }
    }

    $this->app->redirect('OpCache');
  }
}