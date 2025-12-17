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

use AllowDynamicProperties;
use ClicShopping\OM\HTML;
use ClicShopping\OM\Registry;

#[AllowDynamicProperties]
class Insert extends \ClicShopping\OM\PagesActionsAbstract
{
  public mixed $app;
  public mixed $messageStack;

  public function __construct()
  {
    $this->app = Registry::get('ChatGpt');
    $this->messageStack = Registry::get('MessageStack');
  }

  public function execute()
  {
    $page = (isset($_GET['page']) && is_numeric($_GET['page'])) ? (int)$_GET['page'] : 1;

    if (isset($_GET['RagWebSearch'], $_GET['Insert'])) {

      $site_domain = HTML::sanitize($_POST['site_domain']);
      $authority_score = HTML::sanitize($_POST['authority_score']);
      $description = HTML::sanitize($_POST['description']);
      $search_pattern = $_POST['search_pattern'];

      if (isset($_POST['status'])) {
        $status = HTML::sanitize($_POST['status']);
      } else {
        $status = 0;
      }

      if ($authority_score < 0 || $authority_score > 0.15) {
        $this->messageStack->add('ChatGpt', 'Error: authority score must be between 0 and 0.15', 'error');
        $this->app->redirect('RagWebSearchEdit');
      }

      $insert_array = [
        'site_domain' => $site_domain,
        'authority_score' => (float)$authority_score,
        'status' => (int)$status,
        'description' => $description,
        'search_pattern' => $search_pattern,
      ];

      $this->app->db->save('rag_websearch', $insert_array);
    }

    $this->app->redirect('RagWebSearch&page=' . $page);
  }
}