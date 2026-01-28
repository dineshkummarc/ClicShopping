<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Payment\Stripe\Sites\Shop\Pages\ST;

use ClicShopping\Apps\Payment\Stripe\Module\Payment\ST as PaymentStripeST;
use ClicShopping\OM\Registry;

/**
 * Stripe webhook handler for payment processing.
 * 
 * This class handles Stripe webhook events for payment processing,
 * including charge succeeded, payment intent succeeded, and payment method
 * attached events. It validates webhook signatures and processes events
 * according to Stripe's webhook specifications.
 * 
 * @package ClicShopping\Apps\Payment\Stripe\Sites\Shop\Pages\ST
 * @author ClicShopping Team
 * @copyright 2008 - https://www.clicshopping.org
 * @license GPL 2 & MIT
 */
class ST extends \ClicShopping\OM\Domains\PagesAbstract
{
  protected ?string $file = null;
  protected bool $use_site_template = false;
  protected $pm;
  private mixed $lang;

  protected function init()
  {
    $this->lang = Registry::get('Language');

    $this->pm = new PaymentStripeST();

    if (!\defined('CLICSHOPPING_APP_STRIPE_ST_STATUS') && CLICSHOPPING_APP_STRIPE_ST_STATUS == 'False') {
      return false;
    }

    $this->lang->loadDefinitions('Shop/checkout_process');

    $endpoint_secret = CLICSHOPPING_APP_STRIPE_ST_KEY_WEBHOOK_ENDPOINT;
    $payload = @file_get_contents('php://input');

    //Could be different in test mode
// In my case I do not receive the HTTP_STRIPE_SIGNATURE in test mod
    if (CLICSHOPPING_APP_STRIPE_ST_SERVER_PROD == 'True') {
      $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    } else {
      $sig_header = '';
    }

    try {
      $event = \Stripe\Webhook::constructEvent(
        $payload,
        $sig_header,
        $endpoint_secret
      );
    } catch (\UnexpectedValueException $e) {
      // Invalid payload
      http_response_code(400);
      exit();
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
      // Invalid signature
      http_response_code(400);
      exit();
    }

    // Handle the event
    switch ($event->type) {
      case 'charge.succeeded':
        //$charge = $event->data->object;
        $event->data->object;
        break;
      case 'payment_intent.succeeded':
        //$paymentIntent = $event->data->object;
        $event->data->object;
        break;
      case 'payment_method.attached':
        //$paymentMethod = $event->data->object;
        $event->data->object;
        break;
      // ... handle other event types
      default:
        echo 'Received unknown event type ' . $event->type;
        exit;
    }

    http_response_code(200);

    Registry::get('Session')->kill();
  }
}
