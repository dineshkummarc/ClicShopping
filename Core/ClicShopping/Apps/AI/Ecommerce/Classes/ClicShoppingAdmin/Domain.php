<?php
/**
 * Domain-specific helpers for AI Ecommerce
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 */

namespace ClicShopping\Apps\AI\Ecommerce\Classes\ClicShoppingAdmin;

class Domain
{
  /**
   * Return domain-specific metadata fields, ordered by priority.
   *
   * @return array
   */
  public static function getPossibleFields(): array
  {
    return [
      // specific ecommerce
      'order_name',
      'product_name',
      'customer_name',
      'supplier_name',
      'category_name',
      'brand_name',
    ];
  }
}
