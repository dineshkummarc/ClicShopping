<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Sites\Shop\Pages\Account\Actions\LogInAuth;

use ClicShopping\OM\Registry;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\Sites\Shop\EmailVerification;

class Resend extends \ClicShopping\OM\PagesActionsAbstract
{
  public function execute()
  {
    $CLICSHOPPING_MessageStack = Registry::get('MessageStack');
    $CLICSHOPPING_Db = Registry::get('Db');
    $CLICSHOPPING_Mail = Registry::get('Mail');

    if (!isset($_SESSION['email_address'])) {
      CLICSHOPPING::redirect(null, 'Account&LogIn');
    }

    $email_address = $_SESSION['email_address'];

    if (EmailVerification::sendVerificationCode($email_address)) {
      $_SESSION['email_code_sent'] = true;

      if (!empty($admin_check['user_name'])) {
        $code_length = defined('EMAIL_VERIFICATION_CODE_LENGTH') ? (int)EMAIL_VERIFICATION_CODE_LENGTH : 6;
        $code_length = max(4, min(8, $code_length)); // Limiter entre 4 et 8

        $verification_code = '';

        for ($i = 0; $i < $code_length; $i++) {
          $verification_code .= mt_rand(0, 9);
        }

        $expiry_minutes = defined('EMAIL_VERIFICATION_CODE_EXPIRY') ? (int)EMAIL_VERIFICATION_CODE_EXPIRY : 15;
        $expiry_time = date('Y-m-d H:i:s', time() + ($expiry_minutes * 60));

        $update_array = [
          'email_verification_code' => $verification_code,
          'email_verification_expiry' => $expiry_time
        ];

        $CLICSHOPPING_Db->save('administrators', $update_array, ['user_name' => $username]);

        // Envoyer l'email avec le code
        $body_subject = CLICSHOPPING::getDef('email_verification_subject', ['store_name' => STORE_NAME]);

        $text_array = [
          'store_name' => STORE_NAME,
          'verification_code' => $verification_code,
          'expiry_minutes' => $expiry_minutes
        ];

        $email_body = CLICSHOPPING::getDef('email_verification_body', $text_array) . "\n";

        $to_addr = $username;
        $from_name = STORE_OWNER;
        $from_addr = STORE_OWNER_EMAIL_ADDRESS;
        $to_name = null;
        $subject = $body_subject;

        $CLICSHOPPING_Mail->addHtml($email_body);
        $CLICSHOPPING_Mail->send($to_addr, $from_name, $from_addr, $to_name, $subject);

        $CLICSHOPPING_MessageStack->add(CLICSHOPPING::getDef('success_email_verification_code_sent'), 'success');
      } else {
        $CLICSHOPPING_MessageStack->add(CLICSHOPPING::getDef('error_email_verification_failed'), 'error');
      }
    }

    CLICSHOPPING::redirect(null, 'Account&LogInAuth');
  }
}
