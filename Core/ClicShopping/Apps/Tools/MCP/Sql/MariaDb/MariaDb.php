<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\MCP\MCP\Sql\MariaDb;

use ClicShopping\OM\Cache;
use ClicShopping\OM\Registry;

class MariaDb
{
  /**
   * Executes the installation process for the Customers module by loading
   * language definitions and performing database setup operations.
   *
   * @return void
   */
  public function execute()
  {
    $CLICSHOPPING_MCP = Registry::get('MCP');
    $CLICSHOPPING_MCP->loadDefinitions('Sites/ClicShoppingAdmin/install');

    self::installDbMenuAdministration();
    self::installDb();
  }

  /**
   * Installs the database entries for the administrator menu related to customer management.
   *
   * Checks if specific menu entries associated with customer-related functionality
   * already exist in the `administrator_menu` table. If not, adds the entries along
   * with their descriptions for all supported languages. Clears the administrator menu cache upon completion.
   *
   * @return void
   */
  private static function installDbMenuAdministration(): void
  {
    $CLICSHOPPING_Db = Registry::get('Db');
    $CLICSHOPPING_MCP = Registry::get('MCP');
    $CLICSHOPPING_Language = Registry::get('Language');

    $Qcheck = $CLICSHOPPING_Db->get('administrator_menu', 'app_code', ['app_code' => 'app_tools_mcp']);

    if ($Qcheck->fetch() === false) {
      $sql_data_array = [
        'sort_order' => 1,
        'link' => 'index.php?A&Tools\MCP&MCP',
        'image' => '',
        'b2b_menu' => 0,
        'access' => 1,
        'app_code' => 'app_tools_mcp'
      ];

      $insert_sql_data = ['parent_id' => 163];
      $sql_data_array = array_merge($sql_data_array, $insert_sql_data);

      $CLICSHOPPING_Db->save('administrator_menu', $sql_data_array);

      $id = $CLICSHOPPING_Db->lastInsertId();
      $languages = $CLICSHOPPING_Language->getLanguages();

      for ($i = 0, $n = \count($languages); $i < $n; $i++) {
        $language_id = $languages[$i]['id'];
        $sql_data_array = ['label' => $CLICSHOPPING_MCP->getDef('title_menu')];

        $insert_sql_data = [
          'id' => (int)$id,
          'language_id' => (int)$language_id
        ];

        $sql_data_array = array_merge($sql_data_array, $insert_sql_data);

        $CLICSHOPPING_Db->save('administrator_menu_description', $sql_data_array);
      }

      Cache::clear('menu-administrator');
    }
  }

  /**
   * Installs the database table for customers if it does not already exist.
   *
   * Checks the database for the presence of the customers table. If it does not exist,
   * the method creates the table with the specified schema, defining various customer-related
   * fields, constraints, and default values.
   *
   * @return void
   */
  private static function installDb()
  {
    $CLICSHOPPING_Db = Registry::get('Db');

    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_mcp_alerts"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
CREATE TABLE :table_mcp_alerts (
  `id` int(11) NOT NULL,
  `alert_type` varchar(32) NOT NULL,
  `message` text NOT NULL,
  `alert_timestamp` datetime NOT NULL,
  `severity_level` int(11) NOT NULL DEFAULT 1,
  `context` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE :table_mcp_alerts
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_alert_timestamp` (`alert_timestamp`),
  ADD KEY `idx_severity` (`severity_level`);

EOD;
      $CLICSHOPPING_Db->exec($sql);
    }

    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_mcp_performance_history"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
       CREATE TABLE :table_mcp_performance_history (
          `id` int(11) NOT NULL,
          `timestamp` int(11) NOT NULL,
          `request_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
          `average_latency` decimal(10,2) NOT NULL DEFAULT 0.00,
          `error_frequency` decimal(5,2) NOT NULL DEFAULT 0.00,
          `uptime_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
          `total_requests` int(11) NOT NULL DEFAULT 0,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        
        ALTER TABLE `clic_mcp_performance_history`
        ADD PRIMARY KEY (`id`),
        ADD KEY `idx_timestamp` (`timestamp`),
        ADD KEY `idx_created_at` (`created_at`);
EOD;
      $CLICSHOPPING_Db->exec($sql);
    }
  }
}