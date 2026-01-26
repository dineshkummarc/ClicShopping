<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\AI\Ecommerce\Sites\ClicShoppingAdmin\Pages\Home\Actions;

use ClicShopping\OM\Registry;

class Help extends \ClicShopping\OM\Domains\PagesActionsAbstract
{
  public function execute()
  {
    $CLICSHOPPING_Ecommerce = Registry::get('Ecommerce');

    $this->page->setFile('help.php');
    $this->page->data['action'] = 'Help';

    $CLICSHOPPING_Ecommerce->loadDefinitions('Sites/ClicShoppingAdmin/help');
  }
}
