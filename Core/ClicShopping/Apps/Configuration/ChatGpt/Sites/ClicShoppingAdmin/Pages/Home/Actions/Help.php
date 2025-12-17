<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Sites\ClicShoppingAdmin\Pages\Home\Actions;

use ClicShopping\OM\Registry;

class Help extends \ClicShopping\OM\PagesActionsAbstract
{
  public function execute()
  {
    $CLICSHOPPING_ChatGpt = Registry::get('ChatGpt');

    $this->page->setFile('help.php');
    $this->page->data['action'] = 'Help';

    $CLICSHOPPING_ChatGpt->loadDefinitions('Sites/ClicShoppingAdmin/help');
  }
}