<?php
/**
 * Export Performance Data
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 */

use ClicShopping\OM\Registry;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\Apps\Tools\MCP\Classes\ClicShoppingAdmin\MCPConnector;
use ClicShopping\Apps\Tools\MCP\Classes\ClicShoppingAdmin\McpMockMonitor;
use ClicShopping\Apps\Configuration\Administrators\Classes\ClicShoppingAdmin\AdministratorAdmin;

define('CLICSHOPPING_BASE_DIR', realpath(__DIR__ . '/../../../Core/ClicShopping/') . '/');

require_once(CLICSHOPPING_BASE_DIR . 'OM/CLICSHOPPING.php');
spl_autoload_register('ClicShopping\OM\CLICSHOPPING::autoload');

CLICSHOPPING::initialize();
CLICSHOPPING::loadSite('ClicShoppingAdmin');
AdministratorAdmin::hasUserAccess();

$mcpConnector = MCPConnector::getInstance();

$token = $_GET['token'] ?? $_GET['sessiontoken'] ?? null;

if ($token !== $mcpConnector->getSessionToken()) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid MCP token']);
    exit();
}

$range = $_GET['range'] ?? '24h';
$format = $_GET['format'] ?? 'json';

// Create monitor instance
$config = MCPConnector::getConfigDb();
$monitor = new McpMockMonitor($config);

// Export data
$exportData = $monitor->exportData($range, $format);

// Set appropriate headers based on format
if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="mcp_performance_' . $range . '_' . date('Y-m-d') . '.csv"');
} else {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="mcp_performance_' . $range . '_' . date('Y-m-d') . '.json"');
}

echo $exportData;

