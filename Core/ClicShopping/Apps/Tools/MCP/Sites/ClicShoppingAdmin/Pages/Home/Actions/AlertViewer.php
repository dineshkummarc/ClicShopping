<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */
namespace ClicShopping\Apps\Tools\MCP\Sites\ClicShoppingAdmin\Pages\Home\Actions;

use ClicShopping\OM\Registry;

class AlertViewer extends \ClicShopping\OM\PagesActionsAbstract
{
  public function execute()
  {
    $CLICSHOPPING_MCP = Registry::get('MCP');

    $this->page->setFile('alert_viewer.php');
    $this->page->data['action'] = 'MCP';

    $CLICSHOPPING_MCP->loadDefinitions('Sites/ClicShoppingAdmin/alert_viewer');
  }
}