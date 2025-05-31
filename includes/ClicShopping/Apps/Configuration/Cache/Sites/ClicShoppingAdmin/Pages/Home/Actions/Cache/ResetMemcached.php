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

class ResetMemcached extends \ClicShopping\OM\PagesActionsAbstract
{
  public mixed $app;

  public function __construct()
  {
    $this->app = Registry::get('Cache');
  }
  /**
   * Execute the action to reset Memcached.
   *
   * This method checks if the Memcached extension is available, flushes the Memcached server,
   * and adds a success or error message to the message stack.
   *
   * @return void
   */
  public function execute()
  {
    $CLICSHOPPING_MessageStack = Registry::get('MessageStack');

    $result = CacheAdmin::resetMemcached();

    if ($result === true) {
      $CLICSHOPPING_MessageStack->add($this->app->getDef('success_memcached_reset'), 'success');
    } else {
      $CLICSHOPPING_MessageStack->add($this->app->getDef('error_memcached_reset'), 'error');
    }

    $this->app ->redirect('Memcached');
  }
}

