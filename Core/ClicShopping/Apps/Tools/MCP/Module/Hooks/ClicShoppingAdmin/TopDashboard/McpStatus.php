<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Tools\MCP\Module\Hooks\ClicShoppingAdmin\TopDashboard;

use ClicShopping\OM\HTML;
use ClicShopping\OM\Registry;

use ClicShopping\Apps\Tools\MCP\MCP as MCPApp;
use ClicShopping\Apps\Tools\MCP\Classes\ClicShoppingAdmin\McpStatus as McpStatusClass;

class McpStatus implements \ClicShopping\OM\Modules\HooksInterface
{
  public mixed $app;
  public $group;

  /**
   * Initializes the module by setting up the required application instance,
   * language configurations, and loading module definitions. It also determines
   * the title, description, sort order, and enabled status of the module based on defined constants.
   *
   * @return void
   */
  public function __construct()
  {
    if (!Registry::exists('MCP')) {
      Registry::set('MCP', new MCPApp());
    }

    $this->app = Registry::get('MCP');
    $this->app->loadDefinitions('Module/Hooks/ClicShoppingAdmin/TopDashboard/top_dashboard_mcp');
    $this->title = $this->app->getDef('module_admin_dashboard_mcp_status_app_title');
    $this->description = $this->app->getDef('module_admin_dashboard_mcp_status_app_description');
  }

   /**
     * Generates and returns the HTML output for the MCP status dashboard widget.
     *
     * This method retrieves the current status of the MCP, formats it into a user-friendly
     * display with icons and quick stats, and provides a link to view more details.
     *
     * @return string The complete HTML content for rendering the MCP status widget.
     */
  public function Display(): string | bool
  {
    if (\defined('CLICSHOPPING_APP_MCP_MC_STATUS') && CLICSHOPPING_APP_MCP_MC_STATUS == 'False') {
      return false;
    }

    if (!Registry::exists('McpStatusClass')) {
        Registry::set('McpStatusClass', new McpStatusClass());
    }

    $health = Registry::get('McpStatusClass');
    $status = $health->check();

    $statusClass = $this->getStatusClass($status['status']);

    $output = '';
    $output .= '
<div class="col-md-2 col-12 m-1">
  <div class="card bg-primary shadow-sm border-0 rounded-3">
    <div class="card-body p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="card-title text-white mb-0">
          <i class="bi bi-signal ' . $statusClass . '"></i> MCP Status
        </h6>
        ' . HTML::link(
        $this->app->link('HealthMonitor'),
        '<i class="bi bi-info-circle text-white"></i>',
        'class="text-white text-decoration-none"'
      ) . '
      </div>
      <div class="text-white small">
        <div>' . $this->app->getDef('module_admin_dashboard_mcp_status_online') . ' '  . $status['status'] . ' / ' . $this->app->getDef('module_admin_dashboard_mcp_status_connexion') . ' ' . ($status['connectivity']['connected'] ? 'Yes' : 'No') . '</div>
      </div>
    </div>
  </div>
</div>
';
    
    return $output;
  }

  /**
     * Returns a CSS class based on the status of the MCP.
     *
     * @param string $status The status of the MCP (e.g., 'healthy', 'warning', 'error').
     * @return string The corresponding CSS class for the status.
     */
    private function getStatusClass(string $status): string
    {
        return match($status) {
            'healthy' => 'text-success',
            'warning' => 'text-warning',
            'error' => 'text-danger',
            default => 'text-secondary'
        };
    }
}