<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM)  at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;

use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\Apps\Configuration\Administrators\Classes\ClicShoppingAdmin\AdministratorAdmin;

use ClicShopping\AI\Rag\MultiDBRAGManager;
use ClicShopping\AI\Agents\Orchestrator\OrchestratorAgent;
use ClicShopping\AI\Agents\Memory\MemoryRetentionService;
use ClicShopping\AI\Insfrastructure\Metrics\StatisticsTracker;
use ClicShopping\AI\Helper\Formatter\ResultFormatter;
use ClicShopping\AI\Helper\ClarificationHelper;
use ClicShopping\AI\Helper\AgentResponseHelper;

define('CLICSHOPPING_BASE_DIR', realpath(__DIR__ . '/../../../Core/ClicShopping/') . DIRECTORY_SEPARATOR);

require_once(CLICSHOPPING_BASE_DIR . 'OM/CLICSHOPPING.php');
spl_autoload_register('ClicShopping\OM\CLICSHOPPING::autoload');

CLICSHOPPING::initialize();
CLICSHOPPING::loadSite('ClicShoppingAdmin');

AdministratorAdmin::hasUserAccess();

header('Content-Type: application/json; charset=UTF-8');
try {
  Gpt::getEnvironment();
} catch (Exception $e) {
  error_log("Warning: Could not load ChatGPT app/environment: " . $e->getMessage());
}

try {
  // ============================================
  // 0. CONFIGURE TIMEOUT (Test 5.6)
  // ============================================
  // Set maximum execution time for RAG queries to prevent blocking
  $maxExecutionTime = defined('CLICSHOPPING_APP_CHATGPT_RA_MAX_EXECUTION_TIME') 
    ? (int)CLICSHOPPING_APP_CHATGPT_RA_MAX_EXECUTION_TIME 
    : 30;
  
  $timeoutEnabled = defined('CLICSHOPPING_APP_CHATGPT_RA_ENABLE_TIMEOUT') 
    && CLICSHOPPING_APP_CHATGPT_RA_ENABLE_TIMEOUT === 'True';
  
  if ($timeoutEnabled) {
    set_time_limit($maxExecutionTime);
    
    // Register shutdown function to handle timeout gracefully
    register_shutdown_function(function() use ($maxExecutionTime) {
      $error = error_get_last();
      if ($error && ($error['type'] === E_ERROR || $error['type'] === E_USER_ERROR)) {
        // Check if it's a timeout error
        if (strpos($error['message'], 'Maximum execution time') !== false) {
          error_log('⏱️ TIMEOUT ERROR: Query exceeded ' . $maxExecutionTime . ' seconds');
          
          // Clean output buffer
          if (ob_get_length()) ob_clean();
          
          // Send timeout response
          header('Content-Type: application/json; charset=UTF-8');
          echo json_encode([
            'success' => false,
            'error' => 'La requête prend trop de temps, veuillez réessayer',
            'error_code' => 'QUERY_TIMEOUT',
            'timeout_seconds' => $maxExecutionTime,
            'interaction_id' => null,
            'timeout_error' => true
          ], JSON_UNESCAPED_UNICODE);
          exit;
        }
      }
    });
    
    if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
      error_log('⏱️ TIMEOUT CONFIGURED: ' . $maxExecutionTime . ' seconds');
    }
  }
  
  // Track start time for timeout monitoring
  $queryStartTime = microtime(true);
  
  // ============================================
  // 1. RÉCUPÉRER ET VALIDER LES DONNÉES
  // ============================================
  // 🔧 FIX: Supporter à la fois JSON et form-urlencoded pour compatibilité
  $rawInput = file_get_contents('php://input');
  $jsonInput = json_decode($rawInput, true);
  
  // Si JSON valide, utiliser JSON, sinon fallback sur $_POST
  if ($jsonInput && isset($jsonInput['message'])) {
    $userQuery = trim($jsonInput['message']);
  } else {
    // Fallback sur $_POST pour compatibilité
    $userQuery = trim($_POST['message'] ?? '');
  }
  
  // ⚠️ NE PAS utiliser HTML::sanitize() car cela transforme < et > en entités HTML
  // Ce qui casse les requêtes comme "produits < 150" ou "stock > 100"
  
  // 🛡️ SÉCURITÉ: Créer une version sanitizée pour l'affichage (protection XSS)
  $userQueryDisplay = htmlspecialchars($userQuery, ENT_QUOTES, 'UTF-8');

  $language = Registry::get('Language');
  $languageId = $language->getId();
  $userId = AdministratorAdmin::getUserAdminId();
  $sessionId = session_id();
  //$currentEntityId = $_SESSION['clicshopping_chatgpt_current_entity_id'] ?? 0;
  //$currentEntityType = $_SESSION['clicshopping_chatgpt_current_entity_type'] ?? 'unknown';

  // ============================================
  // VALIDATION: Input validation with proper error responses
  // ============================================
  
  // ✅ Validation 1: Empty query check
  if (empty($userQuery)) {
    error_log('⚠️ VALIDATION ERROR: Empty query received');
    
    if (ob_get_length()) ob_clean();
    
    echo json_encode([
      'success' => false,
      'error' => 'Veuillez entrer une question',
      'error_code' => 'EMPTY_QUERY',
      'interaction_id' => null,
      'validation_error' => true
    ], JSON_UNESCAPED_UNICODE);
    
    exit;
  }
  
  // ✅ Validation 2: Query length check (max 1000 characters)
  $maxLength = 1000;
  if (mb_strlen($userQuery, 'UTF-8') > $maxLength) {
    error_log('⚠️ VALIDATION ERROR: Query too long (' . mb_strlen($userQuery, 'UTF-8') . ' chars)');
    
    if (ob_get_length()) ob_clean();
    
    echo json_encode([
      'success' => false,
      'error' => "Votre question est trop longue (max {$maxLength} caractères)",
      'error_code' => 'QUERY_TOO_LONG',
      'interaction_id' => null,
      'validation_error' => true,
      'current_length' => mb_strlen($userQuery, 'UTF-8'),
      'max_length' => $maxLength
    ], JSON_UNESCAPED_UNICODE);
    
    exit;
  }

  // ✅ Validation 3: Ambiguity check (NEW - Test 5.5)
  // Detect very short or vague queries like "ça", "quoi", "ok"
  // NOTE: We use high confidence (1.0) here because we only want to detect
  // short/vague queries at this stage, NOT low-confidence classifications
  // (which happens later after actual intent analysis)
  
  $clarificationHelper = new ClarificationHelper(false); // debug mode off in production
  $intent = ['type' => 'unknown', 'confidence' => 1.0, 'metadata' => []];
  $ambiguityCheck = $clarificationHelper->detectAmbiguity($intent, $userQuery, []);

  if ($ambiguityCheck['is_ambiguous']) {
    error_log('⚠️ VALIDATION ERROR: Ambiguous query detected - Type: ' . $ambiguityCheck['ambiguity_type']);
    
    if (ob_get_length()) ob_clean();
    
    $clarificationResponse = AgentResponseHelper::buildClarificationRequest(
      $userQuery,
      $ambiguityCheck['ambiguity_type']
    );
    
    echo json_encode([
      'success' => false,
      'error' => $clarificationResponse['message'],
      'error_code' => 'AMBIGUOUS_QUERY',
      'ambiguity_type' => $ambiguityCheck['ambiguity_type'],
      'suggestions' => $ambiguityCheck['suggestions'] ?? [],
      'interaction_id' => null,
      'validation_error' => true
    ], JSON_UNESCAPED_UNICODE);
    
    exit;
  }

  if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
    error_log('=== CHATGPT.PHP START ===');
    error_log('User Query: ' . substr($userQuery, 0, 100));
    error_log('User ID: ' . $userId);
    error_log('Language ID: ' . $languageId);
  }

  // ============================================
  // 2. CRÉER L'INTERACTION ET DÉMARRER LE TRACKING
  // ============================================
  $db = Registry::get('Db');

  // Démarrer le tracking des statistiques
  $statsTracker = new StatisticsTracker($userId, $sessionId, $languageId);
  $statsTracker->startTracking();

  // ============================================
  // 3. INITIALISER LE SERVICE DE MÉMOIRE
  // ============================================
  $memoryService = new MemoryRetentionService($userId, $languageId);

  // ============================================
  // 4. RÉCUPÉRER LE CONTEXTE MULTI-NIVEAUX
  // ============================================
  $context = $memoryService->retrieveContext($userQuery, 5);

  if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
    error_log('📋 CONTEXT RETRIEVED:');
    error_log('   - Working Memory: ' . (empty($context['working_memory']) ? '∅' : '✓'));
    error_log('   - Short-term: ' . count($context['short_term']) . ' items');
    error_log('   - Long-term: ' . count($context['long_term']) . ' items');
  }

  // ============================================
  // 5. TRAITER LA REQUÊTE AVEC L'ORCHESTRATEUR
  // ============================================
  $orchestrator = new OrchestratorAgent($userId, $languageId);
  $aiResponse = $orchestrator->processWithValidation($userQuery);

  // Arrêter le tracking
  $responseTime = $statsTracker->stopTracking();
  
  // ============================================
  // 5.1 CHECK TIMEOUT (Test 5.6)
  // ============================================
  if ($timeoutEnabled) {
    $elapsedTime = microtime(true) - $queryStartTime;
    
    if ($elapsedTime >= $maxExecutionTime) {
      error_log('⏱️ TIMEOUT WARNING: Query took ' . round($elapsedTime, 2) . ' seconds (max: ' . $maxExecutionTime . ')');
      
      if (ob_get_length()) ob_clean();
      
      echo json_encode([
        'success' => false,
        'error' => 'La requête prend trop de temps, veuillez réessayer',
        'error_code' => 'QUERY_TIMEOUT',
        'timeout_seconds' => $maxExecutionTime,
        'elapsed_seconds' => round($elapsedTime, 2),
        'interaction_id' => null,
        'timeout_error' => true
      ], JSON_UNESCAPED_UNICODE);
      
      exit;
    }
    
    if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
      error_log('⏱️ TIMEOUT CHECK: Query took ' . round($elapsedTime, 2) . ' seconds (max: ' . $maxExecutionTime . ')');
    }
  }

  if (!$aiResponse['success']) {
    // Enregistrer l'erreur dans les statistiques
    $statsTracker->setError('orchestrator_error', $aiResponse['error'] ?? 'Unknown error');
    throw new \Exception($aiResponse['error'] ?? 'Erreur orchestrateur');
  }

  // Enregistrer les informations de l'agent et de la classification
  $statsTracker
    ->setAgentType($aiResponse['agent_used'] ?? 'unknown')
    ->setClassificationType($aiResponse['intent']['type'] ?? 'unknown')
    ->setConfidence($aiResponse['intent']['confidence'] ?? 0)
    ->setApiInfo('openai', CLICSHOPPING_APP_CHATGPT_CH_MODEL ?? 'gpt-4');

  if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
    error_log('✅ OrchestratorAgent processed successfully');
    error_log('   Agent Used: ' . ($aiResponse['agent_used'] ?? 'unknown'));
  }

  // ============================================
  // 5. EXTRAIRE entity_id ET language_id
  // ============================================
  // ✅ LOGIQUE CORRIGÉE: Récupérer ces valeurs de la réponse OrchestratorAgent

  $entityId = null;
  $entityType = null;

  // 1. Priorité 1: Chercher au niveau racine de la réponse (OrchestratorAgent)
  if (isset($aiResponse['entity_id'])) {
    $entityId = $aiResponse['entity_id'];
  }

  if (isset($aiResponse['entity_type'])) {
    $entityType = $aiResponse['entity_type'];
  }

  // 2. Priorité 2: Chercher dans data (structure OrchestratorAgent)
  if ($entityId === null && isset($aiResponse['data']['entity_id'])) {
    $entityId = $aiResponse['data']['entity_id'];
  }

  if ($entityType === null && isset($aiResponse['data']['entity_type'])) {
    $entityType = $aiResponse['data']['entity_type'];
  }

  // 3. Priorité 3: Si c'est une réponse analytique directe avec des résultats
  if ($entityId === null && !empty($aiResponse['data']['results']) && is_array($aiResponse['data']['results'])) {
    foreach ($aiResponse['data']['results'] as $result) {
      if (isset($result['id'])) {
        $entityId = $result['id'];
        break;
      }
    }
  }

  // 4. Valeurs par défaut sûres si rien n'est trouvé
  if ($entityId === null || $entityId === '' || $entityId === 'ABSENT') {
    $entityId = 0;  // Valeur par défaut (pas NULL)
  }

  if ($entityType === null || $entityType === '') {
    $entityType = 'unknown';
  }

  if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
    error_log('📌 EXTRACTED METADATA:');
    error_log('   Entity ID: ' . $entityId);
    error_log('   Entity Type: ' . $entityType);
    error_log('   Language ID: ' . $languageId);
  }

  // ============================================
  // 6. ENREGISTRER L'INTERACTION EN MÉMOIRE ⭐⭐⭐
  // ============================================
  $responseText = '';

  // 🔧 NOUVEAU: Utiliser text_response si disponible, sinon fallback
  if (isset($aiResponse['text_response']) && !empty($aiResponse['text_response'])) {
    $responseText = $aiResponse['text_response'];
  } elseif (isset($aiResponse['data']) && is_array($aiResponse['data'])) {
    // Essayer plusieurs champs possibles dans l'ordre de priorité
    $responseText = $aiResponse['data']['response'] 
                 ?? $aiResponse['data']['interpretation']  // 🔧 FIX: Ajouter interpretation pour analytics
                 ?? $aiResponse['data']['message'] 
                 ?? $aiResponse['data']['text_response']
                 ?? json_encode($aiResponse['data']);
  } elseif (isset($aiResponse['data'])) {
    $responseText = (string) $aiResponse['data'];
  } else {
    $responseText = 'Aucune réponse disponible';
  }

  $array_metadata = [
    'source' => 'chat_ajax',
    'success' => true,
    'agent_used' => $aiResponse['agent_used'] ?? 'unknown',
    'intent_confidence' => $aiResponse['intent']['confidence'] ?? 0,
    'execution_time' => $aiResponse['execution_time'] ?? 0,
    'entity_id' => $entityId,
    'entity_type' => $entityType,
    'language_id' => $languageId,
  ];

  $memoryService->recordInteraction($userQuery, $responseText, $array_metadata);

  if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
    error_log('💾 INTERACTION RECORDED IN MEMORY');
    error_log('   Query Length: ' . strlen($userQuery));
    error_log('   Response Length: ' . strlen($responseText));
  }

  // ============================================
  // 7. MIGRATION AUTOMATIQUE (CHAQUE 10 APPELS)
  // ============================================
  static $callCounter = 0;

  if (++$callCounter % 10 === 0) {
    $migrated = $memoryService->migrateShortTermToLongTerm();
    if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
      error_log('🔄 MIGRATION TRIGGERED: ' . $migrated . ' interactions migrated');
    }
  }

  // ============================================
  // 8. GÉNÉRER UN INTERACTION_ID UNIQUE
  // ============================================
  $clientInteractionId = 'interaction_' . $userId . '_' . time() . '_' . substr(md5(uniqid()), 0, 8);

  // ============================================
  // 9. FORMATER ET RETOURNER LA RÉPONSE JSON
  // ============================================
  $ragManager = new MultiDBRAGManager();

  // ✅ ALWAYS use ResultFormatter (no shortcuts)
  if (!Registry::exists('ResultFormatter')) {
    Registry::set('ResultFormatter', new ResultFormatter());
  }

  $resultFormatter = Registry::get('ResultFormatter');

  // 🔧 DEBUG: Log the full aiResponse structure
  if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
    error_log('🔍 FULL aiResponse structure:');
    error_log('   Keys: ' . implode(', ', array_keys($aiResponse)));
    error_log('   Has data: ' . (isset($aiResponse['data']) ? 'YES' : 'NO'));
    error_log('   Has text_response: ' . (isset($aiResponse['text_response']) ? 'YES (' . strlen($aiResponse['text_response']) . ' chars)' : 'NO'));
    error_log('   Has result: ' . (isset($aiResponse['result']) ? 'YES' : 'NO'));

    if (isset($aiResponse['text_response'])) {
      error_log('   text_response preview: ' . substr($aiResponse['text_response'], 0, 100));
    }
  }
  
  $dataToFormat = $aiResponse['data'] ?? [];

  if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
    error_log('📦 DATA TO FORMAT (before processing):');
    error_log('   Type: ' . gettype($dataToFormat));
    error_log('   Is array: ' . (is_array($dataToFormat) ? 'YES' : 'NO'));

    if (is_array($dataToFormat)) {
      error_log('   Keys: ' . implode(', ', array_keys($dataToFormat)));
      error_log('   Has type: ' . (isset($dataToFormat['type']) ? $dataToFormat['type'] : 'NO'));
      error_log('   Has response: ' . (isset($dataToFormat['response']) ? 'YES (' . strlen($dataToFormat['response']) . ' chars)' : 'NO'));
    }
  }

  // 🔧 ALWAYS process dataToFormat and use ResultFormatter
  if (!is_array($dataToFormat)) {
    $dataToFormat = ['type' => 'error', 'message' => 'Format invalide'];
  }

  if (!isset($dataToFormat['type'])) {
    $dataToFormat['type'] = 'semantic_results';
  }

  if (!isset($dataToFormat['query'])) {
    $dataToFormat['query'] = $userQuery;
  }

  // ⭐ Ajout des IDs d'entité pour le formateur
  $dataToFormat['entity_id'] = $entityId;
  $dataToFormat['entity_type'] = $entityType;

  // 🆕 Preserve source_attribution if present in aiResponse['data']
  if (isset($aiResponse['data']['source_attribution'])) {
    $dataToFormat['source_attribution'] = $aiResponse['data']['source_attribution'];
  }

  // 🆕 If text_response exists but no structured data, add it to dataToFormat
  if (isset($aiResponse['text_response']) && !empty($aiResponse['text_response'])) {
    if (!isset($dataToFormat['response']) || empty($dataToFormat['response'])) {
      $dataToFormat['response'] = $aiResponse['text_response'];
    }
  }

  if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
    error_log('📦 DATA TO FORMAT (after processing):');
    error_log('   Type: ' . ($dataToFormat['type'] ?? 'NONE'));
    error_log('   Has response: ' . (isset($dataToFormat['response']) ? 'YES' : 'NO'));
    error_log('   Has interpretation: ' . (isset($dataToFormat['interpretation']) ? 'YES' : 'NO'));
    error_log('   Has source_attribution: ' . (isset($dataToFormat['source_attribution']) ? 'YES' : 'NO'));
    error_log('   Has results: ' . (isset($dataToFormat['results']) ? 'YES (' . count($dataToFormat['results']) . ' rows)' : 'NO'));
    if (isset($dataToFormat['results']) && !empty($dataToFormat['results'])) {
      error_log('   First result row keys: ' . implode(', ', array_keys($dataToFormat['results'][0])));
    }
  }



  // 🆕 Check if memory context display is enabled and context is available
  $displayMemory = defined('CLICSHOPPING_APP_CHATGPT_RA_DISPLAY_MEMORY_CONTEXT')  && CLICSHOPPING_APP_CHATGPT_RA_DISPLAY_MEMORY_CONTEXT === 'True';
  
  if ($displayMemory && !empty($context) && isset($memoryService)) {
    // Use the context already retrieved earlier (line 104)
    // Transform it to the format expected by ResultFormatter
    $memoryContext = [
      'short_term_context' => $context['short_term'] ?? [],
      'long_term_context' => $context['long_term'] ?? [],
      'feedback_context' => [], // Feedback context not yet in retrieveContext
      'has_context' => !empty($context['short_term']) || !empty($context['long_term']),
    ];
    
    if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
      error_log('💾 MEMORY CONTEXT FOR DISPLAY:');
      error_log('   Short-term: ' . count($memoryContext['short_term_context']));
      error_log('   Long-term: ' . count($memoryContext['long_term_context']));
      error_log('   Feedback: ' . count($memoryContext['feedback_context']));
      error_log('   Has context: ' . ($memoryContext['has_context'] ? 'YES' : 'NO'));
    }
    
    // Use formatWithMemory if memory context is relevant
    $formattedResult = $resultFormatter->formatWithMemory($dataToFormat, $memoryContext);
  } else {
    // Use standard format without memory context
    $formattedResult = $resultFormatter->format($dataToFormat);
  }
  
  $formatted = $formattedResult['content'] ?? '';
  
  if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
    error_log('✅ Using ResultFormatter: ' . strlen($formatted) . ' chars');
    if (isset($formattedResult['has_memory_context'])) {
      error_log('   Memory context integrated: YES');
    }
  }

  if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
    error_log('📤 FORMATTED RESPONSE:');
    error_log('   Formatted result type: ' . (is_array($formattedResult) ? 'array' : gettype($formattedResult)));
    error_log('   Formatted result keys: ' . (is_array($formattedResult) ? implode(', ', array_keys($formattedResult)) : 'N/A'));
    error_log('   Content length: ' . strlen($formatted));
    error_log('   Content preview: ' . substr($formatted, 0, 200));
    error_log('   Original dataToFormat type: ' . ($dataToFormat['type'] ?? 'NONE'));
    error_log('   Interaction ID: ' . ($clientInteractionId ?? 'pending'));
  }

  // ⭐ RETOURNER DU JSON AVEC INTERACTION_ID
  // ============================================
  // 10. SAUVEGARDER LES STATISTIQUES ET METTRE À JOUR L'INTERACTION
  // ============================================
  
  // Arrêter le tracking et obtenir le temps de réponse
  $responseTime = $statsTracker->stopTracking();
  
  // Déterminer l'agent utilisé basé sur le type de requête
  $agentUsed = 'orchestrator';
  if (isset($aiResponse['intent']['type'])) {
    switch ($aiResponse['intent']['type']) {
      case 'analytics':
        $agentUsed = 'analytics_agent';
        break;
      case 'semantic':
        $agentUsed = 'semantic_agent';
        break;
      case 'hybrid':
        $agentUsed = 'hybrid_agent';
        break;
    }
  }
  
  // Enregistrer les métriques dans StatisticsTracker
  $statsTracker
    ->setAgentType($agentUsed)
    ->setClassificationType($aiResponse['intent']['type'] ?? 'unknown')
    ->setConfidence($aiResponse['intent']['confidence'] ?? 0)
    ->setQualityScores(85, 90) // TODO: Calculer des scores basés sur la réponse
    ->setApiInfo('openai', CLICSHOPPING_APP_CHATGPT_CH_MODEL ?? 'gpt-4');
  
  // Récupérer les tokens depuis Gpt::getLastTokenUsage()
  $tokenUsage = \ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt::getLastTokenUsage();
  if ($tokenUsage !== null) {
    // setTokens() calcule automatiquement le coût via calculateCost()
    $statsTracker->setTokens($tokenUsage['prompt_tokens'], $tokenUsage['completion_tokens']);
    
    error_log(sprintf(
      '📊 Tokens recorded: prompt=%d, completion=%d, total=%d',
      $tokenUsage['prompt_tokens'],
      $tokenUsage['completion_tokens'],
      $tokenUsage['total_tokens']
    ));
  } else {
    error_log('⚠️ No token usage data available from Gpt::getLastTokenUsage()');
  }

  // =============================
  // Compute fallback metrics
  // =============================
  $confidenceScore = (float)($aiResponse['metrics']['confidence_score'] ?? ($aiResponse['intent']['confidence'] ?? 0));
  $intentType = $aiResponse['intent']['type'] ?? 'semantic';
  $hasUsefulData = isset($dataToFormat) && is_array($dataToFormat) && (!empty($dataToFormat['results']) || !empty($dataToFormat['response']) || !empty($dataToFormat['interpretation']));
  $responseLen = isset($formatted) ? strlen($formatted) : 0;

  $securityScore = isset($aiResponse['metrics']['security_score']) ? (float)$aiResponse['metrics']['security_score'] : ( $intentType === 'web_search' ? 0.5 : 0.8 );

  $hallucinationScore = isset($aiResponse['metrics']['hallucination_score']) ? (float)$aiResponse['metrics']['hallucination_score'] : ( $hasUsefulData ? 0.1 : ($intentType === 'semantic' ? 0.2 : 0.3) );

  $responseQuality = isset($aiResponse['metrics']['response_quality']) ? (float)$aiResponse['metrics']['response_quality'] : ( $responseLen > 800 ? 0.85 : ($responseLen > 300 ? 0.7 : 0.55) );

  $relevanceScore = isset($aiResponse['metrics']['relevance_score']) ? (float)$aiResponse['metrics']['relevance_score']: $confidenceScore;

  // ============================================
  // 10.1 PERSIST COMPLETE INTERACTION + STATS
  // ============================================
  if (empty($responseText)) {
    error_log("⚠️ WARNING: Persisting interaction with empty response");
  }

  // Note: entity_id = 0 is valid for general queries (not entity-specific)
  // Only warn if entity_id is truly null (should not happen after extraction logic)
  if ($entityId === null) {
    if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
      error_log("⚠️ WARNING: entity_id is null (unexpected), defaulting to 0");
    }
    $entityId = 0;
    $entityType = $entityType ?? 'general';
  }

  $statsSnapshot = $statsTracker->getAllMetrics();

  $tokensUsed = $aiResponse['usage']['total_tokens']
    ?? $aiResponse['metrics']['tokens_used']
    ?? $statsSnapshot['tokens_total']
    ?? null;
  $tokensUsed = $tokensUsed !== null ? (int)$tokensUsed : null;

  $apiCostValue = $aiResponse['metrics']['api_cost']
    ?? $statsSnapshot['api_cost_usd']
    ?? null;
  $apiCostValue = $apiCostValue !== null ? round((float)$apiCostValue, 6) : null;

  $responseQualityScore = $responseQuality;
  $responseQualityValue = $responseQualityScore <= 1
    ? (int)round($responseQualityScore * 100)
    : (int)round($responseQualityScore);

  $resolvedAgentUsed = $aiResponse['agent_used'] ?? $agentUsed ?? 'unknown';
  $resolvedIntentType = $aiResponse['intent']['type'] ?? 'unknown';

  $interactionData = [
    'user_id' => $userId,
    'session_id' => $sessionId,
    'question' => $userQuery,
    'response' => $responseText,
    'request_type' => $resolvedIntentType,
    'confidence' => $aiResponse['intent']['confidence'] ?? 0,
    'response_quality' => $responseQualityValue,
    'response_time' => $responseTime ?? 0,
    'execution_time' => $aiResponse['execution_time'] ?? 0,
    'tokens_used' => $tokensUsed,
    'api_cost' => $apiCostValue,
    'language_id' => $languageId,
    'entity_id' => $entityId,
    'entity_type' => $entityType,
    'agent_used' => $resolvedAgentUsed,
    'intent_type' => $resolvedIntentType,
    'date_added' => 'now()',
  ];

  $dbInteractionId = null;

  try {
    $db->save('rag_interactions', $interactionData);
    $dbInteractionId = (int)$db->lastInsertId();
    $statsTracker->setInteractionId($dbInteractionId);

    if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
      error_log("💾 INTERACTION INSERTED:");
      error_log("   DB ID: {$dbInteractionId}");
      error_log("   Question: " . substr($userQuery, 0, 50));
      error_log("   Answer length: " . strlen($responseText));
      error_log("   Entity: {$entityType} (ID: {$entityId})");
      error_log("   Agent: {$resolvedAgentUsed}");
      error_log("   Intent: {$resolvedIntentType}");
      error_log("   Confidence: " . ($aiResponse['intent']['confidence'] ?? 0));
      error_log("   Execution time: " . ($aiResponse['execution_time'] ?? 0) . 'ms');
      error_log("   Response quality: {$responseQualityValue}");
      error_log("   Tokens used: " . ($tokensUsed ?? 'null'));
      error_log("   API cost: " . ($apiCostValue ?? 'null'));
    }
  } catch (\Exception $e) {
    error_log("❌ Failed to insert interaction record: " . $e->getMessage());
  }

  $statsSaved = false;
  if ($dbInteractionId !== null) {
    try {
      $statsSaved = $statsTracker->save();
    } catch (\Exception $e) {
      error_log("❌ Failed to save statistics for interaction {$dbInteractionId}: " . $e->getMessage());
    }
  } else {
    error_log('⚠️ STATISTICS NOT SAVED: missing interaction ID');
  }

  // ✅ ALWAYS LOG CRITICAL METRICS (Requirement 8.1, 8.2, 8.5)
  error_log(sprintf(
    '[RAG] Query processed: type=%s, confidence=%.2f, agent=%s, time=%dms, stats_saved=%s',
    $aiResponse['intent']['type'] ?? 'unknown',
    $aiResponse['intent']['confidence'] ?? 0,
    $aiResponse['agent_used'] ?? 'unknown',
    $responseTime,
    $statsSaved ? 'YES' : 'NO'
  ));
  
  if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
    error_log('📊 STATISTICS SAVED: ' . ($statsSaved ? 'YES' : 'NO'));
    error_log('   Response time: ' . $responseTime . 'ms');
    error_log('   Agent: ' . ($aiResponse['agent_used'] ?? 'unknown'));
    error_log('   Classification: ' . ($aiResponse['intent']['type'] ?? 'unknown'));
  }

  $jsonResponse = [
    'success' => true,
    'interaction_id' => $clientInteractionId,
    'text_response' => nl2br($formatted),
    'user_query_display' => $userQueryDisplay,  // 🛡️ Version sanitizée pour affichage sécurisé
    'type' => $aiResponse['intent']['type'] ?? 'semantic',
    'confidence' => $aiResponse['intent']['confidence'] ?? 0,
    'agent_used' => $aiResponse['agent_used'] ?? 'unknown',
    'execution_time' => $aiResponse['execution_time'] ?? 0,
    'entity_id' => $entityId,
    'entity_type' => $entityType,
    'language_id' => $languageId,
    // Métriques de qualité et sécurité
    'metrics' => [
      'confidence_score' => $confidenceScore,
      'security_score' => $securityScore,
      'hallucination_score' => $hallucinationScore,
      'response_quality' => $responseQuality,
      'relevance_score' => $relevanceScore
    ],
    'metadata' => [
      'query' => $userQuery,
      'query_display' => $userQueryDisplay,  // 🛡️ Version sanitizée dans metadata aussi
      'timestamp' => time(),
      'user_id' => $userId
    ]
  ];

  // Ajouter les infos de debug si activé
  if (defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
    $jsonResponse['debug'] = [
      'agent' => $aiResponse['agent_used'] ?? 'unknown',
      'time' => $aiResponse['execution_time'] ?? 0,
      'entity_id' => $entityId,
      'entity_type' => $entityType,
      'language_id' => $languageId
    ];
    error_log('✅ CHATGPT.PHP END - JSON Response sent to client');
  }

  // 🔧 FIX: Nettoyer le buffer de sortie avant d'envoyer le JSON
  if (ob_get_length()) {
    ob_clean();
  }  
  
  echo json_encode($jsonResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (\Exception $e) {
  // Log technical details for debugging
  error_log('❌ ERREUR in chatGpt.php: ' . $e->getMessage());
  error_log('Stack: ' . $e->getTraceAsString());
  error_log('File: ' . $e->getFile() . ':' . $e->getLine());
  
  // 🔧 FIX: Nettoyer le buffer de sortie avant d'envoyer le JSON d'erreur
  if (ob_get_length()) {
    ob_clean();
  }
  
  // Determine error type and user-friendly message
  $errorCode = 'SYSTEM_ERROR';
  $userMessage = 'Une erreur technique est survenue. Veuillez réessayer plus tard.';
  
  // Check if it's a database error
  if ($e instanceof \PDOException || 
      stripos($e->getMessage(), 'database') !== false ||
      stripos($e->getMessage(), 'mysql') !== false ||
      stripos($e->getMessage(), 'sql') !== false ||
      stripos($e->getMessage(), 'connection') !== false) {
    $errorCode = 'DATABASE_ERROR';
    error_log('🔴 Database error detected - hiding technical details from user');
  }
  
  // Retourner une erreur JSON avec message générique
  echo json_encode([
    'success' => false,
    'error' => $userMessage,
    'error_code' => $errorCode,
    'interaction_id' => null
  ], JSON_UNESCAPED_UNICODE);
}