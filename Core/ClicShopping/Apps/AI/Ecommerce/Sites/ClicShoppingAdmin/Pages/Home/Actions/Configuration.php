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

class Configuration extends \ClicShopping\OM\Domains\PagesActionsAbstract
{
  public function execute()
  {
    $CLICSHOPPING_Ecommerce = Registry::get('Ecommerce');

    $this->page->setFile('configuration.php');
    $this->page->data['action'] = 'Configuration';

    $CLICSHOPPING_Ecommerce->loadDefinitions('Sites/ClicShoppingAdmin/configuration');
  }
}
