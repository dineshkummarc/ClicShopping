<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Marketing\Recommendations\Sites\Shop\Pages\Recommendations;

use ClicShopping\Apps\Marketing\Recommendations\Recommendations as RecommendationsApp;
use ClicShopping\OM\Registry;

class Recommendations extends \ClicShopping\OM\Domains\PagesAbstract
{
  public mixed $app;

  protected function init()
  {
    if (!Registry::exists('Recommendations')) {
      Registry::set('Recommendations', new RecommendationsApp());
    }

    $CLICSHOPPING_ProductsRecommendation = Registry::get('Recommendations');

    $CLICSHOPPING_ProductsRecommendation->loadDefinitions('Sites/Shop/main');
  }
}
