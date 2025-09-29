<?php
  /**
   *
   * @copyright 2008 - https://www.clicshopping.org
   * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
   * @Licence GPL 2 & MIT
   * @Info : https://www.clicshopping.org/forum/trademark/
   *
   */


use ClicShopping\OM\Registry;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\Apps\Configuration\Administrators\Classes\ClicShoppingAdmin\AdministratorAdmin;

define('CLICSHOPPING_BASE_DIR', realpath(__DIR__ . '/../../../Core/ClicShopping/') . '/');

require_once(CLICSHOPPING_BASE_DIR . 'OM/CLICSHOPPING.php');
spl_autoload_register('ClicShopping\OM\CLICSHOPPING::autoload');

CLICSHOPPING::initialize();
CLICSHOPPING::loadSite('ClicShoppingAdmin');
AdministratorAdmin::hasUserAccess();

$CLICSHOPPING_Db = Registry::get('Db');

  $page = (int)($_GET['page'] ?? 1);
  $filter = $_GET['filter'] ?? 'all';
  $alertsPerPage = 10;

  // Build query based on filter
  $whereClause = '';
  if ($filter !== 'all') {
      $whereClause = ' WHERE alert_type = :type';
  }

  // Count total alerts
  $Qcount = $CLICSHOPPING_Db->prepare('select count(*) as total 
                                      from :table_mcp_alerts' .
                                      $whereClause);

  if ($filter !== 'all') {
      $Qcount->bindValue(':type', $filter);
  }

  $Qcount->execute();
  $total = $Qcount->valueInt('total');

  // Get paginated alerts
  $Qalerts = $CLICSHOPPING_Db->prepare('select * 
                                       from :table_mcp_alerts' .
                                       $whereClause .
                                       ' order by alert_timestamp desc
                                        limit :offset, :limit
                                      ');

  if ($filter !== 'all') {
      $Qalerts->bindValue(':type', $filter);
  }

  $Qalerts->bindValue(':offset', ($page - 1) * $alertsPerPage, \PDO::PARAM_INT);
  $Qalerts->bindValue(':limit', $alertsPerPage, \PDO::PARAM_INT);
  $Qalerts->execute();

  $alerts = [];
  while ($alert = $Qalerts->fetch()) {
      $alerts[] = [
          'id' => (int)$alert['id'],
          'type' => $alert['alert_type'],
          'message' => $alert['message'],
          'alert_timestamp' => $alert['alert_timestamp']
      ];
  }

  header('Content-Type: application/json');
  echo json_encode([
      'success' => true,
      'alerts' => $alerts,
      'total_pages' => ceil($total / $alertsPerPage),
      'current_page' => $page
  ]);

  exit;

