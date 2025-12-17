<?php
/**
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 */

namespace ClicShopping\AI\Infrastructure\Monitoring;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use ClicShopping\AI\Infrastructure\Monitoring\MonitoringAgent;
use ClicShopping\AI\Infrastructure\Monitoring\MetricsCollector;
use ClicShopping\AI\Infrastructure\Monitoring\AlertManager;
use ClicShopping\AI\Infrastructure\Monitoring\StatsAggregator;
/**
 * DocumentationGenerator Class
 *
 * Générateur de documentation automatique qui :
 * - Analyse les classes via Reflection
 * - Génère de la documentation Markdown
 * - Crée des API references
 * - Produit des diagrammes ASCII
 * - Exporte en HTML et PDF
 */
class DocumentationGenerator
{
  private string $projectName = 'ClicShopping AI';
  private string $projectVersion = '1.0.0';
  private array $analyzedClasses = [];
  private mixed $monitoring;
  private mixed $collector;

  private mixed $alertManager;

  private mixed $aggregator;
    /**
   * Constructor
   */
  public function __construct(string $projectName = 'ClicShopping AI', string $version = '1.0.0')
  {
    $this->projectName = $projectName;
    $this->projectVersion = $version;
    $this->monitoring = new MonitoringAgent();
    $this->collector = new MetricsCollector();
    $this->alertManager = new AlertManager();
    $this->aggregator = new StatsAggregator();
  }

  /**
   * Analyse une classe et génère sa documentation
   */
  public function analyzeClass(string $className): array
  {
    try {
      $reflection = new ReflectionClass($className);

      $analysis = [
        'name' => $reflection->getName(),
        'short_name' => $reflection->getShortName(),
        'namespace' => $reflection->getNamespaceName(),
        'description' => $this->extractDocComment($reflection->getDocComment()),
        'methods' => [],
        'properties' => [],
        'constants' => [],
      ];

      // Analyser les constantes
      foreach ($reflection->getConstants() as $name => $value) {
        $analysis['constants'][] = [
          'name' => $name,
          'value' => $value,
        ];
      }

      // Analyser les propriétés
      foreach ($reflection->getProperties() as $property) {
        $analysis['properties'][] = $this->analyzeProperty($property);
      }

      // Analyser les méthodes
      foreach ($reflection->getMethods() as $method) {
        $analysis['methods'][] = $this->analyzeMethod($method);
      }

      $this->analyzedClasses[$className] = $analysis;

      return $analysis;

    } catch (\Exception $e) {
      return [
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Analyse une propriété
   */
  private function analyzeProperty(ReflectionProperty $property): array
  {
    return [
      'name' => $property->getName(),
      'type' => $property->getType() ? (string)$property->getType() : 'mixed',
      'default' => null,
      'visibility' => $this->getVisibility($property),
      'static' => $property->isStatic(),
      'description' => $this->extractDocComment($property->getDocComment()),
    ];
  }

  /**
   * Analyse une méthode
   */
  private function analyzeMethod(ReflectionMethod $method): array
  {
    $params = [];
    foreach ($method->getParameters() as $param) {
      $params[] = [
        'name' => $param->getName(),
        'type' => $param->getType() ? (string)$param->getType() : 'mixed',
        'default' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
        'required' => !$param->isOptional(),
      ];
    }

    return [
      'name' => $method->getName(),
      'description' => $this->extractDocComment($method->getDocComment()),
      'visibility' => $this->getVisibility($method),
      'static' => $method->isStatic(),
      'return_type' => $method->getReturnType() ? (string)$method->getReturnType() : 'mixed',
      'parameters' => $params,
    ];
  }

  /**
   * Extrait le contenu du DocComment
   */
  private function extractDocComment(?string $docComment): string
  {
    if (!$docComment) {
      return '';
    }

    // Nettoyer le DocComment
    $lines = explode("\n", $docComment);
    $content = [];

    foreach ($lines as $line) {
      $line = trim($line);
      $line = preg_replace('/^\/\*+/', '', $line);
      $line = preg_replace('/\*+\/$/', '', $line);
      $line = preg_replace('/^\*\s?/', '', $line);

      if (!empty($line)) {
        $content[] = $line;
      }
    }

    return implode("\n", $content);
  }

  /**
   * Obtient la visibilité d'une propriété/méthode
   */
  private function getVisibility($member): string
  {
    if ($member->isPublic()) return 'public';
    if ($member->isProtected()) return 'protected';
    if ($member->isPrivate()) return 'private';
    return 'unknown';
  }

  /**
   * Génère la documentation en Markdown
   */
  public function generateMarkdown(): string
  {
    $md = [];

    // Header
    $md[] = "# {$this->projectName}";
    $md[] = "**Version:** {$this->projectVersion}";
    $md[] = "";
    $md[] = "## Overview";
    $md[] = "Documentation auto-générée pour les composants du système de monitoring.";
    $md[] = "";

    // Table of contents
    $md[] = "## Table of Contents";
    $md[] = "";
    foreach (array_keys($this->analyzedClasses) as $className) {
      $shortName = (new ReflectionClass($className))->getShortName();
      $anchor = strtolower(str_replace('\\', '-', $className));
      $md[] = "- [{$shortName}](#{$anchor})";
    }
    $md[] = "";

    // Classes
    foreach ($this->analyzedClasses as $className => $analysis) {
      $md = array_merge($md, $this->generateClassMarkdown($analysis));
    }

    return implode("\n", $md);
  }

  /**
   * Génère la documentation Markdown d'une classe
   */
  private function generateClassMarkdown(array $analysis): array
  {
    $md = [];

    $anchor = strtolower(str_replace('\\', '-', $analysis['name']));
    $md[] = "## {$analysis['short_name']}";
    $md[] = "";

    if (!empty($analysis['description'])) {
      $md[] = $analysis['description'];
      $md[] = "";
    }

    $md[] = "**Namespace:** `{$analysis['namespace']}`";
    $md[] = "";

    // Propriétés
    if (!empty($analysis['properties'])) {
      $md[] = "### Properties";
      $md[] = "";
      $md[] = "| Name | Type | Visibility | Description |";
      $md[] = "|------|------|------------|-------------|";

      foreach ($analysis['properties'] as $prop) {
        $static = $prop['static'] ? ' (static)' : '';
        $md[] = "| `{$prop['name']}{$static}` | {$prop['type']} | {$prop['visibility']} | {$prop['description']} |";
      }

      $md[] = "";
    }

    // Méthodes
    if (!empty($analysis['methods'])) {
      $md[] = "### Methods";
      $md[] = "";

      foreach ($analysis['methods'] as $method) {
        $signature = $this->generateMethodSignature($method);
        $md[] = "#### `{$signature}`";
        $md[] = "";

        if (!empty($method['description'])) {
          $md[] = $method['description'];
          $md[] = "";
        }

        // Paramètres
        if (!empty($method['parameters'])) {
          $md[] = "**Parameters:**";
          foreach ($method['parameters'] as $param) {
            $required = $param['required'] ? '(required)' : '(optional)';
            $md[] = "- `{$param['name']}` ({$param['type']}) {$required}";
            if ($param['default'] !== null) {
              $md[] = "  - Default: `{$param['default']}`";
            }
          }
          $md[] = "";
        }

        // Return type
        $md[] = "**Returns:** `{$method['return_type']}`";
        $md[] = "";
      }
    }

    return $md;
  }

  /**
   * Génère la signature d'une méthode
   */
  private function generateMethodSignature(array $method): string
  {
    $params = [];
    foreach ($method['parameters'] as $param) {
      $required = $param['required'] ? '' : '?';
      $params[] = "{$required}{$param['type']} \${$param['name']}";
    }

    $paramStr = implode(', ', $params);

    return "{$method['name']}({$paramStr}): {$method['return_type']}";
  }

  /**
   * Génère une référence API en HTML
   */
  public function generateHTML(): string
  {
    $markdown = $this->generateMarkdown();
    $htmlContent = $this->markdownToHTML($markdown);

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$this->projectName} - API Reference</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            line-height: 1.6;
            color: #333;
            background: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 20px;
            border-radius: 8px;
            margin-bottom: 40px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        header h1 {
            font-size: 32px;
            margin-bottom: 10px;
        }
        header .version {
            opacity: 0.9;
            font-size: 14px;
        }
        main {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h2 {
            color: #667eea;
            margin-top: 40px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #667eea;
        }
        h3 {
            color: #764ba2;
            margin-top: 30px;
            margin-bottom: 15px;
        }
        h4 {
            color: #555;
            margin-top: 20px;
            margin-bottom: 10px;
            font-size: 16px;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        pre {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            margin: 15px 0;
        }
        pre code {
            background: none;
            padding: 0;
            color: inherit;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #f9f9f9;
            font-weight: 600;
            color: #333;
        }
        tr:hover {
            background: #f9f9f9;
        }
        ul, ol {
            margin: 15px 0 15px 30px;
        }
        li {
            margin-bottom: 8px;
        }
        .toc {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 30px;
        }
        .toc h3 {
            margin-top: 0;
        }
        .toc ul {
            margin-bottom: 0;
        }
        .toc a {
            color: #667eea;
            text-decoration: none;
        }
        .toc a:hover {
            text-decoration: underline;
        }
        footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 14px;
            margin-top: 40px;
        }
    </style>
</head>
<body>
    <header>
        <h1>{$this->projectName}</h1>
        <div class="version">Version {$this->projectVersion}</div>
    </header>

    <div class="container">
        <main>
            {$htmlContent}
        </main>

        <footer>
            <p>Generated automatically with DocumentationGenerator</p>
            <p>© 2024 ClicShopping</p>
        </footer>
    </div>
</body>
</html>
HTML;

    return $html;
  }

  /**
   * Convertit Markdown en HTML simple
   */
  private function markdownToHTML(string $markdown): string
  {
    $html = htmlspecialchars($markdown);

    // Headers
    $html = preg_replace('/^## (.*?)$/m', '<h2>$1</h2>', $html);
    $html = preg_replace('/^### (.*?)$/m', '<h3>$1</h3>', $html);
    $html = preg_replace('/^#### (.*?)$/m', '<h4>$1</h4>', $html);

    // Bold
    $html = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $html);

    // Italic
    $html = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $html);

    // Code inline
    $html = preg_replace('/`(.*?)`/', '<code>$1</code>', $html);

    // Lists
    $html = preg_replace('/^\- (.*?)$/m', '<li>$1</li>', $html);
    $html = preg_replace('/(<li>.*?<\/li>)/s', '<ul>$1</ul>', $html);

    // Tables
    $html = preg_replace('/\|(.*?)\|/m', function($matches) {
      $cells = array_map('trim', explode('|', $matches[1]));
      return '<tr><td>' . implode('</td><td>', $cells) . '</td></tr>';
    }, $html);

    // Paragraphs
    $html = preg_replace('/\n\n+/', '</p><p>', $html);
    $html = '<p>' . $html . '</p>';

    // Line breaks
    $html = str_replace("\n", "<br>\n", $html);

    return $html;
  }

  /**
   * Génère un diagramme d'architecture ASCII
   */
  public function generateArchitectureDiagram(): string
  {
    return <<<ASCII
╔════════════════════════════════════════════════════════════════════╗
║                      ClicShopping AI - Architecture                ║
╚════════════════════════════════════════════════════════════════════╝

┌─────────────────────────────────────────────────────────────────┐
│                     OrchestratorAgent                           │
│            (Entry point for all queries)                        │
└────────────────────┬────────────────────────────────────────────┘
                     │
        ┌────────────┼────────────┬──────────────┐
        │            │            │              │
        ▼            ▼            ▼              ▼
    ┌────────┐  ┌────────┐  ┌──────────┐  ┌──────────┐
    │Planner │  │Executor│  │Reasoning │  │Correction│
    └────────┘  └────────┘  └──────────┘  └──────────┘
        │            │            │              │
        └────────────┼────────────┴──────────────┘
                     │
        ┌────────────┴────────────┐
        │                         │
        ▼                         ▼
    ┌──────────────┐      ┌─────────────┐
    │   RAG Stack  │      │  Analytics  │
    │              │      │   Engine    │
    │ - Semantic   │      │             │
    │ - Vector DB  │      │ - SQL Query │
    │ - Cache      │      │ - Analytics │
    └──────────────┘      └─────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                  Monitoring Layer (Phase 4)                      │
├─────────────────────────────────────────────────────────────────┤
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐          │
│  │  Collector   │→ │  Monitoring  │→ │  Aggregator  │          │
│  │  (Metrics)   │  │  (Health)    │  │  (Reports)   │          │
│  └──────────────┘  └──────────────┘  └──────────────┘          │
│                           │                                      │
│                           ▼                                      │
│                  ┌──────────────────┐                           │
│                  │  AlertManager    │                           │
│                  │  (Rules & Events)│                           │
│                  └──────────────────┘                           │
└─────────────────────────────────────────────────────────────────┘

Data Flow:
  Events → Collector → Monitoring → Alerts
           │              │
           └──────→ Aggregator → Reports
ASCII;
  }

  /**
   * Génère un guide des meilleurs pratiques
   */
  public function generateBestPractices(): string
  {
    return <<<MARKDOWN
# Best Practices Guide

## 1. Monitoring

### Enregistrer des événements importants
- Toujours enregistrer avant/après chaque opération critique
- Inclure les tags pour le groupage (component, operation_type, etc.)
- Mesurer les temps d'exécution avec startTimer/stopTimer

### Configuration des alertes
- Commencer avec peu de règles, ajouter progressivement
- Éviter les faux positifs en ajustant les seuils
- Configurer l'escalation pour les alertes critiques
- Tester les canaux de notification (email, Slack, etc.)

## 2. Performance

### Optimisation de la collecte
- Utiliser le buffering (bufferSize > 1)
- Vider le cache régulièrement (flush())
- Nettoyer les vieilles métriques (cleanOldMetrics())
- Désactiver le debug en production

### Aggregation
- Augmenter cache lifetime en production (300-600s)
- Limiter l'historique (maxHistorySize)
- Paralléliser les requêtes de données

## 3. Analyse des données

### Interprétation des rapports
- Vérifier les tendances, pas juste les valeurs actuelles
- Corréler les métriques (erreurs + latence)
- Investiguer les anomalies immédiatement
- Documenter les incidents et causes

### Escalation
- Automatiser l'escalation pour les critiques
- Notifier les bonnes personnes au bon moment
- Mettre à jour les runbooks avec les solutions

## 4. Sécurité

### Données sensibles
- Ne pas logger les données utilisateur
- Chiffrer les métriques sensibles
- Limiter l'accès aux rapports
- Audit trail pour les modifications

### API Security
- Valider tous les paramètres des alertes
- Limiter le rate des requests API
- Monitorer les usages d'API suspects
- Rotater les clés régulièrement

## 5. Troubleshooting

### Debugging
- Activer le debug mode pour les développements
- Utiliser les logs détaillés
- Exporter les métriques pour analyse offline
- Comparer les périodes (before/after)

### Problèmes courants

**Mémoire haute**
- Réduire maxHistorySize
- Vider le buffer plus souvent
- Nettoyer les vieilles métriques

**Alertes manquées**
- Vérifier que les règles sont activées
- Tester la condition manuellement
- Vérifier les canaux de notification

**Performance dégradée**
- Augmenter cache lifetime
- Réduire la fréquence de collecte
- Paralléliser les opérations

MARKDOWN;
  }

  /**
   * Génère une API Reference complète
   */
  public function generateAPIReference(): string
  {
    return <<<MARKDOWN
# API Reference

## MonitoringAgent

### Enregistrement de composants
\`\`\`php
$monitoring->registerComponent(string $componentName, object $component, array $metricsToTrack = []): void
\`\`\`

### Collecte de métriques
\`\`\`php
$metrics = $monitoring->collectMetrics(): array
\`\`\`

### Rapports
\`\`\`php
$health = $monitoring->getHealthReport(): array
$summary = $monitoring->getQuickSummary(): array
\`\`\`

## MetricsCollector

### Timers
\`\`\`php
$collector->startTimer(string $name, array $tags = []): void
$elapsed = $collector->stopTimer(string $name): ?float
\`\`\`

### Compteurs
\`\`\`php
$collector->increment(string $name, int $value = 1, array $tags = []): void
$collector->decrement(string $name, int $value = 1, array $tags = []): void
\`\`\`

### Gauges
\`\`\`php
$collector->gauge(string $name, float $value, array $tags = []): void
\`\`\`

### Histogrammes
\`\`\`php
$collector->recordHistogram(string $name, float $value, array $tags = []): void
$stats = $collector->getHistogramStats(string $name, array $tags = []): ?array
\`\`\`

## AlertManager

### Règles
\`\`\`php
$alertManager->addRule(string $ruleId, array $ruleConfig): void
$alertManager->enableRule(string $ruleId): bool
$alertManager->disableRule(string $ruleId): bool
\`\`\`

### Alertes
\`\`\`php
$triggered = $alertManager->triggerAlert(string $alertId, array $alertData): bool
$alertManager->acknowledgeAlert(string $alertId, string $acknowledgedBy = 'system'): bool
$alertManager->resolveAlert(string $alertId, string $resolution = ''): bool
\`\`\`

### Notifications
\`\`\`php
$alertManager->addNotificationChannel(string $channelName, callable $handler): void
\`\`\`

## StatsAggregator

### Agrégation
\`\`\`php
$aggregated = $aggregator->aggregate(): array
$report = $aggregator->getFullReport(): array
$summary = $aggregator->getExecutiveSummary(): array
\`\`\`

### Export
\`\`\`php
$json = $aggregator->exportJSON(): string
$csv = $aggregator->exportCSV(): string
\`\`\`

MARKDOWN;
  }

  /**
   * Exporte toute la documentation
   */
  public function exportComplete(string $outputDir): array
  {
    $results = [];

    // Markdown
    $markdownFile = $outputDir . '/API_REFERENCE.md';
    file_put_contents($markdownFile, $this->generateMarkdown());
    $results['markdown'] = $markdownFile;

    // HTML
    $htmlFile = $outputDir . '/index.html';
    file_put_contents($htmlFile, $this->generateHTML());
    $results['html'] = $htmlFile;

    // Best Practices
    $practicesFile = $outputDir . '/BEST_PRACTICES.md';
    file_put_contents($practicesFile, $this->generateBestPractices());
    $results['practices'] = $practicesFile;

    // API Reference
    $apiFile = $outputDir . '/API.md';
    file_put_contents($apiFile, $this->generateAPIReference());
    $results['api'] = $apiFile;

    // Architecture Diagram
    $diagramFile = $outputDir . '/ARCHITECTURE.txt';
    file_put_contents($diagramFile, $this->generateArchitectureDiagram());
    $results['diagram'] = $diagramFile;

    return $results;
  }

  /**
   * Génère un README complet
   */
  public function generateREADME(): string
  {
    return <<<MARKDOWN
# {$this->projectName} - Phase 4: Monitoring & Analytics

## Overview

Phase 4 implémente un système de monitoring complet, d'alerting intelligent et de rapports analytiques pour le système multi-agents ClicShopping AI.

## Composants

### 1. MonitoringAgent
Agent centralisé qui collecte et agrège les métriques de tous les composants du système. Fournit une vue d'ensemble de la santé du système avec détection d'anomalies.

**Responsabilités:**
- Collecte des métriques de chaque composant
- Calcul de la santé globale du système
- Génération de rapports
- Détection des anomalies

### 2. MetricsCollector
Collecteur temps réel de métriques avec support des timers, compteurs, gauges et histogrammes. Utilise le buffering pour la performance.

**Responsabilités:**
- Collecte des timers (mesure de durée)
- Incrément/décrement de compteurs
- Enregistrement de gauges (valeurs instantanées)
- Création d'histogrammes pour les distributions

### 3. AlertManager
Gestionnaire d'alertes configurable avec support de règles personnalisées, canaux de notification multiples et escalation automatique.

**Responsabilités:**
- Gestion des règles d'alerte
- Déclenchement et grouping des alertes
- Notification via multiples canaux
- Escalation automatique
- Historique des alertes

### 4. StatsAggregator
Agrégateur de statistiques qui combine les données de plusieurs sources et génère des rapports synthétiques avec détection de tendances.

**Responsabilités:**
- Agrégation de données multiples sources
- Calcul de métriques consolidées
- Détection de tendances et anomalies
- Génération de rapports exécutifs

## Quick Start

### Installation

1. Copier les 4 fichiers PHP dans votre projet
2. Initialiser les composants:

\`\`\`php
use ClicShopping\\Apps\\Configuration\\ChatGpt\\Classes\\Tools\\Monitoring\\{
  MonitoringAgent,
  MetricsCollector,
  AlertManager,
  StatsAggregator
};

$monitoring = new MonitoringAgent();
$collector = new MetricsCollector($monitoring);
$alertManager = new AlertManager();
$aggregator = new StatsAggregator();
\`\`\`

### Enregistrer les composants

\`\`\`php
$monitoring->registerComponent('OrchestratorAgent', $orchestrator);
$monitoring->registerComponent('TaskPlanner', $planner);
$monitoring->registerComponent('PlanExecutor', $executor);
\`\`\`

### Collecter des métriques

\`\`\`php
$collector->startTimer('operation');
// ... faire quelque chose ...
$collector->stopTimer('operation');

$collector->increment('requests_total');
$collector->gauge('memory_usage_mb', memory_get_usage(true) / 1024 / 1024);
\`\`\`

### Configurer les alertes

\`\`\`php
$alertManager->addRule('high_error_rate', [
  'message' => 'Error rate exceeded 10%',
  'severity' => 'error',
  'condition' => fn(\$m) => (\$m['error_rate'] ?? 0) > 0.1,
  'channels' => ['log', 'email'],
]);
\`\`\`

### Générer des rapports

\`\`\`php
$health = $monitoring->getHealthReport();
$summary = $aggregator->getExecutiveSummary();
$html = $monitoring->exportMetrics('html');
\`\`\`

## Configuration

### Development
\`\`\`php
define('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER', 'True');
$alertManager->setCooldownPeriod(60);
\`\`\`

### Production
\`\`\`php
define('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER', 'False');
$alertManager->setCooldownPeriod(1800);
$collector->bufferSize = 50;
\`\`\`

## Documentation

- [API Reference](API.md) - Documentation complète des APIs
- [Best Practices](BEST_PRACTICES.md) - Guide des meilleures pratiques
- [Architecture](ARCHITECTURE.txt) - Diagramme d'architecture
- [Integration Guide](INTEGRATION_GUIDE.md) - Guide d'intégration détaillé

## Support

Pour toute question ou problème:
1. Consulter la [documentation](.)
2. Vérifier les logs avec debug activé
3. Exporter les métriques pour analyse offline

## License

GPL 2 & MIT - ClicShopping
MARKDOWN;
  }
}