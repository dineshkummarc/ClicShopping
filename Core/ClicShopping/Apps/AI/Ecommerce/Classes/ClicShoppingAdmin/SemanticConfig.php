<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\AI\Ecommerce\Classes\ClicShoppingAdmin;

use ClicShopping\AI\InterfacesAI\SemanticConfigInterface;

/**
 * SemanticConfig Class
 *
 * Domain-specific semantic retrieval configuration for Ecommerce.
 * Return null values to keep admin/global defaults.
 */
class SemanticConfig implements SemanticConfigInterface
{
  public static function getEmbeddingTables(): array
  {
    // Empty array = use auto-discovery of *_embedding tables.
    return [];
  }

  public static function getSimilarityThreshold(): ?float
  {
    // Return null to use admin/global default.
    return null;
  }

  public static function getMaxResultsPerStore(): ?int
  {
    // Return null to use admin/global default.
    return null;
  }
}
