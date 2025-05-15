<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\OM;

class OnlineUpdate
{
  /**
   * @param string $message
   * @param string $version
   */
  public static function log(string $message, string $version)
  {
    if (FileSystem::isWritable(CLICSHOPPING::BASE_DIR . 'Work/OnlineUpdates/' . $version . '-log.txt')) {
      $message = '[' . date('d-M-Y H:i:s') . '] ' . trim($message) . "\n";

      file_put_contents(CLICSHOPPING::BASE_DIR . 'Work/OnlineUpdates/' . $version . '-log.txt', $message, FILE_APPEND);
    }
  }

  /**
   * @param string $version
   */
  public static function resetLog(string $version)
  {
    if (static::logExists($version) && FileSystem::isWritable(CLICSHOPPING::BASE_DIR . 'work/OnlineUpdates/' . $version . '-log.txt')) {
      unlink(CLICSHOPPING::BASE_DIR . 'Work/OnlineUpdates/' . $version . '-log.txt');
    }
  }

  /**
   * @param string $version
   * @return string
   */
  public static function getLog(string $version)
  {
    $result = '';

    if (static::logExists($version)) {
      $result = file_get_contents(CLICSHOPPING::BASE_DIR . 'Work/OnlineUpdates/' . $version . '-log.txt');
    }

    return trim($result);
  }

  /**
   * @param string $version
   * @return bool
   */
  public static function logExists(string $version)
  {
    return is_file(CLICSHOPPING::BASE_DIR . 'Work/OnlineUpdates/' . $version . '-log.txt');
  }

  /**
   * @param string $version
   * @return mixed|string
   */
  public static function getLogPath(string $version)
  {
    $result = '';

    if (static::logExists($version)) {
      $result = FileSystem::displayPath(CLICSHOPPING::BASE_DIR . 'Work/OnlineUpdates/' . $version . '-log.txt');
    }

    return $result;
  }
}
