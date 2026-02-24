<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\InterfacesAI;

/**
 * SchemaConfigInterface
 *
 * Contract for domain-specific schema rules used by the AI schema retriever.
 * Implementations should provide concise, LLM-friendly guidance for their domain.
 *
 * @package ClicShopping\AI\InterfacesAI
 */
interface SchemaConfigInterface
{
  /**
   * Get schema rules as an array of strings.
   *
   * @return array
   */
  public static function getSchemaRules(): array;

  /**
   * Get schema rules as a single formatted string.
   *
   * @return string
   */
  public static function getSchemaRulesString(): string;
}
