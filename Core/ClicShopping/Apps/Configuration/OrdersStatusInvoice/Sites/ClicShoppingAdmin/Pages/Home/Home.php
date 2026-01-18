<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\OrdersStatusInvoice\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Configuration\OrdersStatusInvoice\OrdersStatusInvoice;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_OrdersStatusInvoice = new OrdersStatusInvoice();
    Registry::set('OrdersStatusInvoice', $CLICSHOPPING_OrdersStatusInvoice);

    $this->app = $CLICSHOPPING_OrdersStatusInvoice;

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
