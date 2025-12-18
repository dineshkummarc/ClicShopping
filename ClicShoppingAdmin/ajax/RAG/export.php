<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */
use ClicShopping\OM\CLICSHOPPING;

use ClicShopping\AI\Infrastructure\Monitoring\AlertManager;
use ClicShopping\AI\Infrastructure\Monitoring\DocumentationGenerator;
use ClicShopping\AI\Infrastructure\Monitoring\MetricsCollector;
use ClicShopping\AI\Infrastructure\Monitoring\MonitoringAgent;
use ClicShopping\AI\Infrastructure\Monitoring\StatsAggregator;
use ClicShopping\Apps\Configuration\Administrators\Classes\ClicShoppingAdmin\AdministratorAdmin;

// -----------------------------------------------------
// 1. Initialisation ClicShopping (Votre code de base)
// -----------------------------------------------------

define('CLICSHOPPING_BASE_DIR', realpath(__DIR__ . '/../../../Core/ClicShopping/') . DIRECTORY_SEPARATOR);

require_once(CLICSHOPPING_BASE_DIR . 'OM/CLICSHOPPING.php');
spl_autoload_register('ClicShopping\OM\CLICSHOPPING::autoload');

CLICSHOPPING::initialize();
CLICSHOPPING::loadSite('ClicShoppingAdmin');

// Vérification de l'accès administrateur
AdministratorAdmin::hasUserAccess();

// -----------------------------------------------------
// 2. Logique d'Exportation
// -----------------------------------------------------


if (isset($_GET['export'])) {
  $exportType = strtolower($_GET['export']);
  $output = null;
  $mimeType = 'application/json'; // Par défaut

  try {
    // --- Instanciation des classes avec gestion des dépendances ---
    $monitoringAgent = new MonitoringAgent();
    // MetricsCollector a besoin de MonitoringAgent
    $metricsCollector = new MetricsCollector($monitoringAgent);
    $alertManager = new AlertManager();
    $statsAggregator = new StatsAggregator();
    // Le DocumentationGenerator n'a pas besoin de dépendances dans son constructeur
    $docGenerator = new DocumentationGenerator();
  } catch (\Exception $e) {
    header('HTTP/1.0 500 Internal Server Error');
    header('Content-Type: application/json');
    echo json_encode([
      'error' => 'Failed to initialize monitoring classes',
      'message' => $e->getMessage(),
      'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
    exit;
  }

  try {
    switch ($exportType) {
      // ------------------------------------
      // Exports CSV
      // ------------------------------------
      case 'csv':
        $output = $monitoringAgent->exportMetrics('csv');
        $mimeType = 'text/csv; charset=utf-8';
        // Set download headers
        header('Content-Disposition: attachment; filename="rag_statistics_' . date('Y-m-d_His') . '.csv"');
        break;

      // ------------------------------------
      // Exports JSON
      // ------------------------------------
      case 'health':
        $output = $monitoringAgent->exportMetrics();
        break;

      case 'metrics':
        $output = $metricsCollector->exportStatsD();
        break;

      case 'alerts':
        $output = $alertManager->exportJSON();
        break;

      case 'stats':
        $output = $statsAggregator->exportJSON();
        break;

      // ------------------------------------
      // Exports Prometheus
      // ------------------------------------
      case 'prometheus':
        $output = $metricsCollector->exportPrometheus();
        $mimeType = 'text/plain; version=0.0.4; charset=utf-8';
        break;

      // ------------------------------------
      // Exports HTML / Documentation
      // ------------------------------------
      case 'html_dashboard':
        $output = $monitoringAgent->exportMetrics('html');
        $mimeType = 'text/html; charset=utf-8';
        break;

      case 'documentation':
        $output = $docGenerator->generateMarkdown();
        $mimeType = 'text/markdown; charset=utf-8';
        break;

      default:
        // Type d'exportation non reconnu
        header('HTTP/1.0 400 Bad Request');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Type d\'exportation non valide', 'available_types' => ['csv', 'health', 'metrics', 'alerts', 'stats', 'prometheus', 'html_dashboard', 'documentation']]);
        exit;
    }
  } catch (\Exception $e) {
    header('HTTP/1.0 500 Internal Server Error');
    header('Content-Type: application/json');
    echo json_encode([
      'error' => 'Export failed',
      'export_type' => $exportType,
      'message' => $e->getMessage(),
      'file' => $e->getFile(),
      'line' => $e->getLine()
    ], JSON_PRETTY_PRINT);
    exit;
  }

  // ------------------------------------
  // 3. Envoi de la Réponse
  // ------------------------------------

  if ($output !== null) {
    // Envoi des Headers HTTP
    header('Content-Type: ' . $mimeType);

    // Détermine l'extension et encode si c'est du JSON
    if (strpos($mimeType, 'json') !== false) {
      // S'assurer que la sortie est un tableau/objet avant d'encoder
      if (is_array($output) || is_object($output)) {
        $output = json_encode($output, JSON_PRETTY_PRINT);
      }
    }

    // Affichage du contenu
    echo $output;

  } else {
    // Erreur si $output est null
    header('HTTP/1.0 500 Internal Server Error');
    echo json_encode(['error' => 'Erreur lors de la génération de l\'exportation (La méthode a retourné null).']);
  }

  // Arrêter l'exécution après l'exportation (réussie ou en erreur)
  exit;
}
// Fin du bloc 'if (isset($_GET['export']))'


// -----------------------------------------------------
// 4. Réponse par défaut (si pas de paramètre 'export')
// -----------------------------------------------------

// Ce code est exécuté uniquement si le script est appelé SANS le paramètre ?export=...
header('HTTP/1.0 200 OK');
echo "Script d'exportation en cours d'exécution. Utilisez le paramètre ?export=health (ou autre) pour obtenir des données.";