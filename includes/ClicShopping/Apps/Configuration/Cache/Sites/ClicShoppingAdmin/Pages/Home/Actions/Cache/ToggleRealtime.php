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

class ToggleRealtime extends \ClicShopping\OM\PagesActionsAbstract
{
  public mixed $app;

  public function __construct()
  {
    $this->app = Registry::get('Cache');
  }

  public function execute()
  {
    $CLICSHOPPING_MessageStack = Registry::get('MessageStack');

    if (!isset($_SESSION['opcache_realtime'])) {
      $_SESSION['opcache_realtime'] = false;
    }

    $_SESSION['opcache_realtime'] = !$_SESSION['opcache_realtime'];

    $status = $_SESSION['opcache_realtime'] ? 'enabled' : 'disabled';
    $CLICSHOPPING_MessageStack->add('Realtime updates ' . $status, 'success');

    $this->app->redirect('OpCache');
  }
}