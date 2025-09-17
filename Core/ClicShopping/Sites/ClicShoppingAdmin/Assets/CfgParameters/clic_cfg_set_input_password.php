<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

use ClicShopping\OM\Hash;
use ClicShopping\OM\HTML;

/**
 * Email password input
 *
 * @param string $password
 * @return string  $password, the password
 */

function clic_cfg_set_input_password($password)
{
  $encrypted_password = Hash::displayDecryptedDataText($password);
  return HTML::passwordField('configuration_value', $encrypted_password);
}
