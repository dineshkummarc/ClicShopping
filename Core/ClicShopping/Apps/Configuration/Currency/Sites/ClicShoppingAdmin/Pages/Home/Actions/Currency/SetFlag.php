<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */


namespace ClicShopping\Apps\Configuration\Currency\Sites\ClicShoppingAdmin\Pages\Home\Actions\Currency;

use ClicShopping\Apps\Configuration\Currency\Classes\ClicShoppingAdmin\Status;
use ClicShopping\OM\Cache;
use ClicShopping\OM\Registry;


class SetFlag extends \ClicShopping\OM\Domains\PagesActionsAbstract
{
  public mixed $app;

  public function __construct()
  {
    $this->app = Registry::get('Currency');
  }

  public function execute()
  {
    $page = (isset($_GET['page']) && is_numeric($_GET['page'])) ? (int)$_GET['page'] : 1;

    Status::getCurrencyStatus($_GET['cID'], $_GET['flag']);

    Cache::clear('currencies');

    $this->app->redirect('Currency&' . $page . '&cID=' . $_GET['cID']);
  }
}