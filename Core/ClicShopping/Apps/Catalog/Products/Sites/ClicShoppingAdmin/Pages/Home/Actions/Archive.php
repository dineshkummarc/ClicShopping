<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Catalog\Products\Sites\ClicShoppingAdmin\Pages\Home\Actions;

use ClicShopping\OM\Registry;
/**
 * Class Archive
 *
 * This action class handles the display of the archive confirmation page in the admin interface.
 * It sets up the necessary page file and loads language definitions for the Products app.
 */
class Archive extends \ClicShopping\OM\PagesActionsAbstract
{
  /**
   * Execute the action to display the archive confirmation page.
   *
   * This method sets the page file to 'archive.php', defines the action as 'ArchiveConfirm',
   * and loads the relevant language definitions for the Products app.
   */
  public function execute()
  {
    $CLICSHOPPING_Products = Registry::get('Products');

    $this->page->setFile('archive.php');
    $this->page->data['action'] = 'ArchiveConfirm';

    $CLICSHOPPING_Products->loadDefinitions('Sites/ClicShoppingAdmin/Products');
  }
}