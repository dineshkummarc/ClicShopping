<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Sites\ClicShoppingAdmin\Pages\Home\Actions\ChatGpt;

use ClicShopping\OM\Registry;

/** * Class DeleteAll
 * @package ClicShopping\Apps\Configuration\ChatGpt\Sites\ClicShoppingAdmin\Pages\Home\Actions\ChatGpt
 *
 * This class handles the deletion of multiple GPT entries from the database.
 */
class DeleteAll extends \ClicShopping\OM\PagesActionsAbstract
{
    /**
     * Execute the action to delete selected GPT entries
     *
     * This method checks for selected entries in the POST request,
     * deletes them from the database, and redirects back to the ChatGpt page.
     */
  public function execute()
  {
    $CLICSHOPPING_ChatGpt = Registry::get('ChatGpt');
    $CLICSHOPPING_Hooks = Registry::get('Hooks');

    $page = (isset($_GET['page']) && is_numeric($_GET['page'])) ? (int)$_GET['page'] : 1;

    if (isset($_POST['selected']) && !\is_null($_POST['selected'])) {
      foreach ($_POST['selected'] as $id) {
        $CLICSHOPPING_ChatGpt->db->delete('gpt', ['gpt_id' => (int)$id]);

        $CLICSHOPPING_Hooks->call('Gpt', 'DeleteAll');
      }
    }

    $CLICSHOPPING_ChatGpt->redirect('ChatGpt&page=' . $page);
  }
}