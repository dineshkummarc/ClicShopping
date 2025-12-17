<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Sites\ClicShoppingAdmin\Pages\Home\Actions\RagWebSearch;

use ClicShopping\OM\Registry;

class DeleteAll extends \ClicShopping\OM\PagesActionsAbstract
{
  public mixed $app;

  public function __construct()
  {
    $this->app = Registry::get('ChatGpt');
  }

  public function execute()
  {
    $page = (isset($_GET['page']) && is_numeric($_GET['page'])) ? (int)$_GET['page'] : 1;

    if (isset($_POST['selected'])) {
      foreach ($_POST['selected'] as $id) {
        $this->app->db->delete('rag_websearch', ['id' => (int)$id]);
      }
    }

    $this->app->redirect('RagWebSearch&page=' . $page);
  }
}