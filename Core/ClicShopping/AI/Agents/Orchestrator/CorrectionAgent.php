<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\Orchestrator;

use AllowDynamicProperties;
use ClicShopping\AI\Helper\EntityRegistry;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;

use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Security\InputValidator;
use ClicShopping\AI\Infrastructure\Storage\MariaDBVectorStore;
use ClicShopping\AI\Domain\Embedding\NewVector;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\AI\Infrastructure\Cache\Cache;

use LLPhant\Embeddings\Document;
use LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface;

/**
 * CorrectionAgent Class
 *
 * Agent spécialisé dans l'apprentissage et la correction automatique des erreurs.
 * Utilise l'apprentissage par renforcement pour :
 * - Mémoriser les erreurs et leurs corrections réussies
 * - Appliquer des corrections basées sur l'historique
 * - Suggérer des améliorations proactives
 * - S'auto-améliorer au fil du temps
 */
#[AllowDynamicProperties]
class CorrectionAgent
{
  private SecurityLogger $securityLogger;
  private MariaDBVectorStore $correctionStore;
  private EmbeddingGeneratorInterface $embeddingGenerator;
  private Cache $cache;
  private bool $debug;
  private string $userId;
  private int $languageId;
  private mixed $language;
  private mixed $chat;

  // Stratégies de correction
  private array $correctionStrategies = [];

  // Statistiques d'apprentissage
  private array $learningStats = [
    'total_errors' => 0,
    'successful_corrections' => 0,
    'failed_corrections' => 0,
    'learned_patterns' => 0,
    'correction_accuracy' => 0.0,
  ];

  // Configuration
  private float $confidenceThreshold = 0.7;
  private int $maxSimilarCases = 5;

  private mixed $db;

  /**
   * Constructor
   *
   * @param string $userId Identifiant de l'utilisateur
   * @param int|null $languageId ID de la langue
   * @param string $tableName Table pour stocker les corrections
   */
  public function __construct(string $userId = 'system', ?int $languageId = null, string $tableName = 'rag_correction_patterns_embedding')
  {
    $this->userId = $userId;
    $this->securityLogger = new SecurityLogger();
    $this->cache = new Cache(true);
    $this->debug = defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';
    $this->db = Registry::get('Db');
    $this->language = Registry::get('Language');
    
    if (is_null($languageId)) {
      $this->languageId = $this->language->getId();
    } else {
      $this->languageId = $languageId;
    }

    // Initialiser l'embedding generator
    $this->embeddingGenerator = $this->createEmbeddingGenerator();

    // Initialiser le vector store pour les patterns de correction
    $this->correctionStore = new MariaDBVectorStore($this->embeddingGenerator, $tableName);

    // Charger les stratégies de correction
    $this->initializeCorrectionStrategies();

    // Charger les stats depuis le cache
    $this->loadLearningStats();

    if ($this->debug) {
      $this->securityLogger->logSecurityEvent(
        "CorrectionAgent initialized for user: {$this->userId}",
        'info'
      );
    }
  }

  /**
   * Crée l'embedding generator
   */
  private function createEmbeddingGenerator(): EmbeddingGeneratorInterface
  {
    return new class implements EmbeddingGeneratorInterface {
      public function embedText(string $text): array
      {
        $generator = NewVector::gptEmbeddingsModel();
        if (!$generator) {
          throw new \RuntimeException('Embedding generator non initialisé');
        }
        return $generator->embedText($text);
      }

      public function embedDocument(Document $document): Document
      {
        $document->embedding = $this->embedText($document->content);
        return $document;
      }

      public function embedDocuments(array $documents): array
      {
        $results = [];
        foreach ($documents as $document) {
          $results[] = $this->embedDocument($document);
        }
        return $results;
      }

      public function getEmbeddingLength(): int
      {
        return NewVector::getEmbeddingLength();
      }
    };
  }



  /**
   * Initialise les stratégies de correction
   */
  private function initializeCorrectionStrategies(): void
  {
    $this->correctionStrategies = [
      'syntax_error' => [$this, 'correctSyntaxError'],
      'unknown_column' => [$this, 'correctUnknownColumn'],
      'unknown_table' => [$this, 'correctUnknownTable'],
      'group_by_error' => [$this, 'correctGroupByError'],
      'join_error' => [$this, 'correctJoinError'],
      'type_mismatch' => [$this, 'correctTypeMismatch'],
      'semantic_error' => [$this, 'correctSemanticError'],
    ];
  }

  /**
   * Point d'entrée principal : tente de corriger une erreur
   *
   * @param array $errorContext Contexte de l'erreur
   * @return array Résultat de la correction
   */
  public function attemptCorrection(array $errorContext): array
  {
    $startTime = microtime(true);
    $this->learningStats['total_errors']++;

    try {
      // 1. Analyser le type d'erreur
      $errorAnalysis = $this->analyzeError($errorContext);

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Error analyzed: Type={$errorAnalysis['type']}, Confidence={$errorAnalysis['confidence']}",
          'info'
        );
      }

      // 2. Rechercher des cas similaires dans l'historique
      $similarCases = $this->findSimilarCases($errorContext, $errorAnalysis);

      // 3. Appliquer la stratégie de correction appropriée
      $correction = $this->applyCorrectionStrategy(
        $errorContext,
        $errorAnalysis,
        $similarCases
      );

      // 4. Valider la correction
      $validation = $this->validateCorrection($correction);

      if ($validation['is_valid']) {
        // 5. Si succès, mémoriser le pattern
        $this->memorizeSuccessfulCorrection(
          $errorContext,
          $correction,
          $errorAnalysis
        );

        $this->learningStats['successful_corrections']++;

        $result = [
          'success' => true,
          'corrected_query' => $correction['query'],
          'correction_method' => $correction['method'],
          'confidence' => $correction['confidence'],
          'learned_from_history' => !empty($similarCases),
          'similar_cases_found' => count($similarCases),
          'execution_time' => microtime(true) - $startTime,
          'suggestions' => $correction['suggestions'] ?? [],
        ];
      } else {
        $this->learningStats['failed_corrections']++;

        $result = [
          'success' => false,
          'error' => 'Correction validation failed',
          'validation_issues' => $validation['issues'],
          'attempted_correction' => $correction['query'] ?? null,
          'suggestions' => $this->generateFallbackSuggestions($errorContext),
        ];
      }

      // Mettre à jour l'accuracy
      $this->updateAccuracy();

      return $result;

    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Correction attempt failed: " . $e->getMessage(),
        'error'
      );

      return [
        'success' => false,
        'error' => $e->getMessage(),
        'suggestions' => $this->generateFallbackSuggestions($errorContext),
      ];
    }
  }

  /**
   * Analyse l'erreur pour déterminer son type et sa gravité
   *
   * @param array $errorContext Contexte de l'erreur
   * @return array Analyse de l'erreur
   */
  private function analyzeError(array $errorContext): array
  {
    $errorMessage = $errorContext['error_message'] ?? '';
    $failedQuery = $errorContext['failed_query'] ?? '';

    $analysis = [
      'type' => 'unknown',
      'severity' => 'medium',
      'confidence' => 0.0,
      'correctable' => true,
      'details' => [],
    ];

    // Patterns d'erreur SQL
    $errorPatterns = [
      'syntax_error' => [
        'patterns' => [
          '/syntax error.*?near/i',
          '/unexpected.*?at line/i',
          '/invalid syntax/i',
        ],
        'severity' => 'high',
      ],
      'unknown_column' => [
        'patterns' => [
          '/unknown column/i',
          '/column.*?does not exist/i',
          '/ambiguous column/i',
        ],
        'severity' => 'medium',
      ],
      'unknown_table' => [
        'patterns' => [
          '/table.*?doesn\'t exist/i',
          '/no such table/i',
          '/unknown table/i',
        ],
        'severity' => 'high',
      ],
      'group_by_error' => [
        'patterns' => [
          '/not in GROUP BY/i',
          '/must appear in.*?GROUP BY/i',
          '/incompatible with sql_mode=only_full_group_by/i',
        ],
        'severity' => 'medium',
      ],
      'join_error' => [
        'patterns' => [
          '/unknown column in (on|join) clause/i',
          '/ambiguous.*?in (on|join)/i',
        ],
        'severity' => 'medium',
      ],
      'type_mismatch' => [
        'patterns' => [
          '/operand type/i',
          '/type mismatch/i',
          '/invalid.*?for.*?type/i',
        ],
        'severity' => 'medium',
      ],
    ];

    // Identifier le type d'erreur
    $matchedConfidence = 0;
    foreach ($errorPatterns as $type => $config) {
      foreach ($config['patterns'] as $pattern) {
        if (preg_match($pattern, $errorMessage)) {
          $analysis['type'] = $type;
          $analysis['severity'] = $config['severity'];
          $matchedConfidence = 0.9;
          break 2;
        }
      }
    }

    // Si aucun pattern direct, utiliser le LLM pour analyse sémantique
    if ($matchedConfidence === 0) {
      $llmAnalysis = $this->analyzErrorWithLLM($errorMessage, $failedQuery);
      $analysis['type'] = $llmAnalysis['type'] ?? 'semantic_error';
      $matchedConfidence = $llmAnalysis['confidence'] ?? 0.5;
    }

    $analysis['confidence'] = $matchedConfidence;

    // Extraire les détails spécifiques
    $analysis['details'] = $this->extractErrorDetails($errorMessage, $analysis['type']);

    return $analysis;
  }

  /**
   * Analyse l'erreur avec le LLM pour les cas complexes
   *
   * @param string $errorMessage Message d'erreur
   * @param string $query Requête qui a échoué
   * @return array Analyse LLM
   */
  private function analyzErrorWithLLM(string $errorMessage, string $query): array
  {
    // Load SYSTEM prompt in English for better LLM performance (internal analysis)
    $this->language->loadDefinitions('main', 'en', null, 'ClicShoppingAdmin');
    
    $prompt = $this->language->getDef('text_analyze_sql_error', [
      'error' => $errorMessage,
      'query' => $query
    ]) ?? "Analyze this SQL error and categorize it:\nError: {$errorMessage}\nQuery: {$query}\n\nProvide: error_type, confidence (0-1), description";

    if ($this->debug) {
      $this->securityLogger->logSecurityEvent(
        "CorrectionAgent: Using English SYSTEM prompt for error analysis",
        'info'
      );
      $this->securityLogger->logSecurityEvent(
        "Prompt length: " . strlen($prompt) . " chars",
        'info'
      );
    }

    try {
      // Utilisation de Gpt::getGptResponse pour la validation et sécurité
      $response = Gpt::getGptResponse($prompt, 150);

      // Parser la réponse du LLM
      $parsed = $this->parseLLMResponse($response);

      return [
        'type' => $parsed['error_type'] ?? 'unknown',
        'confidence' => (float) ($parsed['confidence'] ?? 0.5),
        'description' => $parsed['description'] ?? '',
      ];

    } catch (\Exception $e) {
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "LLM error analysis failed: " . $e->getMessage(),
          'error'
        );
      }

      return [
        'type' => 'unknown',
        'confidence' => 0.3,
        'description' => 'LLM analysis failed',
      ];
    }
  }

  /**
   * Parse la réponse du LLM
   */
  private function parseLLMResponse(string $response): array
  {
    $parsed = [];

    // Extraire error_type
    if (preg_match('/error[_\s]type[:\s]+([a-z_]+)/i', $response, $matches)) {
      $parsed['error_type'] = trim($matches[1]);
    }

    // Extraire confidence
    if (preg_match('/confidence[:\s]+([\d\.]+)/i', $response, $matches)) {
      $parsed['confidence'] = (float) $matches[1];
    }

    // Extraire description
    if (preg_match('/description[:\s]+(.+?)(?:\n|$)/i', $response, $matches)) {
      $parsed['description'] = trim($matches[1]);
    }

    return $parsed;
  }

  /**
   * Extrait les détails spécifiques de l'erreur
   */
  private function extractErrorDetails(string $errorMessage, string $errorType): array
  {
    $details = [];

    switch ($errorType) {
      case 'unknown_column':
        if (preg_match('/column [\'"](.*?)[\'"]/i', $errorMessage, $matches)) {
          $details['column_name'] = $matches[1];
        }
        break;

      case 'unknown_table':
        if (preg_match('/table [\'"](.*?)[\'"]/i', $errorMessage, $matches)) {
          $details['table_name'] = $matches[1];
        }
        break;

      case 'group_by_error':
        if (preg_match('/column [\'"](.*?)[\'"]/i', $errorMessage, $matches)) {
          $details['missing_column'] = $matches[1];
        }
        break;
    }

    return $details;
  }

  /**
   * Recherche des cas similaires dans l'historique
   *
   * @param array $errorContext Contexte de l'erreur
   * @param array $errorAnalysis Analyse de l'erreur
   * @return array Cas similaires trouvés
   */
  private function findSimilarCases(array $errorContext, array $errorAnalysis): array
  {
    try {
      // Créer une représentation textuelle de l'erreur
      $errorRepresentation = $this->createErrorRepresentation($errorContext, $errorAnalysis);

      // Rechercher dans le vector store
      $filter = function ($metadata) use ($errorAnalysis) {
        return isset($metadata['error_type'])
          && $metadata['error_type'] === $errorAnalysis['type']
          && isset($metadata['correction_successful'])
          && $metadata['correction_successful'] === true;
      };

      $results = $this->correctionStore->similaritySearch(
        $errorRepresentation,
        $this->maxSimilarCases,
        0.6, // Seuil plus bas pour trouver plus de cas
        $filter
      );

      $similarCases = [];
      foreach ($results as $doc) {
        $similarCases[] = [
          'original_error' => $doc->metadata['original_error'] ?? '',
          'original_query' => $doc->metadata['original_query'] ?? '',
          'corrected_query' => $doc->metadata['corrected_query'] ?? '',
          'correction_method' => $doc->metadata['correction_method'] ?? '',
          'similarity_score' => $doc->metadata['score'] ?? 0,
          'success_rate' => $doc->metadata['success_rate'] ?? 0,
        ];
      }

      if ($this->debug && !empty($similarCases)) {
        $this->securityLogger->logSecurityEvent(
          "Found " . count($similarCases) . " similar correction cases",
          'info'
        );
      }

      return $similarCases;

    } catch (\Exception $e) {
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Error finding similar cases: " . $e->getMessage(),
          'error'
        );
      }

      return [];
    }
  }

  /**
   * Crée une représentation textuelle de l'erreur pour la recherche
   */
  private function createErrorRepresentation(array $errorContext, array $errorAnalysis): string
  {
    $parts = [];

    $parts[] = "Error Type: " . $errorAnalysis['type'];
    $parts[] = "Error Message: " . ($errorContext['error_message'] ?? '');

    if (!empty($errorAnalysis['details'])) {
      foreach ($errorAnalysis['details'] as $key => $value) {
        $parts[] = ucfirst($key) . ": " . $value;
      }
    }

    $parts[] = "Query Fragment: " . substr($errorContext['failed_query'] ?? '', 0, 200);

    return implode("\n", $parts);
  }

  /**
   * Applique la stratégie de correction appropriée
   *
   * @param array $errorContext Contexte de l'erreur
   * @param array $errorAnalysis Analyse de l'erreur
   * @param array $similarCases Cas similaires
   * @return array Correction proposée
   */
  private function applyCorrectionStrategy(
    array $errorContext,
    array $errorAnalysis,
    array $similarCases
  ): array {
    // 1. Si cas similaires trouvés avec haute confiance, les utiliser
    if (!empty($similarCases) && $similarCases[0]['similarity_score'] > 0.85) {
      return $this->applyLearnedCorrection($errorContext, $similarCases[0]);
    }

    // 2. Sinon, utiliser les stratégies programmatiques
    $errorType = $errorAnalysis['type'];

    if (isset($this->correctionStrategies[$errorType])) {
      $strategy = $this->correctionStrategies[$errorType];
      return call_user_func($strategy, $errorContext, $errorAnalysis, $similarCases);
    }

    // 3. Fallback : utiliser le LLM pour raisonnement
    return $this->correctWithLLMReasoning($errorContext, $errorAnalysis, $similarCases);
  }

  /**
   * Applique une correction apprise de l'historique
   */
  private function applyLearnedCorrection(array $errorContext, array $learnedCase): array
  {
    // Adapter la correction apprise au contexte actuel
    $originalQuery = $errorContext['failed_query'];
    $learnedOriginal = $learnedCase['original_query'];
    $learnedCorrected = $learnedCase['corrected_query'];

    // Identifier les transformations appliquées
    $transformations = $this->identifyTransformations($learnedOriginal, $learnedCorrected);

    // Appliquer les mêmes transformations
    $correctedQuery = $this->applyTransformations($originalQuery, $transformations);

    return [
      'query' => $correctedQuery,
      'method' => 'learned_from_history',
      'confidence' => $learnedCase['similarity_score'] * $learnedCase['success_rate'],
      'source_case' => $learnedCase,
      'transformations' => $transformations,
    ];
  }

  /**
   * Identifie les transformations entre deux requêtes
   */
  private function identifyTransformations(string $original, string $corrected): array
  {
    $transformations = [];

    // Transformation 1: Ajout de colonnes au GROUP BY
    if (
      preg_match_all('/GROUP BY\s+(.+?)(?:\s+ORDER BY|\s+HAVING|\s*$)/i', $original, $origMatches) &&
      preg_match_all('/GROUP BY\s+(.+?)(?:\s+ORDER BY|\s+HAVING|\s*$)/i', $corrected, $corrMatches)
    ) {

      $origCols = array_map('trim', explode(',', $origMatches[1][0] ?? ''));
      $corrCols = array_map('trim', explode(',', $corrMatches[1][0] ?? ''));

      $addedCols = array_diff($corrCols, $origCols);

      if (!empty($addedCols)) {
        $transformations[] = [
          'type' => 'add_to_group_by',
          'columns' => array_values($addedCols),
        ];
      }
    }

    // Transformation 2: Remplacement de colonnes
    $origWords = explode(' ', $original);
    $corrWords = explode(' ', $corrected);

    $minCount = min(count($origWords), count($corrWords));
    for ($i = 0; $i < $minCount; $i++) {
      if (
        $origWords[$i] !== $corrWords[$i] &&
        preg_match('/^[a-z_]+$/i', $origWords[$i]) &&
        preg_match('/^[a-z_]+$/i', $corrWords[$i])
      ) {
        $transformations[] = [
          'type' => 'column_replacement',
          'from' => $origWords[$i],
          'to' => $corrWords[$i],
        ];
      }
    }

    // Transformation 3: Ajout de DISTINCT
    if (
      stripos($corrected, 'SELECT DISTINCT') !== false &&
      stripos($original, 'SELECT DISTINCT') === false
    ) {
      $transformations[] = [
        'type' => 'add_distinct',
      ];
    }

    return $transformations;
  }

  /**
   * Applique les transformations identifiées
   */
  private function applyTransformations(string $query, array $transformations): string
  {
    $corrected = $query;

    foreach ($transformations as $transformation) {
      switch ($transformation['type']) {
        case 'add_to_group_by':
          if (preg_match('/GROUP BY\s+(.+?)(?:\s+ORDER BY|\s+HAVING|\s*$)/i', $corrected, $matches)) {
            $currentGroupBy = trim($matches[1]);
            $newColumns = implode(', ', $transformation['columns']);
            $newGroupBy = $currentGroupBy . ', ' . $newColumns;
            $corrected = preg_replace(
              '/GROUP BY\s+' . preg_quote($currentGroupBy, '/') . '/i',
              'GROUP BY ' . $newGroupBy,
              $corrected,
              1
            );
          }
          break;

        case 'column_replacement':
          $corrected = preg_replace(
            '/\b' . preg_quote($transformation['from'], '/') . '\b/i',
            $transformation['to'],
            $corrected,
            1
          );
          break;

        case 'add_distinct':
          $corrected = preg_replace(
            '/SELECT\s+/i',
            'SELECT DISTINCT ',
            $corrected,
            1
          );
          break;
      }
    }

    return $corrected;
  }

  /**
   * Stratégie: Corriger une erreur de syntaxe
   */
  private function correctSyntaxError(
    array $errorContext,
    array $errorAnalysis,
    array $similarCases
  ): array {
    $query = $errorContext['failed_query'];
    $corrected = $query;
    $confidence = 0.6;

    // Correction 1: Virgules consécutives
    if (preg_match('/,\s*,/', $corrected)) {
      $corrected = preg_replace('/,\s*,/', ',', $corrected);
      $confidence += 0.1;
    }

    // Correction 2: Parenthèses déséquilibrées
    $openCount = substr_count($corrected, '(');
    $closeCount = substr_count($corrected, ')');

    if ($openCount > $closeCount) {
      $corrected .= str_repeat(')', $openCount - $closeCount);
      $confidence += 0.1;
    } elseif ($closeCount > $openCount) {
      for ($i = 0; $i < $closeCount - $openCount; $i++) {
        $pos = strrpos($corrected, ')');
        if ($pos !== false) {
          $corrected = substr($corrected, 0, $pos) . substr($corrected, $pos + 1);
        }
      }
      $confidence += 0.1;
    }

    // Correction 3: WHERE AND/OR au début
    $corrected = preg_replace('/\bWHERE\s+(AND|OR)\b/i', 'WHERE', $corrected);

    return [
      'query' => $corrected,
      'method' => 'syntax_correction',
      'confidence' => min($confidence, 0.9),
    ];
  }

  /**
   * Stratégie: Corriger une colonne inconnue
   */
  private function correctUnknownColumn(
    array $errorContext,
    array $errorAnalysis,
    array $similarCases
  ): array {
    $query = $errorContext['failed_query'];
    $unknownColumn = $errorAnalysis['details']['column_name'] ?? '';

    if (empty($unknownColumn)) {
      return $this->correctWithLLMReasoning($errorContext, $errorAnalysis, $similarCases);
    }

    // Rechercher une colonne similaire dans le schéma
    $similarColumn = $this->findSimilarColumnInSchema($unknownColumn);

    if ($similarColumn && $similarColumn !== $unknownColumn) {
      $corrected = str_replace($unknownColumn, $similarColumn, $query);

      return [
        'query' => $corrected,
        'method' => 'column_name_correction',
        'confidence' => 0.8,
        'suggestions' => ["Column '$unknownColumn' replaced with '$similarColumn'"],
      ];
    }

    // Si pas de colonne similaire trouvée, utiliser le LLM
    return $this->correctWithLLMReasoning($errorContext, $errorAnalysis, $similarCases);
  }

  /**
   * Recherche une colonne similaire dans le schéma
   */
  private function findSimilarColumnInSchema(string $columnName): ?string
  {
    // Cette méthode devrait interroger votre schéma DB
    // Pour l'instant, retourne null (à implémenter selon votre architecture)
    return null;
  }

  /**
   * Stratégie: Corriger une table inconnue
   */
  private function correctUnknownTable(
    array $errorContext,
    array $errorAnalysis,
    array $similarCases
  ): array {
    // Similaire à correctUnknownColumn mais pour les tables
    return $this->correctWithLLMReasoning($errorContext, $errorAnalysis, $similarCases);
  }

  /**
   * Stratégie: Corriger une erreur GROUP BY
   */
  private function correctGroupByError(
    array $errorContext,
    array $errorAnalysis,
    array $similarCases
  ): array {
    $query = $errorContext['failed_query'];
    $missingColumn = $errorAnalysis['details']['missing_column'] ?? '';

    if (empty($missingColumn)) {
      return $this->correctWithLLMReasoning($errorContext, $errorAnalysis, $similarCases);
    }

    // Ajouter la colonne manquante au GROUP BY
    if (preg_match('/GROUP BY\s+(.+?)(?:\s+ORDER BY|\s+HAVING|\s*$)/i', $query, $matches)) {
      $currentGroupBy = trim($matches[1]);

      // Vérifier que la colonne n'est pas déjà présente
      if (stripos($currentGroupBy, $missingColumn) === false) {
        $newGroupBy = $currentGroupBy . ', ' . $missingColumn;
        $corrected = preg_replace(
          '/GROUP BY\s+' . preg_quote($currentGroupBy, '/') . '/i',
          'GROUP BY ' . $newGroupBy,
          $query,
          1
        );

        return [
          'query' => $corrected,
          'method' => 'group_by_correction',
          'confidence' => 0.9,
          'suggestions' => ["Added missing column '$missingColumn' to GROUP BY clause"],
        ];
      }
    }

    return $this->correctWithLLMReasoning($errorContext, $errorAnalysis, $similarCases);
  }

  /**
   * Stratégie: Corriger une erreur de JOIN
   */
  private function correctJoinError(
    array $errorContext,
    array $errorAnalysis,
    array $similarCases
  ): array {
    return $this->correctWithLLMReasoning($errorContext, $errorAnalysis, $similarCases);
  }

  /**
   * Stratégie: Corriger une erreur de type
   */
  private function correctTypeMismatch(
    array $errorContext,
    array $errorAnalysis,
    array $similarCases
  ): array {
    return $this->correctWithLLMReasoning($errorContext, $errorAnalysis, $similarCases);
  }

  /**
   * Stratégie: Corriger une erreur sémantique
   */
  private function correctSemanticError(
    array $errorContext,
    array $errorAnalysis,
    array $similarCases
  ): array {
    return $this->correctWithLLMReasoning($errorContext, $errorAnalysis, $similarCases);
  }

  /**
   * Correction avec raisonnement LLM (Chain-of-Thought)
   *
   * @param array $errorContext Contexte de l'erreur
   * @param array $errorAnalysis Analyse de l'erreur
   * @param array $similarCases Cas similaires
   * @return array Correction proposée
   */
  private function correctWithLLMReasoning(array $errorContext, array $errorAnalysis, array $similarCases): array
  {
    try {
      // Construire le prompt avec Chain-of-Thought
      $prompt = $this->buildReasoningPrompt($errorContext, $errorAnalysis, $similarCases);

      // Générer la correction avec raisonnement
      $response = Gpt::getGptResponse($prompt, 500);


      // Parser la réponse
      $parsed = $this->parseReasoningResponse($response);

      return [
        'query' => $parsed['corrected_query'] ?? $errorContext['failed_query'],
        'method' => 'llm_reasoning',
        'confidence' => $parsed['confidence'] ?? 0.5,
        'reasoning' => $parsed['reasoning'] ?? '',
        'suggestions' => $parsed['suggestions'] ?? [],
      ];

    } catch (\Exception $e) {
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "LLM reasoning correction failed: " . $e->getMessage(),
          'error'
        );
      }

      return [
        'query' => $errorContext['failed_query'],
        'method' => 'llm_reasoning_failed',
        'confidence' => 0.0,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Construit le prompt de raisonnement pour le LLM
   */
  private function buildReasoningPrompt(
    array $errorContext,
    array $errorAnalysis,
    array $similarCases
  ): string {
    $parts = [];

    $parts[] = "You are an expert SQL debugging assistant. Analyze and fix this SQL error using step-by-step reasoning.";
    $parts[] = "";
    $parts[] = "## Error Context";
    $parts[] = "Error Type: " . $errorAnalysis['type'];
    $parts[] = "Error Message: " . $errorContext['error_message'];
    $parts[] = "Failed Query:";
    $parts[] = "```sql";
    $parts[] = $errorContext['failed_query'];
    $parts[] = "```";

    if (!empty($errorContext['original_query'])) {
      $parts[] = "";
      $parts[] = "Original User Question: " . $errorContext['original_query'];
    }

    if (!empty($similarCases)) {
      $parts[] = "";
      $parts[] = "## Similar Cases from History";
      foreach (array_slice($similarCases, 0, 2) as $i => $case) {
        $parts[] = "Case " . ($i + 1) . ":";
        $parts[] = "- Original Error: " . $case['original_error'];
        $parts[] = "- Correction Applied: " . $case['correction_method'];
        $parts[] = "- Similarity: " . round($case['similarity_score'] * 100, 1) . "%";
      }
    }

    $parts[] = "";
    $parts[] = "## Your Task";
    $parts[] = "Analyze this error step by step:";
    $parts[] = "1. **Understand**: What is the root cause of this error?";
    $parts[] = "2. **Plan**: What changes are needed to fix it?";
    $parts[] = "3. **Apply**: Generate the corrected SQL query";
    $parts[] = "4. **Validate**: Check if the correction makes sense";
    $parts[] = "";
    $parts[] = "Respond in this format:";
    $parts[] = "REASONING: <your step-by-step analysis>";
    $parts[] = "CORRECTED_QUERY: <the fixed SQL query>";
    $parts[] = "CONFIDENCE: <0.0 to 1.0>";
    $parts[] = "SUGGESTIONS: <optional improvement suggestions>";

    return implode("\n", $parts);
  }

  /**
   * Parse la réponse de raisonnement du LLM
   */
  private function parseReasoningResponse(string $response): array
  {
    $parsed = [];

    // Extraire le raisonnement
    if (preg_match('/REASONING:\s*(.+?)(?=CORRECTED_QUERY:|$)/is', $response, $matches)) {
      $parsed['reasoning'] = trim($matches[1]);
    }

    // Extraire la requête corrigée
    if (preg_match('/CORRECTED_QUERY:\s*```?sql?\s*(.+?)\s*```?/is', $response, $matches)) {
      $parsed['corrected_query'] = trim($matches[1]);
    } elseif (preg_match('/CORRECTED_QUERY:\s*(.+?)(?=CONFIDENCE:|SUGGESTIONS:|$)/is', $response, $matches)) {
      $parsed['corrected_query'] = trim($matches[1]);
    }

    // Extraire la confiance
    if (preg_match('/CONFIDENCE:\s*([\d\.]+)/i', $response, $matches)) {
      $parsed['confidence'] = (float) $matches[1];
    }

    // Extraire les suggestions
    if (preg_match('/SUGGESTIONS:\s*(.+?)$/is', $response, $matches)) {
      $suggestions = trim($matches[1]);
      $parsed['suggestions'] = array_filter(explode("\n", $suggestions));
    }

    return $parsed;
  }

  /**
   * Valide la correction proposée
   *
   * @param array $correction Correction à valider
   * @return array Résultat de validation
   */
  private function validateCorrection(array $correction): array
  {
    $validation = [
      'is_valid' => true,
      'issues' => [],
      'warnings' => [],
    ];

    $query = $correction['query'] ?? '';

    if (empty($query)) {
      $validation['is_valid'] = false;
      $validation['issues'][] = "Corrected query is empty";
      return $validation;
    }

    // Validation 1: Syntaxe SQL basique
    $syntaxCheck = InputValidator::validateSqlQuery($query);
    if (!$syntaxCheck['valid']) {
      $validation['is_valid'] = false;
      $validation['issues'] = array_merge(
        $validation['issues'],
        $syntaxCheck['issues']
      );
    }

    // Validation 2: Parenthèses équilibrées
    $openCount = substr_count($query, '(');
    $closeCount = substr_count($query, ')');
    if ($openCount !== $closeCount) {
      $validation['is_valid'] = false;
      $validation['issues'][] = "Unbalanced parentheses: $openCount open vs $closeCount close";
    }

    // Validation 3: Clauses SQL cohérentes
    if (stripos($query, 'SELECT') === 0 && stripos($query, 'FROM') === false) {
      $validation['is_valid'] = false;
      $validation['issues'][] = "SELECT query missing FROM clause";
    }

    // Validation 4: Confiance suffisante
    if (isset($correction['confidence']) && $correction['confidence'] < $this->confidenceThreshold) {
      $validation['warnings'][] = "Low confidence correction: " . $correction['confidence'];
    }

    return $validation;
  }

  /**
   * Mémorise une correction réussie pour apprentissage futur
   *
   * @param array $errorContext Contexte de l'erreur originale
   * @param array $correction Correction appliquée
   * @param array $errorAnalysis Analyse de l'erreur
   */
  private function memorizeSuccessfulCorrection(
    array $errorContext,
    array $correction,
    array $errorAnalysis
  ): void {
    try {
      // Extract entity_id and entity_type from error context if available
      $entityInfo = $this->extractEntityInfoFromContext($errorContext, $correction);
      $entityId = $entityInfo['entity_id'];
      $entityType = $entityInfo['entity_type'];
      
      // Validate entity_id before memorization
      if ($entityId === null) {
        // Log warning but continue - we'll use a default value
        $this->securityLogger->logSecurityEvent(
          "Cannot extract entity_id for correction memorization. Query: " . 
          substr($errorContext['failed_query'] ?? 'N/A', 0, 200),
          'warning',
          [
            'error_type' => $errorAnalysis['type'],
            'correction_method' => $correction['method'],
            'original_query' => $errorContext['original_query'] ?? 'N/A'
          ]
        );
        
        // Use default entity_id of 0 to indicate "no specific entity"
        $entityId = 0;
        $entityType = null;
      }
      
      // Créer un document pour le pattern de correction
      $document = new Document();
      $document->content = $this->createCorrectionPatternContent(
        $errorContext,
        $correction,
        $errorAnalysis
      );
      $document->sourceType = 'correction_pattern';
      $document->sourceName = 'learned_correction';

      // Métadonnées enrichies
      $document->metadata = [
        'type' => 'correction_pattern',
        'error_type' => $errorAnalysis['type'],
        'correction_method' => $correction['method'],
        'confidence' => $correction['confidence'] ?? 0.5,
        'original_query' => $errorContext['failed_query'],
        'corrected_query' => $correction['query'],
        'original_error' => $errorContext['error_message'],
        'correction_successful' => true,
        'timestamp' => time(),
        'user_id' => $this->userId,
        'language_id' => $this->languageId,
        'success_rate' => 1.0, // Sera mis à jour avec le temps
        'entity_id' => $entityId, // Add entity_id to metadata
        'entity_type' => $entityType, // Add entity_type to metadata
      ];

      // Stocker dans le vector store
      $this->correctionStore->addDocument($document);

      $this->learningStats['learned_patterns']++;

      if ($this->debug) {
        $entityTypeStr = $entityType ? " ({$entityType})" : '';
        $this->securityLogger->logSecurityEvent(
          "Correction pattern memorized: " . $errorAnalysis['type'] . 
          " (entity_id: {$entityId}{$entityTypeStr})",
          'info'
        );
      }

    } catch (\Exception $e) {
      // Log error but don't throw - memorization failure shouldn't break correction flow
      $this->securityLogger->logSecurityEvent(
        "Error memorizing correction: " . $e->getMessage(),
        'error',
        [
          'error_type' => $errorAnalysis['type'],
          'correction_method' => $correction['method'],
          'stack_trace' => $e->getTraceAsString()
        ]
      );
      
      if ($this->debug) {
        error_log("CorrectionAgent: Failed to memorize correction - " . $e->getMessage());
      }
    }
  }
  
  /**
   * Extracts entity_id and entity_type from error context or correction results
   * 
   * Attempts to extract entity information from:
   * 1. Correction results (if query was re-executed)
   * 2. Original error context
   * 3. Query analysis (parsing SQL for entity references)
   *
   * @param array $errorContext Error context containing query and results
   * @param array $correction Correction data that may contain results
   * @return array Array with 'entity_id' and 'entity_type' keys
   */
  private function extractEntityInfoFromContext(array $errorContext, array $correction): array
  {
    // Method 1: Check if correction contains results with entity_id
    if (isset($correction['results']) && !empty($correction['results'])) {
      $entityInfo = $this->extractEntityIdFromResults($correction['results']);
      if ($entityInfo['entity_id'] !== null) {
        return $entityInfo;
      }
    }
    
    // Method 2: Check if error context contains results
    if (isset($errorContext['results']) && !empty($errorContext['results'])) {
      $entityInfo = $this->extractEntityIdFromResults($errorContext['results']);
      if ($entityInfo['entity_id'] !== null) {
        return $entityInfo;
      }
    }
    
    // Method 3: Try to extract from SQL query (parse for WHERE id = X patterns)
    $query = $correction['query'] ?? $errorContext['failed_query'] ?? '';
    if (!empty($query)) {
      $entityId = $this->extractEntityIdFromQuery($query);
      if ($entityId !== null) {
        // Try to determine entity_type from query
        $entityType = $this->extractEntityTypeFromQuery($query);
        return [
          'entity_id' => $entityId,
          'entity_type' => $entityType
        ];
      }
    }
    
    // Method 4: Check if entity_id is explicitly provided in context
    if (isset($errorContext['entity_id']) && $errorContext['entity_id'] !== null) {
      return [
        'entity_id' => (int) $errorContext['entity_id'],
        'entity_type' => $errorContext['entity_type'] ?? null
      ];
    }
    
    // No entity_id found
    return [
      'entity_id' => null,
      'entity_type' => null
    ];
  }
  
  /**
   * Extracts entity_id from query results using centralized EntityRegistry
   * 
   * @param array $results Query results array
   * @return array Array with 'entity_id' and 'entity_type' keys
   */
  private function extractEntityIdFromResults(array $results): array
  {
    $entityId = null;
    $entityType = null;

    if (empty($results)) {
      return ['entity_id' => $entityId, 'entity_type' => $entityType];
    }

    $firstRow = $results[0];

    // Use centralized EntityRegistry for ID column mappings
    $registry = EntityRegistry::getInstance();
    $idColumnNames = $registry->getIdColumnMappings();

    foreach ($idColumnNames as $idCol => $type) {
      if (isset($firstRow[$idCol]) && !empty($firstRow[$idCol])) {
        $entityId = (int) $firstRow[$idCol];
        $entityType = $type;
        break;
      }
    }

    return [
      'entity_id' => $entityId,
      'entity_type' => $entityType,
    ];
  }
  
  /**
   * Attempts to extract entity_id from SQL query by parsing WHERE clauses
   * 
   * Looks for patterns like:
   * - WHERE products_id = 123
   * - WHERE id = 456
   * - WHERE p.products_id = 789
   * 
   * @param string $query SQL query to parse
   * @return int|null Entity ID if found, null otherwise
   */
  private function extractEntityIdFromQuery(string $query): ?int
  {
    // Pattern 1: WHERE {table}_id = {number}
    if (preg_match('/WHERE\s+(?:\w+\.)?(\w+_id)\s*=\s*(\d+)/i', $query, $matches)) {
      return (int) $matches[2];
    }
    
    // Pattern 2: WHERE id = {number}
    if (preg_match('/WHERE\s+(?:\w+\.)?id\s*=\s*(\d+)/i', $query, $matches)) {
      return (int) $matches[1];
    }
    
    // Pattern 3: AND {table}_id = {number}
    if (preg_match('/AND\s+(?:\w+\.)?(\w+_id)\s*=\s*(\d+)/i', $query, $matches)) {
      return (int) $matches[2];
    }
    
    // Pattern 4: AND id = {number}
    if (preg_match('/AND\s+(?:\w+\.)?id\s*=\s*(\d+)/i', $query, $matches)) {
      return (int) $matches[1];
    }
    
    return null;
  }
  
  /**
   * Attempts to extract entity_type from SQL query by analyzing table names
   * 
   * Uses centralized EntityRegistry to dynamically discover entity types from table names.
   * 
   * Looks for patterns like:
   * - FROM products WHERE ...
   * - FROM categories WHERE ...
   * - JOIN pages_manager ON ...
   * 
   * @param string $query SQL query to parse
   * @return string|null Entity type if found, null otherwise
   */
  private function extractEntityTypeFromQuery(string $query): ?string
  {
    // Use centralized EntityRegistry for dynamic entity type discovery
    $registry = EntityRegistry::getInstance();
    $allTables = $registry->getAllEntityTables();
    
    // Get table prefix dynamically
    $prefix = CLICSHOPPING::getConfig('db_table_prefix');
    
    // Build a mapping of table names (without prefix) to entity types
    $tableToEntityType = [];
    foreach ($allTables as $fullTableName) {
      $entityType = $registry->getEntityTypeForTable($fullTableName);
      // Remove prefix and _embedding suffix to get base table name
      $tableName = str_replace([$prefix, '_embedding'], '', $fullTableName);
      $tableToEntityType[$tableName] = $entityType;
    }
    
    // Pattern 1: FROM {table_name}
    if (preg_match('/FROM\s+(?:\w+\.)?(\w+)/i', $query, $matches)) {
      $tableName = strtolower($matches[1]);
      // Remove table prefix if present
      $tableName = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $tableName);
      
      if (isset($tableToEntityType[$tableName])) {
        return $tableToEntityType[$tableName];
      }
    }
    
    // Pattern 2: JOIN {table_name}
    if (preg_match('/JOIN\s+(?:\w+\.)?(\w+)/i', $query, $matches)) {
      $tableName = strtolower($matches[1]);
      // Remove table prefix if present
      $tableName = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $tableName);
      
      if (isset($tableToEntityType[$tableName])) {
        return $tableToEntityType[$tableName];
      }
    }
    
    // Pattern 3: {table}_id column name
    if (preg_match('/(?:WHERE|AND)\s+(?:\w+\.)?(\w+)_id\s*=/i', $query, $matches)) {
      $entityName = strtolower($matches[1]);
      
      if (isset($tableToEntityType[$entityName])) {
        return $tableToEntityType[$entityName];
      }
    }
    
    return null;
  }

  /**
   * Crée le contenu du pattern de correction pour stockage
   */
  private function createCorrectionPatternContent(
    array $errorContext,
    array $correction,
    array $errorAnalysis
  ): string {
    $parts = [];

    $parts[] = "Error Pattern:";
    $parts[] = "Type: " . $errorAnalysis['type'];
    $parts[] = "Error: " . $errorContext['error_message'];
    $parts[] = "";
    $parts[] = "Original Query:";
    $parts[] = $errorContext['failed_query'];
    $parts[] = "";
    $parts[] = "Correction Applied:";
    $parts[] = "Method: " . $correction['method'];
    $parts[] = "Corrected Query:";
    $parts[] = $correction['query'];

    if (!empty($correction['reasoning'])) {
      $parts[] = "";
      $parts[] = "Reasoning:";
      $parts[] = $correction['reasoning'];
    }

    return implode("\n", $parts);
  }

  /**
   * Génère des suggestions de fallback si la correction échoue
   */
  private function generateFallbackSuggestions(array $errorContext): array
  {
    $suggestions = [];

    $errorMessage = $errorContext['error_message'] ?? '';

    if (stripos($errorMessage, 'column') !== false) {
      $suggestions[] = "Check column names for typos";
      $suggestions[] = "Verify table aliases are correct";
      $suggestions[] = "Ensure all columns are in the SELECT or GROUP BY clause";
    }

    if (stripos($errorMessage, 'table') !== false) {
      $suggestions[] = "Verify table name spelling";
      $suggestions[] = "Check if table exists in the database";
      $suggestions[] = "Ensure proper table prefix if applicable";
    }

    if (stripos($errorMessage, 'syntax') !== false) {
      $suggestions[] = "Check for missing or extra commas";
      $suggestions[] = "Verify parentheses are balanced";
      $suggestions[] = "Review SQL clause order (SELECT, FROM, WHERE, GROUP BY, ORDER BY)";
    }

    $suggestions[] = "Try simplifying the query to identify the issue";
    $suggestions[] = "Review the original question to ensure SQL aligns with intent";

    return $suggestions;
  }

  /**
   * Met à jour l'accuracy de correction
   */
  private function updateAccuracy(): void
  {
    $total = $this->learningStats['successful_corrections'] + $this->learningStats['failed_corrections'];

    if ($total > 0) {
      $this->learningStats['correction_accuracy'] =
        $this->learningStats['successful_corrections'] / $total;
    }

    // Sauvegarder les stats périodiquement
    if ($total % 10 === 0) {
      $this->saveLearningStats();
    }
  }

  /**
   * Charge les statistiques d'apprentissage depuis le cache
   */
  private function loadLearningStats(): void
  {
    $cacheKey = "correction_agent_stats_{$this->userId}";
    $cached = $this->cache->getCachedResponse($cacheKey);

    if ($cached !== null) {
      $decoded = json_decode($cached, true);
      if (is_array($decoded)) {
        $this->learningStats = array_merge($this->learningStats, $decoded);
      }
    }
  }

  /**
   * Sauvegarde les statistiques d'apprentissage dans le cache
   */
  private function saveLearningStats(): void
  {
    $cacheKey = "correction_agent_stats_{$this->userId}";
    $encoded = json_encode($this->learningStats);
    $this->cache->cacheResponse($cacheKey, $encoded, 86400); // 24h

    if ($this->debug) {
      $this->securityLogger->logSecurityEvent(
        "Learning stats saved: " . json_encode($this->learningStats),
        'info'
      );
    }
  }

  /**
   * Obtient les statistiques d'apprentissage
   *
   * @return array Statistiques
   */
  public function getLearningStats(): array
  {
    return $this->learningStats;
  }

  /**
   * Réinitialise les statistiques d'apprentissage
   */
  public function resetLearningStats(): void
  {
    $this->learningStats = [
      'total_errors' => 0,
      'successful_corrections' => 0,
      'failed_corrections' => 0,
      'learned_patterns' => 0,
      'correction_accuracy' => 0.0,
    ];

    $this->saveLearningStats();
  }

  /**
   * Configure le seuil de confiance
   *
   * @param float $threshold Seuil (0-1)
   */
  public function setConfidenceThreshold(float $threshold): void
  {
    $this->confidenceThreshold = max(0.0, min(1.0, $threshold));
  }

  /**
   * Obtient un rapport détaillé sur l'apprentissage
   *
   * @return array Rapport
   */
  public function getLearningReport(): array
  {
    $totalErrors = $this->learningStats['total_errors'];
    $successfulCorrections = $this->learningStats['successful_corrections'];
    $failedCorrections = $this->learningStats['failed_corrections'];

    return [
      'overview' => [
        'total_errors_processed' => $totalErrors,
        'successful_corrections' => $successfulCorrections,
        'failed_corrections' => $failedCorrections,
        'correction_accuracy' => round($this->learningStats['correction_accuracy'] * 100, 2) . '%',
        'learned_patterns' => $this->learningStats['learned_patterns'],
      ],
      'performance' => [
        'success_rate' => $totalErrors > 0
          ? round(($successfulCorrections / $totalErrors) * 100, 2) . '%'
          : 'N/A',
        'learning_efficiency' => $successfulCorrections > 0
          ? round($this->learningStats['learned_patterns'] / $successfulCorrections, 2)
          : 0,
      ],
      'recommendations' => $this->generateRecommendations(),
    ];
  }

  /**
   * Génère des recommandations basées sur les stats
   */
  private function generateRecommendations(): array
  {
    $recommendations = [];
    $accuracy = $this->learningStats['correction_accuracy'];

    if ($accuracy < 0.5) {
      $recommendations[] = "Low correction accuracy. Consider reviewing correction strategies.";
    } elseif ($accuracy < 0.7) {
      $recommendations[] = "Moderate correction accuracy. System is learning but could improve.";
    } else {
      $recommendations[] = "Good correction accuracy. System is learning effectively.";
    }

    $learnedPatterns = $this->learningStats['learned_patterns'];
    if ($learnedPatterns < 10) {
      $recommendations[] = "Limited learned patterns. More corrections needed for better performance.";
    } elseif ($learnedPatterns < 50) {
      $recommendations[] = "Building knowledge base. Continue processing errors to improve.";
    } else {
      $recommendations[] = "Substantial knowledge base acquired. System can handle many error types.";
    }

    return $recommendations;
  }

  /**
   * Apprend des feedbacks utilisateur pour améliorer les corrections
   * 
   * Cette méthode analyse les feedbacks négatifs pour identifier les patterns d'erreur,
   * extrait les corrections réussies et met à jour les stratégies de correction.
   * 
   * @param int $limit Nombre maximum de feedbacks à analyser
   * @return array Résultats de l'apprentissage
   */
  public function learnFromFeedback(int $limit = 100): array
  {
    try {
      // 1. Récupérer les feedbacks négatifs et corrections récents
      // 🔧 TASK 4.4.1 PHASE 2: Use DoctrineOrm instead of direct DB access
      $prefix = CLICSHOPPING::getConfig('db_table_prefix');
      
      $sql = "SELECT f.id,
                f.interaction_id,
                f.feedback_type,
                f.feedback_data,
                f.timestamp,
                f.user_id
              FROM {$prefix}rag_feedback f
              WHERE f.feedback_type IN ('negative', 'correction')
              ORDER BY f.timestamp DESC
              LIMIT :limit
            ";
      
      $feedbacks = \ClicShopping\AI\Infrastructure\Orm\DoctrineOrm::select($sql, ['limit' => $limit]);
      
      if (empty($feedbacks)) {
        return [
          'success' => true,
          'feedbacks_analyzed' => 0,
          'patterns_identified' => 0,
          'corrections_learned' => 0,
          'message' => 'No feedbacks to analyze'
        ];
      }
      
      // 2. Analyser les feedbacks
      $patternsIdentified = [];
      $correctionsLearned = 0;
      $improvementRate = 0;
      
      foreach ($feedbacks as $feedback) {
        $feedbackData = json_decode($feedback['feedback_data'], true);
        
        if ($feedback['feedback_type'] === 'negative') {
          // Analyser les feedbacks négatifs pour identifier les patterns d'erreur
          $pattern = $this->identifyErrorPattern($feedback, $feedbackData);
          if ($pattern) {
            $patternsIdentified[] = $pattern;
          }
        } elseif ($feedback['feedback_type'] === 'correction') {
          // Extraire et stocker les corrections réussies
          $success = $this->storeCorrectionPattern($feedback, $feedbackData);
          if ($success) {
            $correctionsLearned++;
          }
        }
      }
      
      // 3. Mettre à jour les stratégies de correction basées sur les patterns
      if (!empty($patternsIdentified)) {
        $this->updateCorrectionStrategies($patternsIdentified);
      }
      
      // 4. Calculer le taux d'amélioration
      $improvementRate = $this->calculateImprovementRate($feedbacks);
      
      // 5. Mettre à jour les statistiques
      $this->learningStats['learned_patterns'] += $correctionsLearned;
      $this->saveLearningStats();
      
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Learned from " . count($feedbacks) . " feedbacks: " . 
          $correctionsLearned . " corrections, " . 
          count($patternsIdentified) . " patterns",
          'info'
        );
      }
      
      return [
        'success' => true,
        'feedbacks_analyzed' => count($feedbacks),
        'patterns_identified' => count($patternsIdentified),
        'corrections_learned' => $correctionsLearned,
        'improvement_rate' => round($improvementRate, 2),
        'patterns' => array_slice($patternsIdentified, 0, 10), // Top 10 patterns
        'message' => "Successfully learned from {$correctionsLearned} corrections"
      ];
      
    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Error learning from feedback: " . $e->getMessage(),
        'error'
      );
      
      return [
        'success' => false,
        'error' => $e->getMessage(),
        'feedbacks_analyzed' => 0,
        'patterns_identified' => 0,
        'corrections_learned' => 0
      ];
    }
  }
  
  /**
   * Identifie un pattern d'erreur depuis un feedback négatif
   * 
   * @param array $feedback Données du feedback
   * @param array $feedbackData Données JSON décodées
   * @return array|null Pattern identifié ou null
   */
  private function identifyErrorPattern(array $feedback, array $feedbackData): ?array
  {
    try {
      // Extraire les informations pertinentes
      $reason = $feedbackData['reason'] ?? 'unknown';
      $comment = $feedbackData['comment'] ?? '';
      $rating = $feedbackData['rating'] ?? 0;
      
      // Identifier le type d'erreur basé sur le feedback
      $errorType = $this->classifyErrorFromFeedback($reason, $comment);
      
      if (!$errorType) {
        return null;
      }
      
      return [
        'error_type' => $errorType,
        'frequency' => 1,
        'severity' => $this->calculateSeverity($rating),
        'user_feedback' => $comment,
        'interaction_id' => $feedback['interaction_id'],
        'timestamp' => $feedback['timestamp']
      ];
      
    } catch (\Exception $e) {
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Error identifying pattern: " . $e->getMessage(),
          'error'
        );
      }
      return null;
    }
  }
  
  /**
   * Stocke un pattern de correction depuis un feedback de correction
   * 
   * @param array $feedback Données du feedback
   * @param array $feedbackData Données JSON décodées
   * @return bool Succès du stockage
   */
  private function storeCorrectionPattern(array $feedback, array $feedbackData): bool
  {
    try {
      $originalResponse = $feedbackData['original_response'] ?? '';
      $correctedResponse = $feedbackData['corrected_response'] ?? '';
      $correctionType = $feedbackData['correction_type'] ?? 'general';
      
      if (empty($originalResponse) || empty($correctedResponse)) {
        return false;
      }
      
      // Extract entity_id and entity_type from feedback data if available
      $entityId = $feedbackData['entity_id'] ?? null;
      $entityType = $feedbackData['entity_type'] ?? null;
      
      // If no entity_id in feedback, try to extract from interaction
      if ($entityId === null && isset($feedback['interaction_id'])) {
        $entityInfo = $this->extractEntityInfoFromInteraction($feedback['interaction_id']);
        $entityId = $entityInfo['entity_id'];
        $entityType = $entityInfo['entity_type'];
      }
      
      // Validate entity_id - use default if null
      if ($entityId === null) {
        $this->securityLogger->logSecurityEvent(
          "Cannot extract entity_id for feedback correction. Interaction: " . 
          ($feedback['interaction_id'] ?? 'N/A'),
          'warning',
          [
            'correction_type' => $correctionType,
            'user_id' => $feedback['user_id'] ?? 'N/A'
          ]
        );
        
        // Use default entity_id of 0 to indicate "no specific entity"
        $entityId = 0;
        $entityType = null;
      }
      
      // Créer un document pour le vector store
      $content = $this->createCorrectionContent($originalResponse, $correctedResponse, $correctionType);
      
      $document = new Document();
      $document->content = $content;
      $document->sourceType = 'user_correction';
      $document->sourceName = 'feedback_system';
      
      $document->metadata = [
        'type' => 'correction_pattern',
        'correction_type' => $correctionType,
        'original_response' => substr($originalResponse, 0, 500),
        'corrected_response' => substr($correctedResponse, 0, 500),
        'interaction_id' => $feedback['interaction_id'],
        'user_id' => $feedback['user_id'],
        'timestamp' => $feedback['timestamp'],
        'language_id' => $this->languageId,
        'correction_successful' => true,
        'success_rate' => 1.0,
        'entity_id' => $entityId, // Add entity_id to metadata
        'entity_type' => $entityType, // Add entity_type to metadata
      ];
      
      // Stocker dans le vector store
      $this->correctionStore->addDocument($document);
      
      return true;
      
    } catch (\Exception $e) {
      // Log error but don't throw - feedback storage failure shouldn't break the system
      $this->securityLogger->logSecurityEvent(
        "Error storing correction pattern: " . $e->getMessage(),
        'error',
        [
          'interaction_id' => $feedback['interaction_id'] ?? 'N/A',
          'correction_type' => $correctionType ?? 'N/A',
          'stack_trace' => $e->getTraceAsString()
        ]
      );
      
      if ($this->debug) {
        error_log("CorrectionAgent: Failed to store correction pattern - " . $e->getMessage());
      }
      
      return false;
    }
  }
  
  /**
   * Extracts entity_id and entity_type from an interaction by querying the interactions table
   * 
   * @param string $interactionId Interaction ID to look up
   * @return array Array with 'entity_id' and 'entity_type' keys
   */
  private function extractEntityInfoFromInteraction(string $interactionId): array
  {
    try {
      // Query rag_statistics table for entity information
      // 🔧 TASK 4.4.1 PHASE 2: Use DoctrineOrm instead of direct DB access
      $prefix = CLICSHOPPING::getConfig('db_table_prefix');
      
      $sql = "SELECT entity_id, 
                entity_type
              FROM {$prefix}rag_statistics
              WHERE interaction_id = :interaction_id
              LIMIT 1
            ";
      
      $result = \ClicShopping\AI\Infrastructure\Orm\DoctrineOrm::selectOne($sql, ['interaction_id' => $interactionId]);
      
      if ($result && isset($result['entity_id']) && $result['entity_id'] !== null) {
        return [
          'entity_id' => (int) $result['entity_id'],
          'entity_type' => $result['entity_type'] ?? null
        ];
      }
      
      return [
        'entity_id' => null,
        'entity_type' => null
      ];
      
    } catch (\Exception $e) {
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Error extracting entity info from interaction: " . $e->getMessage(),
          'error'
        );
      }
      return [
        'entity_id' => null,
        'entity_type' => null
      ];
    }
  }
  
  /**
   * Crée le contenu textuel d'une correction pour le vector store
   */
  private function createCorrectionContent(string $original, string $corrected, string $type): string
  {
    $parts = [];
    $parts[] = "Correction Type: " . $type;
    $parts[] = "";
    $parts[] = "Original Response:";
    $parts[] = $original;
    $parts[] = "";
    $parts[] = "Corrected Response:";
    $parts[] = $corrected;
    $parts[] = "";
    $parts[] = "Transformation: User-provided correction";
    
    return implode("\n", $parts);
  }
  
  /**
   * Classifie le type d'erreur depuis un feedback utilisateur
   */
  private function classifyErrorFromFeedback(string $reason, string $comment): ?string
  {
    $keywords = [
      'factual_error' => ['wrong', 'incorrect', 'false', 'error', 'mistake'],
      'incomplete' => ['incomplete', 'missing', 'partial', 'not enough'],
      'unclear' => ['unclear', 'confusing', 'ambiguous', 'vague'],
      'irrelevant' => ['irrelevant', 'off-topic', 'not related', 'wrong answer'],
      'formatting' => ['format', 'structure', 'layout', 'presentation']
    ];
    
    $text = strtolower($reason . ' ' . $comment);
    
    foreach ($keywords as $type => $words) {
      foreach ($words as $word) {
        if (strpos($text, $word) !== false) {
          return $type;
        }
      }
    }
    
    return 'general_error';
  }
  
  /**
   * Calcule la sévérité d'une erreur basée sur le rating
   */
  private function calculateSeverity(int $rating): string
  {
    if ($rating <= 1) {
      return 'critical';
    } elseif ($rating <= 2) {
      return 'high';
    } elseif ($rating <= 3) {
      return 'medium';
    } else {
      return 'low';
    }
  }
  
  /**
   * Met à jour les stratégies de correction basées sur les patterns identifiés
   */
  private function updateCorrectionStrategies(array $patterns): void
  {
    // Grouper les patterns par type
    $groupedPatterns = [];
    foreach ($patterns as $pattern) {
      $type = $pattern['error_type'];
      if (!isset($groupedPatterns[$type])) {
        $groupedPatterns[$type] = [];
      }
      $groupedPatterns[$type][] = $pattern;
    }
    
    // Mettre à jour les seuils de confiance pour chaque type
    foreach ($groupedPatterns as $type => $typePatterns) {
      $avgSeverity = $this->calculateAverageSeverity($typePatterns);
      
      // Ajuster les stratégies en fonction de la sévérité
      if ($avgSeverity === 'critical' || $avgSeverity === 'high') {
        // Augmenter la prudence pour ce type d'erreur
        if ($this->debug) {
          $this->securityLogger->logSecurityEvent(
            "Increased caution for error type: $type (severity: $avgSeverity)",
            'info'
          );
        }
      }
    }
  }
  
  /**
   * Calcule la sévérité moyenne d'un groupe de patterns
   */
  private function calculateAverageSeverity(array $patterns): string
  {
    $severityScores = [
      'critical' => 4,
      'high' => 3,
      'medium' => 2,
      'low' => 1
    ];
    
    $totalScore = 0;
    foreach ($patterns as $pattern) {
      $totalScore += $severityScores[$pattern['severity']] ?? 1;
    }
    
    $avgScore = $totalScore / count($patterns);
    
    if ($avgScore >= 3.5) return 'critical';
    if ($avgScore >= 2.5) return 'high';
    if ($avgScore >= 1.5) return 'medium';
    return 'low';
  }
  
  /**
   * Calcule le taux d'amélioration basé sur les feedbacks
   */
  private function calculateImprovementRate(array $feedbacks): float
  {
    if (empty($feedbacks)) {
      return 0.0;
    }
    
    // Compter les feedbacks positifs vs négatifs dans le temps
    $positiveCount = 0;
    $negativeCount = 0;
    
    foreach ($feedbacks as $feedback) {
      if ($feedback['feedback_type'] === 'positive') {
        $positiveCount++;
      } elseif ($feedback['feedback_type'] === 'negative') {
        $negativeCount++;
      }
    }
    
    $total = $positiveCount + $negativeCount;
    if ($total === 0) {
      return 0.0;
    }
    
    return ($positiveCount / $total) * 100;
  }
}