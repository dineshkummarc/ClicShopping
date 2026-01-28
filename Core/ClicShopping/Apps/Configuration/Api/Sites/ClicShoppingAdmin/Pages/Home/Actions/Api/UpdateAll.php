<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\Api\Sites\ClicShoppingAdmin\Pages\Home\Actions\Api;

use ClicShopping\Apps\Configuration\Api\Classes\ClicShoppingAdmin\ApiAdmin;
use ClicShopping\OM\Cache;
use ClicShopping\OM\Registry;

class UpdateAll extends \ClicShopping\OM\Domains\PagesActionsAbstract
{
  public mixed $app;

  public function __construct()
  {
    $this->app = Registry::get('Api');
  }

  public function execute()
  {
    $page = (isset($_GET['page']) && is_numeric($_GET['page'])) ? (int)$_GET['page'] : 1;

    if (!Registry::exists('ApiAdmin')) {
      Registry::set('ApiAdmin', new ApiAdmin());
    }

    $ApiAdmin = Registry::get('ApiAdmin');

    $ApiAdmin->updateAllApi();

    Cache::clear('api');

    $this->app->redirect('Api&page=' . $page);
  }
}