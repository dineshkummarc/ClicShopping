<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\TemplateEmail\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Configuration\TemplateEmail\TemplateEmail;
use ClicShopping\OM\Registry;

class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    $CLICSHOPPING_TemplateEmail = new TemplateEmail();
    Registry::set('TemplateEmail', $CLICSHOPPING_TemplateEmail);

    $this->app = $CLICSHOPPING_TemplateEmail;

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
