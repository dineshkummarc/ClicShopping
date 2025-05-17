<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Module\Hooks\ClicShoppingAdmin\Cronjob;

use ClicShopping\OM\HTML;
use ClicShopping\OM\Registry;

use ClicShopping\Apps\Configuration\ChatGpt\ChatGpt as ChatGptApp;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Cron;

class Process implements \ClicShopping\OM\Modules\HooksInterface
{
  private mixed $app;
  private mixed $cron;
  
  public function __construct()
  {
    if (!Registry::exists('ChatGpt')) {
      Registry::set('ChatGpt', new ChatGptApp());
    }

    $this->app = Registry::get('ChatGpt');
  }

  /**
   * Clears the currency cache.
   *
   * This method clears the cache for currencies by calling the appropriate method
   * from the ClicShopping application instance.
   *
   * @return void
   */
  public function updateAllEmbedding(): void
  {
    if (!Registry::exists('Cron')) {
      Registry::set('Cron', new Cron());
    }

    $this->cron = Registry::get('Cron');

    $this->cron->updateAllEmbedding();
  }

  /**
   * Handles the execution of a cron job related to currency updates.
   *
   * This method checks for a 'cronId' parameter in the GET request, validates it,
   * and performs currency updates if the 'cronId' matches the predefined cron code.
   * If no 'cronId' is provided in the request, the method executes the update for all currencies
   * using the predefined cron ID.
   *
   * @return void
   */
  private function cronJob(): void
  {
    $cron_id_embedding = Cron::getCronCode('embeddings');

    if (isset($_GET['cronId'])) {
      $cron_id = HTML::sanitize($_GET['cronId']);

      Cron::updateCron($cron_id);

      if (isset($cron_id) && $cron_id_embedding == $cron_id) {
        $this->cron->updateAllEmbedding();
      }
    } else {
      Cron::updateCron($cron_id_embedding);

      if (isset($cron_id_embedding)) {
        $this->cron->updateAllEmbedding();
      }
    }
  }

  /**
   * Executes the main process by calling the cron job and clearing the currency cache.
   *
   * @return void
   */
  public function execute()
  {
    $this->cronJob();
  }
}