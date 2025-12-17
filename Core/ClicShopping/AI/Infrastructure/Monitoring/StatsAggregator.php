<?php
/**
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 */

namespace ClicShopping\AI\Infrastructure\Monitoring;

use AllowDynamicProperties;
use ClicShopping\AI\Security\SecurityLogger;

/**
 * StatsAggregator Class
 *
 * Agrégateur de statistiques qui :
 * - Combine les métriques de plusieurs sources
 * - Calcule les tendances et les corrélations
 * - Génère des rapports synthétiques
 * - Détecte les anomalies
 * - Exporte vers différents formats
 */
#[AllowDynamicProperties]
class StatsAggregator
{
  private SecurityLogger $logger;
  private bool $debug;

  // Sources de données
  private array $dataSources = [];

  // Cache des statistiques agrégées
  private array $aggregatedStats = [];
  private int $lastAggregationTime = 0;

  // Configuration
  private int $cacheLifetime = 300; // 5 minutes

  /**
   * Constructor
   */
  public function __construct()
  {
    $this->logger = new SecurityLogger();
    $this->debug = defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';

    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "StatsAggregator initialized",
        'info'
      );
    }
  }

  /**
   * 📊 Ajoute une source de données
   *
   * @param string $sourceName Nom de la source
   * @param callable $dataFetcher Fonction pour récupérer les données
   */
  public function addDataSource(string $sourceName, callable $dataFetcher): void
  {
    $this->dataSources[$sourceName] = [
      'name' => $sourceName,
      'fetcher' => $dataFetcher,
      'last_fetch' => null,
      'last_data' => null,
    ];

    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Data source added: {$sourceName}",
        'info'
      );
    }
  }

  /**
   * 🔄 Agrège toutes les statistiques
   *
   * @return array Statistiques agrégées
   */
  public function aggregate(): array
  {
    // Vérifier le cache
    if ($this->isCacheValid()) {
      return $this->aggregatedStats;
    }

    $aggregated = [
      'timestamp' => time(),
      'sources' => [],
      'system_summary' => [],
      'performance_metrics' => [],
      'quality_metrics' => [],
      'trends' => [],
      'anomalies' => [],
    ];

    // Récupérer les données de chaque source
    foreach ($this->dataSources as $sourceName => $source) {
      try {
        $data = $source['fetcher']();

        $this->dataSources[$sourceName]['last_fetch'] = time();
        $this->dataSources[$sourceName]['last_data'] = $data;

        $aggregated['sources'][$sourceName] = $data;

      } catch (\Exception $e) {
        $this->logger->logSecurityEvent(
          "Error fetching data from source {$sourceName}: " . $e->getMessage(),
          'error'
        );

        $aggregated['sources'][$sourceName] = [
          'error' => $e->getMessage(),
        ];
      }
    }

    // Calculer les synthèses
    $aggregated['system_summary'] = $this->calculateSystemSummary($aggregated['sources']);
    $aggregated['performance_metrics'] = $this->calculatePerformanceMetrics($aggregated['sources']);
    $aggregated['quality_metrics'] = $this->calculateQualityMetrics($aggregated['sources']);
    $aggregated['trends'] = $this->detectTrends($aggregated['sources']);
    $aggregated['anomalies'] = $this->detectAnomalies($aggregated['sources']);

    // Mettre en cache
    $this->aggregatedStats = $aggregated;
    $this->lastAggregationTime = time();

    return $aggregated;
  }

  /**
   * Calcule le résumé du système
   */
  private function calculateSystemSummary(array $sources): array
  {
    $summary = [
      'total_uptime' => 0,
      'total_requests' => 0,
      'total_errors' => 0,
      'average_error_rate' => 0,
      'total_api_calls' => 0,
      'total_api_cost' => 0,
      'components_status' => [],
    ];

    $validSources = 0;

    foreach ($sources as $sourceName => $source) {
      if (isset($source['error'])) {
        continue;
      }

      $validSources++;

      // Métriques système
      if (isset($source['system'])) {
        $summary['total_uptime'] += $source['system']['uptime_seconds'] ?? 0;
        $summary['total_requests'] += $source['system']['total_requests'] ?? 0;
        $summary['total_errors'] += $source['system']['total_errors'] ?? 0;
        $summary['total_api_calls'] += $source['system']['total_api_calls'] ?? 0;
        $summary['total_api_cost'] += $source['system']['total_api_cost'] ?? 0;
      }

      // Statut des composants
      if (isset($source['components'])) {
        foreach ($source['components'] as $comp => $data) {
          if (!isset($summary['components_status'][$comp])) {
            $summary['components_status'][$comp] = [
              'total_calls' => 0,
              'success_count' => 0,
              'failure_count' => 0,
            ];
          }

          $summary['components_status'][$comp]['total_calls'] += $data['total_calls'] ?? 0;
          $summary['components_status'][$comp]['success_count'] += $data['successful_calls'] ?? 0;
          $summary['components_status'][$comp]['failure_count'] += $data['failed_calls'] ?? 0;
        }
      }
    }

    if ($validSources > 0) {
      $summary['average_error_rate'] =
        $summary['total_requests'] > 0
          ? round($summary['total_errors'] / $summary['total_requests'] * 100, 2)
          : 0;
    }

    return $summary;
  }

  /**
   * Calcule les métriques de performance
   */
  private function calculatePerformanceMetrics(array $sources): array
  {
    $metrics = [
      'total_response_time' => 0,
      'avg_response_time' => 0,
      'p50_response_time' => 0,
      'p95_response_time' => 0,
      'p99_response_time' => 0,
      'slowest_component' => null,
      'fastest_component' => null,
      'components_performance' => [],
    ];

    $responseTimes = [];
    $slowestTime = 0;
    $fastestTime = PHP_FLOAT_MAX;

    foreach ($sources as $source) {
      if (isset($source['error']) || !isset($source['components'])) {
        continue;
      }

      foreach ($source['components'] as $compName => $comp) {
        $avgTime = $comp['avg_execution_time'] ?? 0;

        $metrics['components_performance'][$compName] = [
          'avg_time' => $avgTime,
          'total_calls' => $comp['total_calls'] ?? 0,
        ];

        if ($avgTime > 0) {
          $responseTimes[] = $avgTime;

          if ($avgTime > $slowestTime) {
            $slowestTime = $avgTime;
            $metrics['slowest_component'] = $compName;
          }

          if ($avgTime < $fastestTime) {
            $fastestTime = $avgTime;
            $metrics['fastest_component'] = $compName;
          }
        }
      }
    }

    if (!empty($responseTimes)) {
      sort($responseTimes);
      $metrics['avg_response_time'] = round(array_sum($responseTimes) / count($responseTimes), 3);
      $metrics['p50_response_time'] = round($responseTimes[floor(count($responseTimes) * 0.5)], 3);
      $metrics['p95_response_time'] = round($responseTimes[floor(count($responseTimes) * 0.95)], 3);
      $metrics['p99_response_time'] = round($responseTimes[floor(count($responseTimes) * 0.99)], 3);
    }

    return $metrics;
  }

  /**
   * Calcule les métriques de qualité
   */
  private function calculateQualityMetrics(array $sources): array
  {
    $metrics = [
      'total_quality_score' => 0,
      'avg_quality_score' => 0,
      'success_rate' => 0,
      'reliability' => [],
      'component_quality' => [],
    ];

    $qualityScores = [];
    $totalCalls = 0;
    $totalSuccess = 0;

    foreach ($sources as $source) {
      if (isset($source['error']) || !isset($source['components'])) {
        continue;
      }

      foreach ($source['components'] as $compName => $comp) {
        $calls = $comp['total_calls'] ?? 0;
        $success = $comp['successful_calls'] ?? 0;

        $totalCalls += $calls;
        $totalSuccess += $success;

        if ($calls > 0) {
          $successRate = ($success / $calls) * 100;
          $qualityScores[] = $successRate;

          $metrics['component_quality'][$compName] = [
            'success_rate' => round($successRate, 2),
            'reliability_score' => round($successRate * 0.7 + (1 - ($comp['failed_calls'] ?? 0) / $calls) * 30, 2),
          ];
        }
      }
    }

    if (!empty($qualityScores)) {
      $metrics['avg_quality_score'] = round(array_sum($qualityScores) / count($qualityScores), 2);
    }

    if ($totalCalls > 0) {
      $metrics['success_rate'] = round(($totalSuccess / $totalCalls) * 100, 2);
    }

    return $metrics;
  }

  /**
   * Détecte les tendances
   */
  private function detectTrends(array $sources): array
  {
    $trends = [
      'error_rate_trend' => null,
      'performance_trend' => null,
      'quality_trend' => null,
      'api_cost_trend' => null,
    ];

    // À implémenter avec des données historiques
    // Pour l'instant, retourner une structure vide

    return $trends;
  }

  /**
   * Détecte les anomalies
   */
  private function detectAnomalies(array $sources): array
  {
    $anomalies = [];

    foreach ($sources as $sourceName => $source) {
      if (isset($source['error']) || !isset($source['components'])) {
        continue;
      }

      // Vérifier les taux d'erreur anormalement élevés
      if (isset($source['system']['error_rate'])) {
        $errorRate = $source['system']['error_rate'];

        if ($errorRate > 0.1) {
          $anomalies[] = [
            'type' => 'high_error_rate',
            'source' => $sourceName,
            'severity' => $errorRate > 0.2 ? 'critical' : 'warning',
            'value' => round($errorRate * 100, 2) . '%',
            'description' => "Abnormally high error rate detected",
          ];
        }
      }

      // Vérifier les temps de réponse anormalement lents
      if (isset($source['system']['avg_response_time'])) {
        $avgTime = $source['system']['avg_response_time'];

        if ($avgTime > 5.0) {
          $anomalies[] = [
            'type' => 'slow_response',
            'source' => $sourceName,
            'severity' => $avgTime > 10.0 ? 'critical' : 'warning',
            'value' => round($avgTime, 2) . 's',
            'description' => "Abnormally slow response time detected",
          ];
        }
      }

      // Vérifier les composants défaillants
      if (isset($source['components'])) {
        foreach ($source['components'] as $compName => $comp) {
          $calls = $comp['total_calls'] ?? 0;
          $failures = $comp['failed_calls'] ?? 0;

          if ($calls > 10 && $failures / $calls > 0.2) {
            $anomalies[] = [
              'type' => 'component_failures',
              'source' => $sourceName,
              'component' => $compName,
              'severity' => 'error',
              'value' => round(($failures / $calls) * 100, 2) . '%',
              'description' => "Component has abnormally high failure rate",
            ];
          }
        }
      }
    }

    return $anomalies;
  }

  /**
   * 📈 Obtient un rapport complet
   */
  public function getFullReport(): array
  {
    $aggregated = $this->aggregate();

    return [
      'timestamp' => $aggregated['timestamp'],
      'generated_at' => date('Y-m-d H:i:s', $aggregated['timestamp']),
      'system' => $aggregated['system_summary'],
      'performance' => $aggregated['performance_metrics'],
      'quality' => $aggregated['quality_metrics'],
      'anomalies' => $aggregated['anomalies'],
      'anomaly_count' => count($aggregated['anomalies']),
      'critical_count' => count(array_filter($aggregated['anomalies'], fn($a) => $a['severity'] === 'critical')),
    ];
  }

  /**
   * 🎯 Obtient un résumé exécutif
   */
  public function getExecutiveSummary(): array
  {
    $report = $this->getFullReport();
    $system = $report['system'];
    $performance = $report['performance'];
    $quality = $report['quality'];

    return [
      'status' => $this->calculateOverallStatus($report),
      'key_metrics' => [
        'requests' => $system['total_requests'],
        'errors' => $system['total_errors'],
        'error_rate' => $system['average_error_rate'] . '%',
        'avg_response_time' => $performance['avg_response_time'] . 's',
        'success_rate' => $quality['success_rate'] . '%',
        'api_cost' => '$' . round($system['total_api_cost'], 2),
      ],
      'critical_issues' => count(array_filter($report['anomalies'], fn($a) => $a['severity'] === 'critical')),
      'warnings' => count(array_filter($report['anomalies'], fn($a) => $a['severity'] === 'warning')),
      'recommendations' => $this->generateRecommendations($report),
    ];
  }

  /**
   * Calcule le statut global
   */
  private function calculateOverallStatus(array $report): string
  {
    $criticalCount = $report['critical_count'] ?? 0;
    $warningCount = count(array_filter($report['anomalies'] ?? [], fn($a) => $a['severity'] === 'warning'));

    if ($criticalCount > 0) {
      return 'critical';
    } elseif ($warningCount > 2) {
      return 'degraded';
    } elseif ($warningCount > 0) {
      return 'warning';
    } else {
      return 'healthy';
    }
  }

  /**
   * Génère des recommandations
   */
  private function generateRecommendations(array $report): array
  {
    $recommendations = [];
    $system = $report['system'];
    $performance = $report['performance'];
    $quality = $report['quality'];

    // Recommandation: Taux d'erreur
    if ($system['average_error_rate'] > 5) {
      $recommendations[] = [
        'priority' => 'high',
        'message' => 'High error rate detected. Review error logs and implement fixes.',
      ];
    }

    // Recommandation: Performance
    if ($performance['avg_response_time'] > 3) {
      $recommendations[] = [
        'priority' => 'medium',
        'message' => 'Slow response times detected. Consider optimization or caching.',
      ];
    }

    // Recommandation: Qualité
    if ($quality['success_rate'] < 95) {
      $recommendations[] = [
        'priority' => 'medium',
        'message' => 'Success rate below 95%. Investigate reliability issues.',
      ];
    }

    // Recommandation: Coûts API
    if ($system['total_api_cost'] > 100) {
      $recommendations[] = [
        'priority' => 'low',
        'message' => 'High API costs. Consider optimization strategies.',
      ];
    }

    return $recommendations;
  }

  /**
   * Exporte au format JSON
   */
  public function exportJSON(): string
  {
    return json_encode(
      $this->getFullReport(),
      JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    );
  }

  /**
   * Exporte au format CSV
   */
  public function exportCSV(): string
  {
    $report = $this->getFullReport();
    $system = $report['system'];
    $performance = $report['performance'];
    $quality = $report['quality'];

    $lines = [
      "System Statistics Report",
      date('Y-m-d H:i:s'),
      "",
      "Metric,Value",
      "Total Requests," . $system['total_requests'],
      "Total Errors," . $system['total_errors'],
      "Error Rate," . $system['average_error_rate'] . "%",
      "Average Response Time," . $performance['avg_response_time'] . "s",
      "P95 Response Time," . $performance['p95_response_time'] . "s",
      "P99 Response Time," . $performance['p99_response_time'] . "s",
      "Success Rate," . $quality['success_rate'] . "%",
      "Total API Cost,\$" . round($system['total_api_cost'], 2),
      "API Calls," . $system['total_api_calls'],
    ];

    return implode("\n", $lines);
  }

  /**
   * Vérifie si le cache est valide
   */
  private function isCacheValid(): bool
  {
    return !empty($this->aggregatedStats) &&
      (time() - $this->lastAggregationTime) < $this->cacheLifetime;
  }

  //*******************************
  //Not used
  //*******************************

  /**
   * Invalide le cache
   */
  public function invalidateCache(): void
  {
    $this->aggregatedStats = [];
    $this->lastAggregationTime = 0;
  }

  /**
   * Configuration
   */
  public function setCacheLifetime(int $seconds): void
  {
    $this->cacheLifetime = max(60, $seconds);
  }

  /**
   * Obtient les données sources
   */
  public function getSourcesStatus(): array
  {
    $status = [];

    foreach ($this->dataSources as $sourceName => $source) {
      $status[$sourceName] = [
        'last_fetch' => $source['last_fetch'] ? date('Y-m-d H:i:s', $source['last_fetch']) : 'Never',
        'has_data' => $source['last_data'] !== null,
        'age_seconds' => $source['last_fetch'] ? time() - $source['last_fetch'] : null,
      ];
    }

    return $status;
  }

  /**
   * Obtient les statistiques de source
   */
  public function getSourceStats(string $sourceName): ?array
  {
    if (!isset($this->dataSources[$sourceName])) {
      return null;
    }

    return $this->dataSources[$sourceName]['last_data'];
  }

  /**
   * Compare deux périodes
   */
  public function comparePeriods(array $period1, array $period2): array
  {
    $comparison = [
      'period1' => $period1,
      'period2' => $period2,
      'changes' => [],
    ];

    // Comparer les métriques clés
    $metrics = ['total_requests', 'total_errors', 'total_api_cost', 'avg_response_time'];

    foreach ($metrics as $metric) {
      $val1 = $period1[$metric] ?? 0;
      $val2 = $period2[$metric] ?? 0;

      if ($val1 != 0) {
        $change = (($val2 - $val1) / $val1) * 100;
      } else {
        $change = 0;
      }

      $comparison['changes'][$metric] = [
        'before' => $val1,
        'after' => $val2,
        'change_percent' => round($change, 2),
        'trend' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'stable'),
      ];
    }

    return $comparison;
  }
}