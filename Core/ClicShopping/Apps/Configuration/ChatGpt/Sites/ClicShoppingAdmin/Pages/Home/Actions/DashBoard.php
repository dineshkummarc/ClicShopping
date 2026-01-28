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

class DashBoard extends \ClicShopping\OM\Domains\PagesActionsAbstract
{
  public function execute()
  {
    $CLICSHOPPING_ChatGpt = Registry::get('ChatGpt');

    $this->page->setFile('dashboard.php');
    $this->page->data['action'] = 'RAG';

    $CLICSHOPPING_ChatGpt->loadDefinitions('Sites/ClicShoppingAdmin/dashboard');
  }
}