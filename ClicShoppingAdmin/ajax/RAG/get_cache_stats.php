<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

define('CLICSHOPPING_BASE_DIR', realpath(__DIR__ . '/../../../Core/ClicShopping/') . DIRECTORY_SEPARATOR);

require_once(CLICSHOPPING_BASE_DIR . 'OM/CLICSHOPPING.php');
spl_autoload_register('ClicShopping\OM\CLICSHOPPING::autoload');

use ClicShopping\AI\Domains\Analytics\Agent\AnalyticsAgent;
use ClicShopping\OM\CLICSHOPPING;

use ClicShopping\Apps\Configuration\Administrators\Classes\ClicShoppingAdmin\AdministratorAdmin;



CLICSHOPPING::initialize();
CLICSHOPPING::loadSite('ClicShoppingAdmin');
AdministratorAdmin::hasUserAccess();

header('Content-Type: application/json');

try {

  // Initialize AnalyticsAgent
  $agent = new AnalyticsAgent(1, true, 'admin');
  
  // Get cache statistics
  $stats = $agent->getQueryCacheStats();
  
  // Return success response
  echo json_encode([
    'success' => true,
    'data' => $stats
  ], JSON_PRETTY_PRINT);

} catch (\Exception $e) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'error' => $e->getMessage()
  ], JSON_PRETTY_PRINT);
}
