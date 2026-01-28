<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Marketing\SEO\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Marketing\SEO\SEO;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_SEO = new SEO();
    Registry::set('SEO', $CLICSHOPPING_SEO);

    $this->app = Registry::get('SEO');

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
