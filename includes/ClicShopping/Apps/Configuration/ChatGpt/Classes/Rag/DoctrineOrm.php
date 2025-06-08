<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\DBAL\Types\Type;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\VectorType;

/**
* Class DoctrineOrm
 *
 * This class manages database connections and operations using Doctrine ORM,
 * specifically adapted for use with LLPhant and MariaDB vector operations.
 * It provides functionality for:
  * - Database connection management
* - MariaDB version verification
* - Table structure management for RAG (Retrieval-Augmented Generation)
 * - Vector embedding table operations
*
 * Requirements:
 * - MariaDB version 11.7.0 or higher
* - Proper database credentials configuration
* - Vector support in MariaDB
*
 * @package ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag
*/
class DoctrineOrm
{
  /**
   * Configures and initializes Doctrine ORM settings.
   * Sets up the database connection parameters and ORM configuration.
   *
   * @return array Array containing connection parameters and configuration
   * @throws \Exception If configuration cannot be initialized
   */
  private static function Orm(): array
  {
    $config = ORMSetup::createConfiguration(true, null, null);
    $config->setMetadataDriverImpl(new \Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver([]));

    $connectionParams = [
      'driver' => 'pdo_mysql',
      'user' => CLICSHOPPING::getConfig('db_server_username'),
      'password' => CLICSHOPPING::getConfig('db_server_password'),
      'dbname' => CLICSHOPPING::getConfig('db_database'),
      'host' => CLICSHOPPING::getConfig('db_server'),
      'charset' => 'utf8mb4',
      'driverOptions' => [
        \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
      ],
    ];

    try {
      $temporaryConnection = \Doctrine\DBAL\DriverManager::getConnection($connectionParams, $config);
      $serverVersion = $temporaryConnection->fetchOne("SELECT VERSION()");

      if ($serverVersion) {
        $serverVersion = strtolower($serverVersion);
        if (strpos($serverVersion, 'mariadb') !== false) {
          $connectionParams['serverVersion'] = 'mariadb';
        } else {
          $connectionParams['serverVersion'] = 'mysql9';
        }
      } else {
        error_log('Unable to fetch a valid server version, proceeding without it.');
        $connectionParams['serverVersion'] = 'mysql8'; // Default version
      }
    } catch (\Exception $e) {
      error_log('Unable to fetch server version, defaulting to version mysql8: ' . $e->getMessage());
      $connectionParams['serverVersion'] = 'mysql8'; // Default version
    }

    return ['connectionParams' => $connectionParams, 'config' => $config];
  }



  /**
   * Creates and returns an instance of the EntityManager.
   * Initializes the database connection and registers custom vector types.
   *
   * @return EntityManager The configured EntityManager instance
   * @throws \Doctrine\DBAL\Exception If connection cannot be established
   */
  public static function getEntityManager(): EntityManager
  {
    $orm = self::Orm();
    $connectionParams = $orm['connectionParams'];
    $config = $orm['config'];

    // Create the connection using the correct driver (pdo_mysql in this case)
    $connection = \Doctrine\DBAL\DriverManager::getConnection($connectionParams, $config);

    if (!Type::hasType('vector')) {
      Type::addType('vector', VectorType::class);
    }

    // EntityManager creation
    return new EntityManager($connection, $config);
  }

  /**
   * Checks if the database has the necessary tables and structures for RAG.
   * Verifies both table existence and required index presence.
   *
   * @param string $tableName Name of the table to check
   * @return bool True if the structure is correct, false otherwise
   */
   public static function checkTableStructure(string $tableName): bool
   {
     try {
       $entityManager = self::getEntityManager();
       $connection = $entityManager->getConnection();

//    check if prefix is on $tableName or not
       $prefix = CLICSHOPPING::getConfig('db_prefix');
       if (strpos($tableName, $prefix) !== 0) {
         $tableName = $prefix . $tableName;
       }

       // Check table existence
       $sql = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?";
       $tableExists = $connection->fetchOne($sql, [$tableName]);

       if ((int)$tableExists === 0) {
         return false;
       }

       // Check index existence
       $sql = "SHOW INDEX FROM `$tableName` WHERE Key_name = ?";
       $indexExists = $connection->fetchOne($sql, ['embedding_index']);

       return $indexExists !== false;
     } catch (\Exception $e) {
       if (\defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True') {
         error_log('Error while checking structure for table ' . $tableName . ': ' . $e->getMessage());
       }
       return false;
     }
   }

  /**
   * Returns a list of all available embedding tables in the database.
   * Queries the database to find tables that contain a VECTOR type embedding column.
   *
   * @return array List of table names containing vector embeddings
   * @throws \Exception If there is an error connecting to the database or executing the query
   */
  public static function getEmbeddingTables(): array
  {
    try {
      $entityManager = self::getEntityManager();
      $connection = $entityManager->getConnection();

      //Seach inside all tables for the embedding column

      $sql = "
        SELECT table_name
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND column_name = 'embedding'
          AND data_type LIKE '%vector%'
      ";

      return $connection->fetchFirstColumn($sql);
    } catch (\Exception $e) {
      if (\defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True') {
        error_log('Error while retrieving embedding tables: ' . $e->getMessage());
      }
      return [];
    }
  }

  /**
   * Logs an error message if debugging is enabled.
   * This function is used to log errors related to database operations.
   *
   * @param string $message The error message to log
   */
  private static function logError($message)
  {
    if (\defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True') {
      error_log($message);
    }
  }
  
  /**
   * Creates the necessary database structure for RAG if it doesn't exist.
   * Sets up tables with appropriate columns and vector indices for embedding storage.
   *
   * @param string $tableName Name of the table to create
   * @return bool True if creation succeeds, false otherwise
   * @throws \Exception If table creation fails
   */
  public static function createTableStructure(string $tableName): bool
  {
     return false;
  }
}
