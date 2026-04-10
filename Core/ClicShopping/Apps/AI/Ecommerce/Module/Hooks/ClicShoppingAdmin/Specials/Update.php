<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\AI\Ecommerce\Module\Hooks\ClicShoppingAdmin\Specials;

use ClicShopping\Apps\Configuration\ChatGpt\ChatGpt as ChatGptApp;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\OM\HTML;
use ClicShopping\OM\Registry;

/**
 * Class Insert
 *
 * This class handles the insertion of product data into the database.
 * It generates SEO metadata, summaries, and translations based on product information,
 * and also creates categories-related images if specified.
 */

class Update implements \ClicShopping\OM\Modules\HooksInterface
{
  public mixed $app;
  public mixed $lang;

  /**
   * Class constructor.
   * Initializes the ChatGptApp instance in the Registry if it doesn't already exist,
   * and loads the necessary definitions for the application.
   *
   * @return void
   */
  public function __construct()
  {
    if (!Registry::exists('ChatGpt')) {
      Registry::set('ChatGpt', new ChatGptApp());
    }

    $this->app = Registry::get('ChatGpt');
    $this->lang = Registry::get('Language');
  }

  /**
   * Executes the necessary processes based on the provided GET and POST parameters related to category handling.
   *
   * Checks if GPT functionality is enabled and processes category-related inputs to update database records
   * such as descriptions, SEO data (title, description, keywords), and optionally images.
   *
   * @return bool Returns false if GPT functionality is disabled or not applicable; otherwise, performs the operations without returning a value.
   */
  public function execute()
  {
    $requiredConstants = [
      'CLICSHOPPING_APP_ECOMMERCE_CAI_STATUS',
    ];

    foreach ($requiredConstants as $const) {
      if (!\defined($const) || \constant($const) !== 'True') {
        return false;
      }
    }

    if (!Gpt::checkGptStatus()) {
      return false;
    }

    if (isset($_GET['Update'], $_GET['Specials'])) {
      $products_id = HTML::sanitize($_POST['products_id']);
      
      // Si on insère ou met à jour une promo
      if (isset($products_id)) {
        $this->clearCockpitCache((int)$products_id);
      }
    }
  }

  /**
   * Supprime le score mis en cache pour forcer le recalcul
   */
  private function clearCockpitCache(int $productId): void
  {
    // Supprime l'entrée dans la table des scores pour ce produit
    $this->app->delete('products_cockpit_ai_embedding ', ['products_id' => $productId]);

    if (\defined('CLICSHOPPING_APP_ECOMMERCE_CAI_DEBUG') && CLICSHOPPING_APP_ECOMMERCE_CAI_DEBUG === 'True') {
      error_log("[CockpitAI Hook] Specials change detected. Cache cleared for product " . $productId);
    }
  }
}