<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Module\ClicShoppingAdmin\Dashboard;

use ClicShopping\OM\HTML;
use ClicShopping\OM\Registry;
use ClicShopping\Apps\Configuration\ChatGpt\ChatGpt as ChatGptApp;

class CostEstimation extends \ClicShopping\OM\Modules\AdminDashboardAbstract
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
  protected function init()
  {
    if (!Registry::exists('ChatGpt')) {
      Registry::set('ChatGpt', new ChatGptApp());
    }

    $this->app = Registry::get('ChatGpt');

    $this->app->loadDefinitions('Module/ClicShoppingAdmin/Dashboard/cost_estimation');

    $this->title = $this->app->getDef('module_admin_dashboard_total_cost_estimation_app_title');
    $this->description = $this->app->getDef('module_admin_dashboard_total_cost_estimation_app_description');

    if (\defined('MODULE_ADMIN_DASHBOARD_TOTAL_COST_ESTIMATION_APP_STATUS')) {
      $this->sort_order = (int)MODULE_ADMIN_DASHBOARD_TOTAL_COST_ESTIMATION_APP_SORT_ORDER ?? 0;
      $this->enabled = (MODULE_ADMIN_DASHBOARD_TOTAL_COST_ESTIMATION_APP_STATUS == 'True');
    }
  }

  /**
   * Generates and returns the HTML and JavaScript output for a dashboard widget that displays
   * a bar chart of GPT token usage and costs over the past 12 months.
   *
   * The method retrieves token usage data from the database, calculates costs based on the
   * application price configuration, structures the data into JSON format for use in a JavaScript chart,
   * and generates the necessary HTML and chart configuration.
   *
   * @return string The complete HTML and JavaScript content for rendering the dashboard widget.
   */
  public function getOutput(): string
  {
    $modelPrices = [
      'gpt-5-nano'    => 0.0005,
      'gpt-5-mini'    => 0.0025,
      'gpt-5'         => 0.0125,
      'gpt-4.1-mini'  => 0.0012,
      'gpt-4.1-nano'  => 0.0008,
      'gpt-4o'        => 0.0025,
      'gpt-3.5-turbo' => 0.0005,
    ];

    $colors = [
      'gpt-5-nano'    => 'rgba(255, 99, 132, 0.5)',    // Red-ish
      'gpt-5-mini'    => 'rgba(54, 162, 235, 0.5)',    // Blue
      'gpt-5'         => 'rgba(255, 206, 86, 0.5)',    // Yellow
      'gpt-4.1-mini'  => 'rgba(75, 192, 192, 0.5)',    // Teal
      'gpt-4.1-nano'  => 'rgba(153, 102, 255, 0.5)',   // Purple
      'gpt-4o'        => 'rgba(255, 159, 64, 0.5)',    // Orange
      'gpt-3.5-turbo' => 'rgba(100, 255, 218, 0.5)',   // Aqua Green
    ];

    // Query by month *and* model
    $Qorders = $this->app->db->query("
        SELECT DATE_FORMAT(date_added, '%Y-%m') as month, 
               model,
               SUM(totalTokens) as total
        FROM :table_gpt_usage
        WHERE date_added >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY month, model
        ORDER BY month ASC
    ");

    $rawData = [];

    while ($Qorders->fetch()) {
      $month = $Qorders->value('month');
      $model = $Qorders->value('model');
      $totalTokens = $Qorders->valueInt('total');

      if (!isset($rawData[$model])) {
        $rawData[$model] = [];
      }

      $rawData[$model][$month] = ($totalTokens) * (($modelPrices[$model] ?? 0.0005) / 10);
    }

    // Collect all months (even missing for some models)
    $allMonths = [];

    foreach ($rawData as $modelData) {
      foreach ($modelData as $month => $value) {
        $allMonths[$month] = true;
      }
    }

    ksort($allMonths); // sort months ascending
    $labels = array_keys($allMonths);

    $datasets = [];

    foreach ($rawData as $model => $data) {
      $modelData = [];

      foreach ($labels as $month) {
        $modelData[] = $data[$month] ?? 0; // fill missing months with 0
      }

      $datasets[] = [
        'label' => $model,
        'data' => $modelData,
        'backgroundColor' => $colors[$model] ?? 'rgba(0,0,0,0.3)',
      ];
    }

    $data_labels = json_encode($labels);
    $data_datasets = json_encode($datasets);

    $chart_label_link = $this->app->getDef('module_admin_dashboard_total_cost_estimation_app_chart_link');
    $content_width = 'col-md-' . (int)MODULE_ADMIN_DASHBOARD_TOTAL_COST_ESTIMATION_APP_CONTENT_WIDTH;

    $output = <<<EOD
<div class="col-12 {$content_width} d-flex" style="padding-right:0.5rem; padding-top:0.5rem">
  <div class="card flex-fill h-215">
    <div class="card-block">
      <div class="card-body">
        <h6 class="card-title"><i class="bi bi-graph-up"></i> {$chart_label_link}</h6>
        <p class="card-text">
          <div class="col-md-12">
            <canvas id="d_total_cost_estimation_app" class="col-md-12" style="display: block; width:100%; height: 215px;"></canvas>
          </div>
        </p>
      </div>
    </div>
  </div>
</div>

<script>
var ctx = document.getElementById('d_total_cost_estimation_app').getContext('2d');
var myChart = new Chart(ctx, {
    type: 'bar',
    data: {
        labels: $data_labels,
        datasets: $data_datasets
    },
    options: {
        maintainAspectRatio: true,
        responsive: true,
        scales: {
            y: {
                beginAtZero: true
            },
            x: {
                stacked: true
            },
            y: {
                stacked: true
            }
        },
        plugins: {
          legend: {
            display: true
          }
        }
    }
});
</script>
EOD;
    return $output;
  }


  /**
   * Installs the module by saving necessary configuration settings to the database.
   *
   * @return void
   */
  public function Install()
  {
    $this->app->db->save('configuration', [
        'configuration_title' => 'Do you want to enable this Module ?',
        'configuration_key' => 'MODULE_ADMIN_DASHBOARD_TOTAL_COST_ESTIMATION_APP_STATUS',
        'configuration_value' => 'True',
        'configuration_description' => 'Do you want to enable this Module ?',
        'configuration_group_id' => '6',
        'sort_order' => '1',
        'set_function' => 'clic_cfg_set_boolean_value(array(\'True\', \'False\'))',
        'date_added' => 'now()'
      ]
    );

    $this->app->db->save('configuration', [
        'configuration_title' => 'Select the width to display',
        'configuration_key' => 'MODULE_ADMIN_DASHBOARD_TOTAL_COST_ESTIMATION_APP_CONTENT_WIDTH',
        'configuration_value' => '6',
        'configuration_description' => 'Select a number between 1 to 12',
        'configuration_group_id' => '6',
        'sort_order' => '1',
        'set_function' => 'clic_cfg_set_content_module_width_pull_down',
        'date_added' => 'now()'
      ]
    );

    $this->app->db->save('configuration', [
        'configuration_title' => 'Do you want to enable this Module ?',
        'configuration_key' => 'MODULE_ADMIN_DASHBOARD_TOTAL_COST_ESTIMATION_APP_PRICE',
        'configuration_value' => '0.001',
        'configuration_description' => 'Sort order of display. Lowest is displayed first.',
        'configuration_group_id' => '6',
        'sort_order' => '2',
        'set_function' => '',
        'date_added' => 'now()'
      ]
    );

    $this->app->db->save('configuration', [
        'configuration_title' => 'Sort Order',
        'configuration_key' => 'MODULE_ADMIN_DASHBOARD_TOTAL_COST_ESTIMATION_APP_SORT_ORDER',
        'configuration_value' => '50',
        'configuration_description' => 'Sort order of display. Lowest is displayed first.',
        'configuration_group_id' => '6',
        'sort_order' => '2',
        'set_function' => '',
        'date_added' => 'now()'
      ]
    );
  }

  /**
   * Retrieves a list of configuration keys related to the dashboard total cost estimation module.
   *
   * @return array An array of configuration keys used by the total cost estimation module.
   */
  public function keys(): array
  {
    return [
      'MODULE_ADMIN_DASHBOARD_TOTAL_COST_ESTIMATION_APP_STATUS',
      'MODULE_ADMIN_DASHBOARD_TOTAL_COST_ESTIMATION_APP_CONTENT_WIDTH',
      'MODULE_ADMIN_DASHBOARD_TOTAL_COST_ESTIMATION_APP_PRICE',
      'MODULE_ADMIN_DASHBOARD_TOTAL_COST_ESTIMATION_APP_SORT_ORDER'
    ];
  }
}
