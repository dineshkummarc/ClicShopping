<?php
/**
 * ConfigurableComponent Interface
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Interfaces;

/**
 * Interface ConfigurableComponent
 * 
 * Defines a contract for components that support dynamic configuration.
 * Allows runtime modification of component parameters without code changes.
 * 
 * Example usage:
 * ```php
 * $component->setParameter('classification_threshold', 5);
 * $threshold = $component->getParameter('classification_threshold');
 * ```
 */
interface ConfigurableComponent
{
  /**
   * Returns the list of configurable parameters for this component
   * 
   * @return array Array of parameter definitions, each containing:
   *               - 'name': Parameter name (string)
   *               - 'type': Parameter type (int, float, bool, string)
   *               - 'default': Default value
   *               - 'description': Human-readable description
   *               - 'min': Minimum value (optional, for numeric types)
   *               - 'max': Maximum value (optional, for numeric types)
   *               - 'allowed_values': Array of allowed values (optional, for enum types)
   */
  public function getConfigurableParameters(): array;

  /**
   * Sets the value of a configuration parameter
   * 
   * @param string $name Parameter name
   * @param mixed $value New value for the parameter
   * @return bool True if the parameter was successfully set, false otherwise
   */
  public function setParameter(string $name, mixed $value): bool;

  /**
   * Gets the current value of a configuration parameter
   * 
   * @param string $name Parameter name
   * @return mixed Current value of the parameter, or null if parameter doesn't exist
   */
  public function getParameter(string $name): mixed;

  /**
   * Validates a parameter value before setting it
   * 
   * @param string $name Parameter name
   * @param mixed $value Value to validate
   * @return array Validation result with keys:
   *               - 'valid': bool indicating if value is valid
   *               - 'errors': array of error messages (empty if valid)
   *               - 'warnings': array of warning messages (optional)
   */
  public function validateParameter(string $name, mixed $value): array;
}
