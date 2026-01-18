<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Payment\Stripe;

use ClicShopping\OM\Domains\ConfigurableAppAbstract;

/**
 * Class Stripe is a part of the ClicShopping payment module.
 * It handles the configuration modules and provides access to relevant information regarding payment configurations.
 */
class Stripe extends ConfigurableAppAbstract
{

  protected $api_version = 1;
  protected string $identifier = 'ClicShopping_Stripe_V1';

  protected function init()
  {
  }
}
