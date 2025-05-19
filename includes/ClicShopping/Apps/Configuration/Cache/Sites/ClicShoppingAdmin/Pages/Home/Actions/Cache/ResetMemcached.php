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

class ResetMemcached extends \ClicShopping\OM\PagesActionsAbstract
{
  public mixed $app;

  public function __construct()
  {
    $this->app = Registry::get('Cache');
  }


  public function execute()
  {
    $CLICSHOPPING_MessageStack = Registry::get('MessageStack');

    if (class_exists('Memcached')) {
      $memcache = new \Memcached();
      $memcache->addServer('127.0.0.1', 11211);
      $memcache->flush();
      $CLICSHOPPING_MessageStack->add($this->app->getDef('success_memcached_reset'), 'success');
    } else {
      $CLICSHOPPING_MessageStack->add($this->app->getDef('error_memcached_reset'), 'error');
    }

    $this->app ->redirect('Memcached');
  }
}

