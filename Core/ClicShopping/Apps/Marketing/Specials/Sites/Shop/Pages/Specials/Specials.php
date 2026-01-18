<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Marketing\Specials\Sites\Shop\Pages\Specials;

use ClicShopping\Apps\Marketing\Specials\Specials as SpecialsApp;
use ClicShopping\OM\Registry;

class Specials extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    if (!Registry::exists('Specials')) {
      Registry::set('Specials', new SpecialsApp());
    }

    $CLICSHOPPING_Specials = Registry::get('Specials');

    $CLICSHOPPING_Specials->loadDefinitions('Sites/Shop/main');
  }
}
