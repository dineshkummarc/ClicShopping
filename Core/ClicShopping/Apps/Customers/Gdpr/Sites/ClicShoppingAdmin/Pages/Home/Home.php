<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Customers\Gdpr\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Customers\Gdpr\Gdpr;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_Gdpr = new Gdpr();
    Registry::set('Gdpr', $CLICSHOPPING_Gdpr);

    $this->app = Registry::get('Gdpr');

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
