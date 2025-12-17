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

use ClicShopping\Apps\Configuration\ChatGpt\ChatGpt as ChatGptApp;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;

/**
 * Centralised provider for all token related chart data used across the
 * ChatGPT dashboard widgets (TotalToken, SumTotalTokenByMonth, CostEstimation)
 * and the tab #5 analytics view. The goal is to avoid duplicated SQL queries
 * and ensure every consumer relies on the exact same payload.
 */
class TokenChartDataProvider
{
  private static ?array $cache = null;

  /**
   * Returns all chart configurations (already formatted for Chart.js) so that
   * every consumer only needs to JSON encode the structure and feed it to the
   * frontend script. Data is cached per-request to avoid running the queries
   * multiple times.
   */
  public static function getChartsData(): array
  {
    if (self::$cache !== null) {
      return self::$cache;
    }

    $db = Registry::get('Db');

    if (!Registry::exists('ChatGpt')) {
      Registry::set('ChatGpt', new ChatGptApp());
    }

    /** @var \ClicShopping\Apps\Configuration\ChatGpt\ChatGpt $app */
    $app = Registry::get('ChatGpt');
    $app->loadDefinitions('Module/ClicShoppingAdmin/Dashboard/total_token');
    $app->loadDefinitions('Module/ClicShoppingAdmin/Dashboard/sum_total_token');
    $app->loadDefinitions('Module/ClicShoppingAdmin/Dashboard/cost_estimation');

    $dailyMap = [];
    // 🔧 UPDATED: Read from rag_statistics instead of gpt_usage to support all providers (GPT, LMStudio, Ollama, etc.)
    $Qdaily = $db->query(
      'select DATE(date_added) as usage_date,
              sum(tokens_total) as total
       from :table_rag_statistics
       where date_sub(curdate(), interval 30 day) <= date_added
         and tokens_total is not null
       group by usage_date'
    );

    while ($Qdaily->fetch()) {
      $dailyMap[$Qdaily->value('usage_date')] = (int)$Qdaily->value('total');
    }

    $dailyLabels = [];
    $dailyData = [];

    for ($i = 29; $i >= 0; $i--) {
      $timestamp = strtotime("-{$i} days");
      $dateKey = date('Y-m-d', $timestamp);
      $dailyLabels[] = date('d/m', $timestamp);
      $dailyData[] = $dailyMap[$dateKey] ?? 0;
    }

    $monthlyMap = [];
    // 🔧 UPDATED: Read from rag_statistics instead of gpt_usage
    $Qmonthly = $db->query(
      'select DATE_FORMAT(date_added, "%Y-%m") as month,
              sum(tokens_total) as total
       from :table_rag_statistics
       where date_added >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
         and tokens_total is not null
       group by month'
    );

    while ($Qmonthly->fetch()) {
      $monthlyMap[$Qmonthly->value('month')] = (int)$Qmonthly->value('total');
    }

    $monthlyLabels = [];
    $monthlyData = [];
    for ($i = 11; $i >= 0; $i--) {
      $timestamp = strtotime("-{$i} months");
      $monthKey = date('Y-m', $timestamp);
      $monthlyLabels[] = date('M y', $timestamp);
      $monthlyData[] = $monthlyMap[$monthKey] ?? 0;
    }

    $modelPrices = [
      'gpt-5-nano' => 0.0005,
      'gpt-5-mini' => 0.0025,
      'gpt-5' => 0.0125,
      'gpt-4.1-mini' => 0.0012,
      'gpt-4.1-nano' => 0.0008,
      'gpt-4o' => 0.0025,
      'gpt-3.5-turbo' => 0.0005,
    ];

    $modelColors = [
      'gpt-5-nano' => 'rgba(255, 99, 132, 0.5)',
      'gpt-5-mini' => 'rgba(54, 162, 235, 0.5)',
      'gpt-5' => 'rgba(255, 206, 86, 0.5)',
      'gpt-4.1-mini' => 'rgba(75, 192, 192, 0.5)',
      'gpt-4.1-nano' => 'rgba(153, 102, 255, 0.5)',
      'gpt-4o' => 'rgba(255, 159, 64, 0.5)',
      'gpt-3.5-turbo' => 'rgba(100, 255, 218, 0.5)',
    ];

    $rawCostData = [];
    // 🔧 UPDATED: Read from rag_statistics and use actual API cost instead of estimating
    $Qcost = $db->query(
      'select DATE_FORMAT(date_added, "%Y-%m") as month,
              model_used as model,
              sum(api_cost_usd) as total_cost
       from :table_rag_statistics
       where date_added >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
         and api_cost_usd is not null
       group by month, model
       order by month asc'
    );

    while ($Qcost->fetch()) {
      $model = $Qcost->value('model') ?: 'gpt-3.5-turbo';
      $month = $Qcost->value('month');
      $cost = (float)$Qcost->value('total_cost');
      $rawCostData[$model][$month] = $cost;
    }

    $costDatasets = [];
    $costLabelsKeys = [];
    foreach ($rawCostData as $model => $entries) {
      foreach ($entries as $month => $value) {
        $costLabelsKeys[$month] = true;
      }
    }
    if (empty($costLabelsKeys)) {
      foreach ($monthlyMap as $month => $_) {
        $costLabelsKeys[$month] = true;
      }
    }
    ksort($costLabelsKeys);
    $costLabels = array_map(static fn($month) => date('M y', strtotime("{$month}-01")), array_keys($costLabelsKeys));
    $costMonths = array_keys($costLabelsKeys);

    foreach ($rawCostData as $model => $entries) {
      $dataset = [];
      foreach ($costMonths as $month) {
        $dataset[] = round($entries[$month] ?? 0, 4);
      }

      $costDatasets[] = [
        'label' => $model,
        'data' => $dataset,
        'backgroundColor' => $modelColors[$model] ?? 'rgba(0,0,0,0.3)',
      ];
    }

    self::$cache = [
      'daily_total_tokens' => [
        'title' => $app->getDef('module_admin_dashboard_total_gpt_token_app_chart_link'),
        'chart' => [
          'type' => 'bar',
          'data' => [
            'labels' => $dailyLabels,
            'datasets' => [[
              'label' => 'GPT Tokens',
              'data' => $dailyData,
              'backgroundColor' => 'rgba(255,0,255,0.2)',
              'borderColor' => 'rgba(54, 162, 235, 1)',
              'borderWidth' => 1,
            ]],
          ],
          'options' => [
            'maintainAspectRatio' => true,
            'responsive' => true,
            'plugins' => [
              'legend' => ['display' => false],
            ],
            'scales' => [
              'y' => [
                'beginAtZero' => true,
              ],
              'x' => [
                'reverse' => true,
                'grid' => ['color' => 'rgba(0,0,0,0.05)'],
              ],
            ],
          ],
        ],
      ],
      'monthly_total_tokens' => [
        'title' => $app->getDef('module_admin_dashboard_sum_total_gpt_token_app_chart_link'),
        'chart' => [
          'type' => 'line',
          'data' => [
            'labels' => $monthlyLabels,
            'datasets' => [[
              'label' => 'GPT Tokens',
              'data' => $monthlyData,
              'backgroundColor' => 'rgba(255, 0, 255, 0.2)',
              'borderColor' => 'rgba(54, 162, 235, 1)',
              'borderWidth' => 1,
              'fill' => true,
            ]],
          ],
          'options' => [
            'maintainAspectRatio' => true,
            'responsive' => true,
            'plugins' => [
              'legend' => ['display' => false],
            ],
            'scales' => [
              'y' => [
                'beginAtZero' => true,
                'ticks' => ['stepSize' => 1],
                'grid' => [
                  'color' => 'rgba(0,0,0,0.05)',
                ],
              ],
              'x' => [
                'grid' => [
                  'color' => 'rgba(0,0,0,0.05)',
                ],
              ],
            ],
          ],
        ],
      ],
      'cost_estimation' => [
        'title' => $app->getDef('module_admin_dashboard_total_cost_estimation_app_chart_link'),
        'chart' => [
          'type' => 'bar',
          'data' => [
            'labels' => $costLabels,
            'datasets' => $costDatasets,
          ],
          'options' => [
            'maintainAspectRatio' => true,
            'responsive' => true,
            'scales' => [
              'x' => ['stacked' => true],
              'y' => ['stacked' => true, 'beginAtZero' => true],
            ],
            'plugins' => [
              'legend' => ['display' => true],
            ],
          ],
        ],
      ],
      'assets' => [
        'script' => CLICSHOPPING::link('Shop/ext/javascript/clicshopping/ClicShoppingAdmin/ChatGpt/token_charts.js'),
      ],
    ];

    return self::$cache;
  }
}

