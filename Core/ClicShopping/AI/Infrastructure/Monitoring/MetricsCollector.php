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
 * MetricsCollector Class
 *
 * Collecteur de métriques temps réel qui :
 * - Intercepte les événements système
 * - Collecte les métriques de performance
 * - Agrège les données en temps réel
 * - Notifie le MonitoringAgent
 * - Supporte des métriques personnalisées
 */
#[AllowDynamicProperties]
class MetricsCollector
{
  private SecurityLogger $logger;
  private ?MonitoringAgent $monitoringAgent = null;
  private bool $debug;

  // Buffer de métriques avant envoi
  private array $metricsBuffer = [];
  private int $bufferSize = 10;

  // Timers pour mesurer la durée
  private array $timers = [];

  // Compteurs
  private array $counters = [];

  // Gauges (valeurs instantanées)
  private array $gauges = [];

  // Histogrammes (distributions)
  private array $histograms = [];

  /**
   * Constructor
   *
   * @param MonitoringAgent|null $monitoringAgent Instance du MonitoringAgent
   */
  public function __construct(?MonitoringAgent $monitoringAgent = null)
  {
    $this->logger = new SecurityLogger();
    $this->monitoringAgent = $monitoringAgent;
    $this->debug = defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';

    if ($this->debug) {
      $this->logger->logSecurityEvent("MetricsCollector initialized", 'info');
    }
  }

  /**
   * 🎯 Démarre un timer pour mesurer la durée
   *
   * @param string $name Nom du timer
   * @param array $tags Tags optionnels
   */
  public function startTimer(string $name, array $tags = []): void
  {
    $this->timers[$name] = [
      'start' => microtime(true),
      'tags' => $tags,
    ];
  }

  /**
   * ⏱️ Arrête un timer et enregistre la métrique
   *
   * @param string $name Nom du timer
   * @return float|null Durée écoulée ou null si timer non trouvé
   */
  public function stopTimer(string $name): ?float
  {
    if (!isset($this->timers[$name])) {
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Timer not found: {$name}",
          'warning'
        );
      }
      return null;
    }

    $elapsed = microtime(true) - $this->timers[$name]['start'];
    $tags = $this->timers[$name]['tags'];

    // Enregistrer dans l'histogramme
    $this->recordHistogram($name, $elapsed, $tags);

    // Nettoyer le timer
    unset($this->timers[$name]);

    return $elapsed;
  }

  /**
   * ➕ Incrémente un compteur
   *
   * @param string $name Nom du compteur
   * @param int $value Valeur à ajouter (défaut: 1)
   * @param array $tags Tags optionnels
   */
  public function increment(string $name, int $value = 1, array $tags = []): void
  {
    $key = $this->buildKey($name, $tags);

    if (!isset($this->counters[$key])) {
      $this->counters[$key] = [
        'name' => $name,
        'value' => 0,
        'tags' => $tags,
      ];
    }

    $this->counters[$key]['value'] += $value;

    $this->checkBuffer();
  }

  /**
   * ➖ Décrémente un compteur
   *
   * @param string $name Nom du compteur
   * @param int $value Valeur à soustraire (défaut: 1)
   * @param array $tags Tags optionnels
   */
  public function decrement(string $name, int $value = 1, array $tags = []): void
  {
    $this->increment($name, -$value, $tags);
  }

  /**
   * 📏 Enregistre une gauge (valeur instantanée)
   *
   * @param string $name Nom de la gauge
   * @param float $value Valeur
   * @param array $tags Tags optionnels
   */
  public function gauge(string $name, float $value, array $tags = []): void
  {
    $key = $this->buildKey($name, $tags);

    $this->gauges[$key] = [
      'name' => $name,
      'value' => $value,
      'tags' => $tags,
      'timestamp' => time(),
    ];

    $this->checkBuffer();
  }

  /**
   * 📊 Enregistre une valeur dans un histogramme
   *
   * @param string $name Nom de l'histogramme
   * @param float $value Valeur
   * @param array $tags Tags optionnels
   */
  public function recordHistogram(string $name, float $value, array $tags = []): void
  {
    $key = $this->buildKey($name, $tags);

    if (!isset($this->histograms[$key])) {
      $this->histograms[$key] = [
        'name' => $name,
        'values' => [],
        'tags' => $tags,
      ];
    }

    $this->histograms[$key]['values'][] = $value;

    // Limiter la taille de l'histogramme
    if (count($this->histograms[$key]['values']) > 1000) {
      array_shift($this->histograms[$key]['values']);
    }

    $this->checkBuffer();
  }

  /**
   * 🎯 Enregistre un événement avec métriques
   *
   * @param string $eventType Type d'événement
   * @param array $metrics Métriques associées
   */
  public function recordEvent(string $eventType, array $metrics = []): void
  {
    $event = [
      'type' => $eventType,
      'timestamp' => microtime(true),
      'metrics' => $metrics,
    ];

    $this->metricsBuffer[] = $event;

    // Notifier le MonitoringAgent
    if ($this->monitoringAgent) {
      $this->monitoringAgent->recordEvent($eventType, $metrics);
    }

    $this->checkBuffer();
  }

  /**
   * 🚀 Mesure l'exécution d'une fonction
   *
   * @param string $name Nom de la métrique
   * @param callable $callback Fonction à mesurer
   * @param array $tags Tags optionnels
   * @return mixed Résultat de la fonction
   */
  public function measure(string $name, callable $callback, array $tags = [])
  {
    $this->startTimer($name, $tags);

    try {
      $result = $callback();
      $this->increment("{$name}.success", 1, $tags);
      return $result;
    } catch (\Exception $e) {
      $this->increment("{$name}.error", 1, $tags);
      throw $e;
    } finally {
      $elapsed = $this->stopTimer($name);

      if ($this->debug && $elapsed !== null) {
        $this->logger->logSecurityEvent(
          "Measured {$name}: " . round($elapsed * 1000, 2) . "ms",
          'info'
        );
      }
    }
  }

  /**
   * 📈 Obtient les statistiques d'un histogramme
   *
   * @param string $name Nom de l'histogramme
   * @param array $tags Tags optionnels
   * @return array|null Statistiques ou null
   */
  public function getHistogramStats(string $name, array $tags = []): ?array
  {
    $key = $this->buildKey($name, $tags);

    if (!isset($this->histograms[$key]) || empty($this->histograms[$key]['values'])) {
      return null;
    }

    $values = $this->histograms[$key]['values'];
    sort($values);

    $count = count($values);
    $sum = array_sum($values);
    $mean = $sum / $count;

    // Calcul de la médiane
    $middle = floor($count / 2);
    if ($count % 2 === 0) {
      $median = ($values[$middle - 1] + $values[$middle]) / 2;
    } else {
      $median = $values[$middle];
    }

    // Percentiles
    $p50 = $this->percentile($values, 50);
    $p75 = $this->percentile($values, 75);
    $p90 = $this->percentile($values, 90);
    $p95 = $this->percentile($values, 95);
    $p99 = $this->percentile($values, 99);

    // Écart-type
    $variance = 0;
    foreach ($values as $value) {
      $variance += pow($value - $mean, 2);
    }
    $stddev = sqrt($variance / $count);

    return [
      'count' => $count,
      'sum' => round($sum, 4),
      'min' => round(min($values), 4),
      'max' => round(max($values), 4),
      'mean' => round($mean, 4),
      'median' => round($median, 4),
      'stddev' => round($stddev, 4),
      'percentiles' => [
        'p50' => round($p50, 4),
        'p75' => round($p75, 4),
        'p90' => round($p90, 4),
        'p95' => round($p95, 4),
        'p99' => round($p99, 4),
      ],
    ];
  }

  /**
   * Calcule un percentile
   */
  private function percentile(array $sortedValues, int $percentile): float
  {
    $count = count($sortedValues);
    $index = ($percentile / 100) * ($count - 1);
    $lower = floor($index);
    $upper = ceil($index);

    if ($lower === $upper) {
      return $sortedValues[$lower];
    }

    $weight = $index - $lower;
    return $sortedValues[$lower] * (1 - $weight) + $sortedValues[$upper] * $weight;
  }

  /**
   * 📊 Obtient toutes les métriques collectées
   *
   * @return array Toutes les métriques
   */
  public function getAllMetrics(): array
  {
    return [
      'counters' => $this->counters,
      'gauges' => $this->gauges,
      'histograms' => $this->getHistogramsWithStats(),
      'active_timers' => count($this->timers),
    ];
  }

  /**
   * Obtient les histogrammes avec leurs statistiques
   */
  private function getHistogramsWithStats(): array
  {
    $result = [];

    foreach ($this->histograms as $key => $histogram) {
      $stats = $this->getHistogramStats($histogram['name'], $histogram['tags']);

      $result[$key] = [
        'name' => $histogram['name'],
        'tags' => $histogram['tags'],
        'stats' => $stats,
      ];
    }

    return $result;
  }

  /**
   * 🔑 Construit une clé unique pour une métrique avec tags
   */
  private function buildKey(string $name, array $tags): string
  {
    if (empty($tags)) {
      return $name;
    }

    ksort($tags);
    $tagString = http_build_query($tags, '', '&');

    return $name . '#' . md5($tagString);
  }

  /**
   * Vérifie si le buffer doit être vidé
   */
  private function checkBuffer(): void
  {
    if (count($this->metricsBuffer) >= $this->bufferSize) {
      $this->flush();
    }
  }

  /**
   * 🚀 Vide le buffer et envoie les métriques
   */
  public function flush(): void
  {
    if (empty($this->metricsBuffer)) {
      return;
    }

    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Flushing " . count($this->metricsBuffer) . " metrics",
        'info'
      );
    }

    // Traiter le buffer
    foreach ($this->metricsBuffer as $metric) {
      // Ici on pourrait envoyer à un système externe (StatsD, Prometheus, etc.)
      // Pour l'instant, on log juste
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Metric: " . json_encode($metric),
          'debug'
        );
      }
    }

    // Vider le buffer
    $this->metricsBuffer = [];
  }

  /**
   * 📈 Obtient le résumé des métriques
   */
  public function getSummary(): array
  {
    return [
      'total_counters' => count($this->counters),
      'total_gauges' => count($this->gauges),
      'total_histograms' => count($this->histograms),
      'active_timers' => count($this->timers),
      'buffer_size' => count($this->metricsBuffer),
      'top_counters' => $this->getTopCounters(5),
      'recent_gauges' => $this->getRecentGauges(5),
    ];
  }

  /**
   * Obtient les compteurs les plus élevés
   */
  private function getTopCounters(int $limit): array
  {
    $counters = $this->counters;

    usort($counters, fn($a, $b) => $b['value'] <=> $a['value']);

    return array_slice($counters, 0, $limit);
  }

  /**
   * Obtient les gauges les plus récentes
   */
  private function getRecentGauges(int $limit): array
  {
    $gauges = $this->gauges;

    usort($gauges, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

    return array_slice($gauges, 0, $limit);
  }

  /**
   * 🗑️ Réinitialise toutes les métriques
   */
  public function reset(): void
  {
    $this->counters = [];
    $this->gauges = [];
    $this->histograms = [];
    $this->timers = [];
    $this->metricsBuffer = [];

    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "All metrics reset",
        'info'
      );
    }
  }

  /**
   * 🎯 Enregistre une métrique personnalisée
   *
   * @param string $type Type de métrique (counter, gauge, histogram)
   * @param string $name Nom de la métrique
   * @param mixed $value Valeur
   * @param array $tags Tags optionnels
   */
  public function recordCustomMetric(string $type, string $name, $value, array $tags = []): void
  {
    switch ($type) {
      case 'counter':
        $this->increment($name, (int)$value, $tags);
        break;

      case 'gauge':
        $this->gauge($name, (float)$value, $tags);
        break;

      case 'histogram':
        $this->recordHistogram($name, (float)$value, $tags);
        break;

      default:
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Unknown metric type: {$type}",
            'warning'
          );
        }
    }
  }

  /**
   * 📊 Enregistre une métrique avec nom et valeur (TASK 4.4.2.3)
   * 
   * Méthode simplifiée pour enregistrer des métriques de latence et autres.
   * Détecte automatiquement le type de métrique basé sur le nom.
   *
   * @param string $name Nom de la métrique (ex: 'orchestrator_query_latency_ms')
   * @param float $value Valeur de la métrique
   * @param array $tags Tags optionnels pour filtrage (ex: ['status' => 'success', 'fast_lane' => 'true'])
   */
  public function recordMetric(string $name, float $value, array $tags = []): void
  {
    // Déterminer le type de métrique basé sur le nom
    if (str_contains($name, '_latency') || str_contains($name, '_time') || str_contains($name, '_duration')) {
      // Métriques de temps → histogramme pour statistiques
      $this->recordHistogram($name, $value, $tags);
    } elseif (str_contains($name, '_count') || str_contains($name, '_total')) {
      // Métriques de comptage → compteur
      $this->increment($name, (int)$value, $tags);
    } else {
      // Par défaut → gauge (valeur instantanée)
      $this->gauge($name, $value, $tags);
    }

    if ($this->debug) {
      $tagsStr = empty($tags) ? '' : ' [' . json_encode($tags) . ']';
      $this->logger->logSecurityEvent(
        "Metric recorded: {$name} = {$value}{$tagsStr}",
        'debug'
      );
    }
  }

  /**
   * 📊 Export des métriques au format Prometheus
   */
  public function exportPrometheus(): string
  {
    $output = [];

    // Counters
    foreach ($this->counters as $counter) {
      $name = str_replace('.', '_', $counter['name']);
      $tags = $this->formatPrometheusTags($counter['tags']);
      $output[] = "{$name}{$tags} {$counter['value']}";
    }

    // Gauges
    foreach ($this->gauges as $gauge) {
      $name = str_replace('.', '_', $gauge['name']);
      $tags = $this->formatPrometheusTags($gauge['tags']);
      $output[] = "{$name}{$tags} {$gauge['value']}";
    }

    // Histograms
    foreach ($this->histograms as $histogram) {
      $name = str_replace('.', '_', $histogram['name']);
      $stats = $this->getHistogramStats($histogram['name'], $histogram['tags']);

      if ($stats) {
        $tags = $this->formatPrometheusTags($histogram['tags']);
        $output[] = "{$name}_count{$tags} {$stats['count']}";
        $output[] = "{$name}_sum{$tags} {$stats['sum']}";
        $output[] = "{$name}_min{$tags} {$stats['min']}";
        $output[] = "{$name}_max{$tags} {$stats['max']}";
        $output[] = "{$name}_avg{$tags} {$stats['mean']}";
      }
    }

    return implode("\n", $output);
  }

  /**
   * Formate les tags au format Prometheus
   */
  private function formatPrometheusTags(array $tags): string
  {
    if (empty($tags)) {
      return '';
    }

    $formatted = [];
    foreach ($tags as $key => $value) {
      $formatted[] = "{$key}=\"{$value}\"";
    }

    return '{' . implode(',', $formatted) . '}';
  }

  /**
   * 📊 Export des métriques au format StatsD
   */
  public function exportStatsD(): array
  {
    $output = [];

    // Counters
    foreach ($this->counters as $counter) {
      $output[] = "{$counter['name']}:{$counter['value']}|c";
    }

    // Gauges
    foreach ($this->gauges as $gauge) {
      $output[] = "{$gauge['name']}:{$gauge['value']}|g";
    }

    // Histograms (as timing)
    foreach ($this->histograms as $histogram) {
      $stats = $this->getHistogramStats($histogram['name'], $histogram['tags']);

      if ($stats) {
        $output[] = "{$histogram['name']}:{$stats['mean']}|ms";
      }
    }

    return $output;
  }

  /**
   * Destructeur - Vider le buffer
   */
  public function __destruct()
  {
    $this->flush();
  }


  ///*************************
  /// not used
  /// ************************

  /**
   * 🔄 Met à jour le MonitoringAgent
   */
  public function setMonitoringAgent(MonitoringAgent $monitoringAgent): void
  {
    $this->monitoringAgent = $monitoringAgent;
  }

  /**
   * 📊 Obtient la valeur d'un compteur
   */
  public function getCounterValue(string $name, array $tags = []): ?int
  {
    $key = $this->buildKey($name, $tags);
    return $this->counters[$key]['value'] ?? null;
  }

  /**
   * 📊 Obtient la valeur d'une gauge
   */
  public function getGaugeValue(string $name, array $tags = []): ?float
  {
    $key = $this->buildKey($name, $tags);
    return $this->gauges[$key]['value'] ?? null;
  }

  /**
   * ⏱️ Vérifie si un timer est actif
   */
  public function hasActiveTimer(string $name): bool
  {
    return isset($this->timers[$name]);
  }

  /**
   * 🧹 Nettoie les vieilles métriques
   *
   * @param int $maxAge Age maximum en secondes
   */
  public function cleanOldMetrics(int $maxAge = 3600): void
  {
    $cutoff = time() - $maxAge;

    // Nettoyer les gauges
    foreach ($this->gauges as $key => $gauge) {
      if ($gauge['timestamp'] < $cutoff) {
        unset($this->gauges[$key]);
      }
    }

    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Cleaned old metrics (max age: {$maxAge}s)",
        'info'
      );
    }
  }

}