<?php
/**
 * ProductHelper - Helper functions for product data retrieval
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 */

namespace ClicShopping\AI\Helper;

use ClicShopping\OM\Registry;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Infrastructure\Orm\DoctrineOrm;

/**
 * ProductHelper Class
 *
 * Provides utility functions for retrieving product data from the database.
 * Used by query executors and other components that need product information.
 * 
 * TASK 2.18: Created to support context memory for follow-up queries
 */
class ProductHelper
{
  private static ?SecurityLogger $logger = null;
  private static bool $debug = false;

  /**
   * Initialize helper with debug mode
   *
   * @param bool $debug Enable debug logging
   * @return void
   */
  public static function initialize(bool $debug = false): void
  {
    self::$debug = $debug;
    if (self::$logger === null) {
      self::$logger = new SecurityLogger();
    }
  }

  /**
   * Get product details by ID
   * 
   * Retrieves product information from database including:
   * - Product ID
   * - Product name (localized)
   * - Price
   * - Model/SKU
   * - EAN/GTIN
   * - Stock quantity
   *
   * @param int $productId Product ID
   * @param int|null $languageId Language ID (defaults to current language)
   * @return array|null Product data or null if not found
   */
  public static function getProductById(int $productId, ?int $languageId = null): ?array
  {
    try {
      // 🔧 TASK 4.4.1 PHASE 8: Migrated to DoctrineOrm
      if ($languageId === null) {
        $languageId = Registry::get('Language')->getId();
      }
      
      $sql = "SELECT 
                p.products_id as product_id,
                pd.products_name as name,
                p.products_price as price,
                p.products_model as model,
                p.products_ean as ean,
                p.products_quantity as stock
              FROM :table_products p
              INNER JOIN :table_products_description pd 
                ON p.products_id = pd.products_id
              WHERE p.products_id = :product_id
              AND pd.language_id = :language_id
              LIMIT 1";
      
      $product = DoctrineOrm::selectOne($sql, [
        'product_id' => $productId,
        'language_id' => $languageId
      ]);
      
      if ($product) {
        if (self::$debug && self::$logger !== null) {
          self::$logger->logSecurityEvent(
            "ProductHelper: Retrieved product by ID {$productId}: {$product['name']}",
            'info'
          );
        }
        
        return $product;
      }
      
      if (self::$debug && self::$logger !== null) {
        self::$logger->logSecurityEvent(
          "ProductHelper: Product not found with ID {$productId}",
          'warning'
        );
      }
      
      return null;
      
    } catch (\Exception $e) {
      if (self::$logger !== null) {
        self::$logger->logSecurityEvent(
          "ProductHelper: Error getting product by ID {$productId}: " . $e->getMessage(),
          'error'
        );
      }
      return null;
    }
  }

  /**
   * Get product name by ID
   * 
   * Quick method to retrieve just the product name
   *
   * @param int $productId Product ID
   * @param int|null $languageId Language ID (defaults to current language)
   * @return string|null Product name or null if not found
   */
  public static function getProductName(int $productId, ?int $languageId = null): ?string
  {
    $product = self::getProductById($productId, $languageId);
   
    return $product['name'] ?? null;
  }

  /**
   * Get multiple products by IDs
   * 
   * Batch retrieval for multiple products
   *
   * @param array $productIds Array of product IDs
   * @param int|null $languageId Language ID (defaults to current language)
   * @return array Array of product data indexed by product_id
   */
  public static function getProductsByIds(array $productIds, ?int $languageId = null): array
  {
    if (empty($productIds)) {
      return [];
    }
    
    try {
      // 🔧 TASK 4.4.1 PHASE 8: Migrated to DoctrineOrm
      if ($languageId === null) {
        $languageId = Registry::get('Language')->getId();
      }
      
      // Build placeholders for IN clause
      $placeholders = implode(',', array_fill(0, count($productIds), '?'));
      
      $sql = "SELECT 
                p.products_id as product_id,
                pd.products_name as name,
                p.products_price as price,
                p.products_model as model,
                p.products_ean as ean,
                p.products_quantity as stock
              FROM :table_products p
              INNER JOIN :table_products_description pd 
                ON p.products_id = pd.products_id
              WHERE p.products_id IN ({$placeholders})
              AND pd.language_id = :language_id";
      
      // Prepare parameters: product IDs as positional, language_id as named
      $params = array_merge($productIds, ['language_id' => $languageId]);
      
      $rows = DoctrineOrm::select($sql, $params);
      
      $products = [];
      foreach ($rows as $row) {
        $productId = (int)$row['product_id'];
        $products[$productId] = $row;
      }
      
      if (self::$debug && self::$logger !== null) {
        self::$logger->logSecurityEvent(
          "ProductHelper: Retrieved " . count($products) . " products by IDs",
          'info'
        );
      }
      
      return $products;
      
    } catch (\Exception $e) {
      if (self::$logger !== null) {
        self::$logger->logSecurityEvent(
          "ProductHelper: Error getting products by IDs: " . $e->getMessage(),
          'error'
        );
      }
      return [];
    }
  }

  /**
   * Check if product exists
   *
   * @param int $productId Product ID
   * @return bool True if product exists
   */
  public static function productExists(int $productId): bool
  {
    try {
      // 🔧 TASK 4.4.1 PHASE 8: Migrated to DoctrineOrm
      $sql = "SELECT COUNT(*) as count
              FROM :table_products
              WHERE products_id = :product_id";
      
      $count = DoctrineOrm::selectValue($sql, ['product_id' => $productId]);
      
      return $count > 0;
      
    } catch (\Exception $e) {
      if (self::$logger !== null) {
        self::$logger->logSecurityEvent(
          "ProductHelper: Error checking product existence: " . $e->getMessage(),
          'error'
        );
      }
      return false;
    }
  }

  /**
   * Get product by model/SKU
   *
   * @param string $model Product model/SKU
   * @param int|null $languageId Language ID (defaults to current language)
   * @return array|null Product data or null if not found
   */
  public static function getProductByModel(string $model, ?int $languageId = null): ?array
  {
    try {
      // 🔧 TASK 4.4.1 PHASE 8: Migrated to DoctrineOrm
      if ($languageId === null) {
        $languageId = Registry::get('Language')->getId();
      }
      
      $sql = "SELECT 
                p.products_id as product_id,
                pd.products_name as name,
                p.products_price as price,
                p.products_model as model,
                p.products_ean as ean,
                p.products_quantity as stock
              FROM :table_products p
              INNER JOIN :table_products_description pd 
                ON p.products_id = pd.products_id
              WHERE p.products_model = :model
              AND pd.language_id = :language_id
              LIMIT 1";
      
      return DoctrineOrm::selectOne($sql, [
        'model' => $model,
        'language_id' => $languageId
      ]);
      
    } catch (\Exception $e) {
      if (self::$logger !== null) {
        self::$logger->logSecurityEvent(
          "ProductHelper: Error getting product by model {$model}: " . $e->getMessage(),
          'error'
        );
      }
      return null;
    }
  }

  /**
   * Get product by EAN/GTIN
   *
   * @param string $ean Product EAN/GTIN
   * @param int|null $languageId Language ID (defaults to current language)
   * @return array|null Product data or null if not found
   */
  public static function getProductByEan(string $ean, ?int $languageId = null): ?array
  {
    try {
      // 🔧 TASK 4.4.1 PHASE 8: Migrated to DoctrineOrm
      if ($languageId === null) {
        Registry::get('Language')->getId();
      }
      
      $sql = "SELECT 
                p.products_id as product_id,
                pd.products_name as name,
                p.products_price as price,
                p.products_model as model,
                p.products_ean as ean,
                p.products_quantity as stock
              FROM :table_products p
              INNER JOIN :table_products_description pd 
                ON p.products_id = pd.products_id
              WHERE p.products_ean = :ean
              AND pd.language_id = :language_id
              LIMIT 1";
      
      return DoctrineOrm::selectOne($sql, [
        'ean' => $ean,
        'language_id' => $languageId
      ]);
      
    } catch (\Exception $e) {
      if (self::$logger !== null) {
        self::$logger->logSecurityEvent(
          "ProductHelper: Error getting product by EAN {$ean}: " . $e->getMessage(),
          'error'
        );
      }
      return null;
    }
  } 
}

