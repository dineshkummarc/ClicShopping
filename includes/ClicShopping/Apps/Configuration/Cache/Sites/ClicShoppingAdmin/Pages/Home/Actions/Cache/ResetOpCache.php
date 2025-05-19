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

use ClicShopping\OM\Registry;

class ResetOPcache extends \ClicShopping\OM\PagesActionsAbstract
{
  public mixed $app;

  public function __construct()
  {
    $this->app = Registry::get('Cache');
  }

  public function execute()
  {
    $CLICSHOPPING_MessageStack = Registry::get('MessageStack');

    if (function_exists('opcache_reset')) {
      if (opcache_reset()) {
        $CLICSHOPPING_MessageStack->add($this->app->getDef('success_opcache_reset'), 'success');
      } else {
        $CLICSHOPPING_MessageStack->add($this->app->getDef('error_opcache_reset'), 'error');
      }
    } else {
      $CLICSHOPPING_MessageStack->add($this->app->getDef('warning_opcache_function'), 'warning');
    }

    $this->app->redirect('OpCache');
  }
}