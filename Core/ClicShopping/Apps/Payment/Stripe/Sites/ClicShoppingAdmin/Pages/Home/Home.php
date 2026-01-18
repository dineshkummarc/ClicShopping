<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Payment\Stripe\Sites\ClicShoppingAdmin\Pages\Home;

use ClicShopping\Apps\Payment\Stripe\Stripe;
use ClicShopping\OM\Registry;

/**
 * Home page class for Stripe payment module administration interface.
 * 
 * This class handles the main administrative page for the Stripe payment integration,
 * providing initialization and setup for the Stripe application instance within
 * the ClicShoppingAdmin environment.
 * 
 * @package ClicShopping\Apps\Payment\Stripe\Sites\ClicShoppingAdmin\Pages\Home
 * @author ClicShopping Team
 * @copyright 2008 - https://www.clicshopping.org
 * @license GPL 2 & MIT
 */
class Home extends \ClicShopping\OM\Domains\PagesAbstract
{
  /**
   * @var mixed The Stripe application instance
   */
  public mixed $app;

  /**
   * Initialize the Stripe administration page.
   * 
   * Creates and registers the Stripe application instance, then loads
   * the necessary language definitions for the admin interface.
   * 
   * @return void
   */
  protected function init()
  {
    $CLICSHOPPING_Stripe = new Stripe();
    Registry::set('Stripe', $CLICSHOPPING_Stripe);

    $this->app = $CLICSHOPPING_Stripe;

    $this->app->loadDefinitions('Sites/ClicShoppingAdmin/main');
  }
}
