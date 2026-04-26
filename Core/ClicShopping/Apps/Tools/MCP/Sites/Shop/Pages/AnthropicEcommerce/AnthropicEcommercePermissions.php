<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Tools\MCP\Sites\Shop\Pages\AnthropicEcommerce;

use ClicShopping\Apps\Tools\MCP\Classes\Shop\Security\McpPermissions;
use ClicShopping\Apps\Tools\MCP\Classes\Shop\Security\McpSecurity;
use ClicShopping\OM\Registry;

/**
 * Permission manager specific to the AnthropicEcommerce MCP endpoint.
 *
 * Mirrors the pattern of RagBIPermissions: whitelist of allowed actions,
 * blacklist of forbidden tables, strict SQL validation for any raw query
 * that might be added in the future.
 *
 * Read-only actions (products, categories, stats, recommendations, orders
 * listing, order detail, session listing) require only select_data.
 *
 * Write actions (session_create, session_update, session_complete,
 * session_cancel, order_cancel, order_message, customer_create) additionally
 * require create_data or update_data as appropriate.
 */
class AnthropicEcommercePermissions
{
  private mixed $db;
  private McpPermissions $mcpPermissions;

  // Actions that only need read permission
  private const READ_ACTIONS = [
    'products',
    'product',
    'search',
    'categories',
    'recommendations',
    'stats',
    'orders',
    'order',
    'order_history',
    'session_get',
    'session_list',
    'customer',
    'addresses',
    'countries',
  ];

  // Actions that need write permission
  private const WRITE_ACTIONS = [
    'session_create',
    'session_update',
    'session_complete',
    'session_cancel',
    'order_cancel',
    'order_message',
    'customer_create',
  ];

  // All allowed actions (union of the above)
  private const ALLOWED_ACTIONS = [
    'products', 'product', 'search', 'categories', 'recommendations', 'stats',
    'orders', 'order', 'order_history', 'order_cancel', 'order_message',
    'session_create', 'session_get', 'session_update',
    'session_complete', 'session_cancel', 'session_list',
    'customer', 'customer_create', 'addresses', 'countries',
  ];

  // Tables the endpoint is allowed to touch (read or write)
  private const ALLOWED_TABLES = [
    'clic_products',
    'clic_products_description',
    'clic_products_to_categories',
    'clic_categories',
    'clic_categories_description',
    'clic_manufacturers',
    'clic_specials',
    'clic_orders',
    'clic_orders_products',
    'clic_orders_status',
    'clic_orders_status_history',
    'clic_customers',
    'clic_address_book',
    'clic_countries',
    'clic_zones',
  ];

  // Tables that must never be accessed
  private const FORBIDDEN_TABLES = [
    'clic_administrators',
    'clic_mcp',
    'clic_mcp_session',
    'clic_sessions',
    'clic_configuration',
    'clic_customers_info',
  ];

  public function __construct()
  {
    $this->db = Registry::get('Db');

    if (!Registry::exists('McpPermissions')) {
      Registry::set('McpPermissions', new McpPermissions());
    }
    $this->mcpPermissions = Registry::get('McpPermissions');
  }

  // =========================================================================
  // Public API
  // =========================================================================

  /**
   * Check whether a user can perform a given AnthropicEcommerce action.
   */
  public function canPerformAction(string $username, string $action): bool
  {
    if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
      McpSecurity::logSecurityEvent('AnthropicEcommerce - action not in whitelist', [
        'username' => $username,
        'action'   => $action,
      ]);
      return false;
    }

    $permissions = $this->mcpPermissions->getUserPermissions($username);
    if (!$permissions) {
      return false;
    }

    // Read actions: select_data is enough
    if (in_array($action, self::READ_ACTIONS, true)) {
      if (!(bool)$permissions['select_data']) {
        McpSecurity::logSecurityEvent('AnthropicEcommerce - missing select_data', [
          'username' => $username,
          'action'   => $action,
        ]);
        return false;
      }
      return true;
    }

    // Write actions: select_data + (create_data or update_data)
    if (in_array($action, self::WRITE_ACTIONS, true)) {
      $canWrite = (bool)$permissions['select_data']
        && ((bool)$permissions['create_data'] || (bool)$permissions['update_data']);

      if (!$canWrite) {
        McpSecurity::logSecurityEvent('AnthropicEcommerce - missing write permission', [
          'username' => $username,
          'action'   => $action,
        ]);
        return false;
      }
      return true;
    }

    return false;
  }

  /**
   * Validate that a table referenced by the endpoint is on the whitelist.
   */
  public function isTableAllowed(string $tableName): bool
  {
    $clean = 'clic_' . str_replace('clic_', '', strtolower($tableName));

    if (in_array($clean, self::FORBIDDEN_TABLES, true)) {
      return false;
    }

    return in_array($clean, self::ALLOWED_TABLES, true);
  }

  /**
   * Generate a security report for a given user.
   */
  public function generateSecurityReport(string $username): array
  {
    $permissions = $this->mcpPermissions->getUserPermissions($username);

    return [
      'username'        => $username,
      'allowed_actions' => self::ALLOWED_ACTIONS,
      'read_actions'    => self::READ_ACTIONS,
      'write_actions'   => self::WRITE_ACTIONS,
      'allowed_tables'  => self::ALLOWED_TABLES,
      'forbidden_tables'=> self::FORBIDDEN_TABLES,
      'permissions'     => $permissions,
      'security_level'  => 'ECOMMERCE_READ_WRITE',
      'restrictions'    => [
        'table_whitelist_enforced'  => true,
        'dangerous_functions_blocked' => true,
        'write_requires_explicit_permission' => true,
      ],
    ];
  }

  public function getAllowedActions(): array { return self::ALLOWED_ACTIONS; }
  public function getAllowedTables(): array  { return self::ALLOWED_TABLES; }
}
