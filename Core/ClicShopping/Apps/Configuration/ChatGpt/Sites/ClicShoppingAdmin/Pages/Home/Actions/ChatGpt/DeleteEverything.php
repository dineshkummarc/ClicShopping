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

/**
 * Class DeleteEverything
 * @package ClicShopping\Apps\Configuration\ChatGpt\Sites\ClicShoppingAdmin\Pages\Home\Actions\ChatGpt
 *
 * This class handles the deletion of all records in the 'gpt' table.
 * It is triggered when the 'DeleteEverything' action is requested.
 */
class DeleteEverything extends \ClicShopping\OM\PagesActionsAbstract
{
    /**
     * Execute the action to delete all records in the 'gpt' table.
     *
     * This method checks if the 'ChatGpt' and 'DeleteEverything' parameters are set,
     * and if so, it deletes all records from the 'gpt' table.
     * After deletion, it redirects back to the ChatGpt page.
     */
  public function execute()
  {
    $CLICSHOPPING_ChatGpt = Registry::get('ChatGpt');

    if (isset($_GET['ChatGpt']) && isset($_GET['DeleteEverything'])) {
      $CLICSHOPPING_ChatGpt->db->delete('gpt');
    }

    $CLICSHOPPING_ChatGpt->redirect('ChatGpt');
  }
}