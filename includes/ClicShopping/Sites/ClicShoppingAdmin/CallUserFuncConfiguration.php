<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Sites\ClicShoppingAdmin;

use ClicShopping\OM\CLICSHOPPING;
use function call_user_func;
/**
 * This class provides a static method to dynamically execute a function or a class method.
 * It supports execution of global functions as well as class methods specified in the format "Class::Method".
 * If the specified function or file does not exist, it attempts to include the required file from predefined directories.
 */
class CallUserFuncConfiguration
{
  /**
   * Executes a function or a class method based on the provided string.
   *
   * @param string $function The name of the function or class method to execute.
   * @param array|string|null $default Optional default parameters to pass to the function.
   * @param string|null $key Optional key parameter to pass to the function.
   * @return mixed The result of the executed function or method.
   */
  public static function execute(string $function, array|string|null $default = null, string|null $key = null)
  {
    if (str_contains($function, '::')) {
      $class_method = explode('::', $function);
      return call_user_func_array([$class_method[0], $class_method[1]], array_filter([$default, $key], fn($v) => $v !== null));
    } else {
      $function_name = preg_replace('/[^a-zA-Z0-9_]/', '', $function);
      $params = [];

      if (preg_match('/^([a-zA-Z0-9_]+)\((.*)\)$/', $function, $matches)) {
        $function_name = $matches[1];
        $param_string = $matches[2];
        $params = str_getcsv($param_string);
      }

      if (!function_exists($function_name)) {
        $file1 = CLICSHOPPING::BASE_DIR . 'Sites/ClicShoppingAdmin/Assets/CfgParameters/' . $function_name . '.php';
        $file2 = CLICSHOPPING::BASE_DIR . 'Custom/SitesClicShoppingAdmin/Assets/CfgParameters/' . $function_name . '.php';

        if (is_file($file1)) {
          include($file1);
        } elseif (is_file($file2)) {
          include($file2);
        }
      }

      // Add $default and $key if provided
      if ($default !== null) $params[] = $default;
      if ($key !== null) $params[] = $key;

      return call_user_func_array($function_name, $params);
    }
  }
}