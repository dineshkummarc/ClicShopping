<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Marketing\Recommendations\Module\Hooks\Shop\Reviews;

use ClicShopping\Apps\Marketing\Recommendations\Classes\Shop\RecommendationsShop;
use ClicShopping\Apps\Marketing\Recommendations\Classes\Shop\ProductsAutomation;
use ClicShopping\Apps\Marketing\Recommendations\Classes\Shop\RatingValidator;

use ClicShopping\OM\Registry;
use function defined;

class saveEntry implements \ClicShopping\OM\Modules\HooksInterface
{
  private mixed $productsCommon;
  private mixed $recommendationsShop;

  /**
   * Constructor method.
   *
   * Initializes the productsCommon property and registers the RecommendationsShop instance
   * in the registry before assigning it to the recommendationsShop property.
   *
   * @return void
   */
  public function __construct()
  {
    $this->productsCommon = Registry::get('ProductsCommon');

    Registry::set('RecommendationsShop', new RecommendationsShop());
    $this->recommendationsShop = Registry::get('RecommendationsShop');
  }

  /**
   * Executes the main functionality for saving product recommendations and triggering additional automation processes
   * based on the application's configuration settings.
   *
   * @return bool|void Returns false if the recommendations functionality is not enabled, otherwise no return value.
   */
  public function execute()
  {
    if (!defined('CLICSHOPPING_APP_RECOMMENDATIONS_PR_STATUS') || CLICSHOPPING_APP_RECOMMENDATIONS_PR_STATUS == 'False') {
      return false;
    }

    // Validate and sanitize the rating input securely
    $validatedRating = RatingValidator::validatePostRating($_POST);
    
    if ($validatedRating === null) {
      // Log the security issue and use default rating
      error_log('[Recommendations Security] Invalid rating provided, using default value');
      $validatedRating = RatingValidator::getDefaultRating();
    }

    $this->recommendationsShop->saveRecommendations($this->productsCommon->getID(), $validatedRating);

//productsAutomation
    if (defined('CLICSHOPPING_APP_RECOMMENDATIONS_PR_FAVORITES_STATUS') || CLICSHOPPING_APP_RECOMMENDATIONS_PR_FAVORITES_STATUS == 'True') {
      ProductsAutomation::favorites();
    }

    if (defined('CLICSHOPPING_APP_RECOMMENDATIONS_PR_FEATURED_STATUS') || CLICSHOPPING_APP_RECOMMENDATIONS_PR_FEATURED_STATUS == 'True') {
      ProductsAutomation::featured();
    }
  }
}