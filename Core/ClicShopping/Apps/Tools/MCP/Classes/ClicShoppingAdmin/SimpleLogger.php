<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 */


namespace ClicShopping\Apps\Tools\MCP\Classes\ClicShoppingAdmin;

use ClicShopping\OM\CLICSHOPPING;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Class SimpleLogger
 *
 * This class provides a basic implementation of the PSR-3 LoggerInterface.
 * It's designed to be a simple, file-based logger for the ClicShopping application.
 * It writes log messages to a file named `simple_logger.log` in the `Work/Log/` directory.
 */
class SimpleLogger implements LoggerInterface
{
  /**
   * Logs a message with the `emergency` level.
   *
   * @param Stringable|string $message The log message.
   * @param array $context The context data.
   */
  public function emergency(Stringable|string $message, array $context = []): void
  {
    $this->log(LogLevel::EMERGENCY, $message, $context);
  }

  /**
   * Logs a message with the `alert` level.
   *
   * @param Stringable|string $message The log message.
   * @param array $context The context data.
   */
  public function alert(Stringable|string $message, array $context = []): void
  {
    $this->log(LogLevel::ALERT, $message, $context);
  }

  /**
   * Logs a message with the `critical` level.
   *
   * @param Stringable|string $message The log message.
   * @param array $context The context data.
   */
  public function critical(Stringable|string $message, array $context = []): void
  {
    $this->log(LogLevel::CRITICAL, $message, $context);
  }

  /**
   * Logs a message with the `error` level.
   *
   * @param Stringable|string $message The log message.
   * @param array $context The context data.
   */
  public function error(Stringable|string $message, array $context = []): void
  {
    $this->log(LogLevel::ERROR, $message, $context);
  }

  /**
   * Logs a message with the `warning` level.
   *
   * @param Stringable|string $message The log message.
   * @param array $context The context data.
   */
  public function warning(Stringable|string $message, array $context = []): void
  {
    $this->log(LogLevel::WARNING, $message, $context);
  }

  /**
   * Logs a message with the `notice` level.
   *
   * @param Stringable|string $message The log message.
   * @param array $context The context data.
   */
  public function notice(Stringable|string $message, array $context = []): void
  {
    $this->log(LogLevel::NOTICE, $message, $context);
  }

  /**
   * Logs a message with the `info` level.
   *
   * @param Stringable|string $message The log message.
   * @param array $context The context data.
   */
  public function info(Stringable|string $message, array $context = []): void
  {
    $this->log(LogLevel::INFO, $message, $context);
  }

  /**
   * Logs a message with the `debug` level.
   *
   * @param Stringable|string $message The log message.
   * @param array $context The context data.
   */
  public function debug(Stringable|string $message, array $context = []): void
  {
    $this->log(LogLevel::DEBUG, $message, $context);
  }

  /**
   * Logs a message to the file system.
   *
   * This is the core method that all other log level methods call. It formats the message
   * with the log level and appends it to the `simple_logger.log` file.
   *
   * @param mixed $level The log level.
   * @param Stringable|string $message The log message.
   * @param array $context The context data.
   */
  public function log($level, Stringable|string $message, array $context = []): void
  {
    // The message is constructed with a timestamp and the log level in a standard format.
    // The file path is determined by the `CLICSHOPPING::BASE_DIR` constant to ensure
    // the log file is placed in a predictable location within the application's file structure.
    $msg = "[" . strtoupper($level) . "] " . $message . "\n";
    file_put_contents(CLICSHOPPING::BASE_DIR . 'Work/Log/simple_logger.log', $msg, FILE_APPEND);
  }
}