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
 * Class Edit
 *
 * This action class handles the editing of products in the admin interface.
 * It sets up the page for editing and loads necessary definitions.
 */
class Edit extends \ClicShopping\OM\Domains\PagesActionsAbstract
{
  /**
   * Execute the action to edit a product.
   *
   * This method sets the page file to 'edit.php', defines the action as 'Edit',
   * and loads the necessary language definitions for the Products app.
   */
  public function execute()
  {
    $CLICSHOPPING_Products = Registry::get('Products');

    $this->page->setFile('edit.php');
    $this->page->data['action'] = 'Edit';

    $CLICSHOPPING_Products->loadDefinitions('Sites/ClicShoppingAdmin/Products');
  }
}