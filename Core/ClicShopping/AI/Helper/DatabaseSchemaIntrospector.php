<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Helper;

use ClicShopping\AI\Infrastructure\Orm\DoctrineOrm;

/**
 * DatabaseSchemaIntrospector (DEPRECATED - Use DoctrineOrm instead)
 * 
 * This class is deprecated and kept only for backward compatibility.
 * All methods now delegate to DoctrineOrm which is the proper place
 * for database operations.
 * 
 * @deprecated Use ClicShopping\AI\Infrastructure\Orm\DoctrineOrm instead
 */
class DatabaseSchemaIntrospector
{
  /**
   * @deprecated Use DoctrineOrm::getAllDatabaseFields() instead
   */
  public static function getAllDatabaseFields(bool $useCache = true): array
  {
    return DoctrineOrm::getAllDatabaseFields($useCache);
  }
  
  /**
   * @deprecated Use DoctrineOrm::getFieldsByTable() instead
   */
  public static function getFieldsByTable(bool $useCache = true): array
  {
    return DoctrineOrm::getFieldsByTable($useCache);
  }
  
  /**
   * @deprecated Use DoctrineOrm::isDatabaseField() instead
   */
  public static function isDatabaseField(string $word): bool
  {
    return DoctrineOrm::isDatabaseField($word);
  }
  
  /**
   * @deprecated Use DoctrineOrm::clearFieldCache() instead
   */
  public static function clearCache(): void
  {
    DoctrineOrm::clearFieldCache();
  }
}
