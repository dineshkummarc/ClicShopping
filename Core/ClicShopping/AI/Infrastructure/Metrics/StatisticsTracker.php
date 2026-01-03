<?php
/**
 * StatisticsTracker
 * 
 * Enregistre automatiquement les statistiques de performance pour le dashboard
 * Intègre avec OrchestratorAgent et autres agents pour tracking transparent
 */

namespace ClicShopping\AI\Infrastructure\Metrics;

use ClicShopping\AI\Infrastructure\Orm\DoctrineOrm;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;

/**
 * StatisticsTracker Class
 * 
 * 🔧 MIGRATED TO DOCTRINEORM: December 6, 2025
 * All database queries now use DoctrineOrm instead of PDO
 */
class StatisticsTracker
{
  private $db;
  private string $prefix;
  private $startTime;
  private $interactionId;
  private $userId;
  private $sessionId;
  private $languageId;
  
  // Métriques collectées
  private $metrics = [
    'response_time_ms' => null,
    'api_provider' => null,
    'model_used' => null,
    'tokens_prompt' => null,
    'tokens_completion' => null,
    'tokens_total' => null,
    'api_cost_usd' => null,
    'agent_type' => null,
    'classification_type' => null,
    'confidence_score' => null,
    'security_score' => null,
    'response_quality' => null,
    'cache_hit' => false,
    'error_occurred' => false,
    'error_type' => null,
    'error_message' => null
  ];
  
  /**
   * Constructeur
   * 
   * @param int|null $userId ID utilisateur
   * @param string|null $sessionId ID session
   * @param int|null $languageId ID langue
   */
  public function __construct(?int $userId = null, ?string $sessionId = null, ?int $languageId = null)
  {
    $this->db = Registry::get('Db');
    $this->prefix = CLICSHOPPING::getConfig('db_table_prefix');
    $this->userId = $userId ?? 1;
    $this->sessionId = $sessionId ?? session_id();
    $this->languageId = $languageId ?? Registry::get('Language')->getId();
  }
  
  /**
   * Démarre le tracking d'une interaction
   * 
   * @return void
   */
  public function startTracking(): void
  {
    $this->startTime = microtime(true);
  }
  
  /**
   * Arrête le tracking et calcule le temps de réponse
   * 
   * @return int Temps de réponse en millisecondes
   */
  public function stopTracking(): int
  {
    if ($this->startTime === null) {
      return 0;
    }
    
    $endTime = microtime(true);
    $responseTime = ($endTime - $this->startTime) * 1000; // Convertir en ms
    $this->metrics['response_time_ms'] = (int)round($responseTime);
    
    return $this->metrics['response_time_ms'];
  }
  
  /**
   * Définit l'ID de l'interaction
   * 
   * @param int $interactionId ID de l'interaction
   * @return self
   */
  public function setInteractionId(int $interactionId): self
  {
    $this->interactionId = $interactionId;
    return $this;
  }
  
  /**
   * Définit le type d'agent
   * 
   * @param string $agentType Type d'agent (AnalyticsAgent, SemanticAgent, etc.)
   * @return self
   */
  public function setAgentType(string $agentType): self
  {
    $this->metrics['agent_type'] = $agentType;
    return $this;
  }
  
  /**
   * Définit le type de classification
   * 
   * @param string $classificationType Type (analytics, semantic, hybrid)
   * @return self
   */
  public function setClassificationType(string $classificationType): self
  {
    $this->metrics['classification_type'] = $classificationType;
    return $this;
  }
  
  /**
   * Définit le score de confiance
   * 
   * @param float $confidence Score de confiance (0-1)
   * @return self
   */
  public function setConfidence(float $confidence): self
  {
    $this->metrics['confidence_score'] = round($confidence * 100, 2);
    return $this;
  }
  
  /**
   * Définit les informations API
   * 
   * @param string $provider Provider (openai, anthropic, ollama)
   * @param string $model Modèle utilisé
   * @return self
   */
  public function setApiInfo(string $provider, string $model): self
  {
    $this->metrics['api_provider'] = $provider;
    $this->metrics['model_used'] = $model;
    return $this;
  }
  
  /**
   * Définit les tokens utilisés
   * 
   * @param int $prompt Tokens du prompt
   * @param int $completion Tokens de la complétion
   * @return self
   */
  public function setTokens(int $prompt, int $completion): self
  {
    $this->metrics['tokens_prompt'] = $prompt;
    $this->metrics['tokens_completion'] = $completion;
    $this->metrics['tokens_total'] = $prompt + $completion;
    
    // Calculer le coût automatiquement
    $this->calculateCost();
    
    return $this;
  }
  
  /**
   * Définit si la réponse vient du cache
   * 
   * @param bool $cacheHit True si cache hit
   * @return self
   */
  public function setCacheHit(bool $cacheHit): self
  {
    $this->metrics['cache_hit'] = $cacheHit;
    return $this;
  }
  
  /**
   * Définit les scores de qualité et sécurité
   * 
   * @param float|null $quality Score de qualité (0-100)
   * @param float|null $security Score de sécurité (0-100)
   * @return self
   */
  public function setQualityScores(?float $quality = null, ?float $security = null): self
  {
    if ($quality !== null) {
      $this->metrics['response_quality'] = round($quality, 2);
    }
    if ($security !== null) {
      $this->metrics['security_score'] = round($security, 2);
    }
    return $this;
  }
  
  /**
   * Enregistre une erreur
   * 
   * @param string $errorType Type d'erreur
   * @param string $errorMessage Message d'erreur
   * @return self
   */
  public function setError(string $errorType, string $errorMessage): self
  {
    $this->metrics['error_occurred'] = true;
    $this->metrics['error_type'] = $errorType;
    $this->metrics['error_message'] = $errorMessage;
    return $this;
  }
  
  /**
   * Calcule le coût API basé sur les tokens et le modèle
   * 
   * @return void
   */
  private function calculateCost(): void
  {
    if ($this->metrics['tokens_total'] === null || $this->metrics['model_used'] === null) {
      return;
    }
    
    // Coûts approximatifs par 1K tokens (à ajuster selon les modèles)
    $costPer1kTokens = [
      'gpt-4' => 0.03,
      'gpt-4-turbo' => 0.01,
      'gpt-3.5-turbo' => 0.002,
      'claude-3-opus' => 0.015,
      'claude-3-sonnet' => 0.003,
      'claude-3-haiku' => 0.00025,
      'llama2' => 0.0, // Local, gratuit
      'mistral' => 0.0, // Local, gratuit
      'phi4' => 0.0, // Local, gratuit
      'gemma' => 0.0 // Local, gratuit
    ];
    
    $model = $this->metrics['model_used'];
    $costRate = $costPer1kTokens[$model] ?? 0.002; // Défaut: 0.002
    
    $this->metrics['api_cost_usd'] = ($this->metrics['tokens_total'] / 1000) * $costRate;
  }
  
  /**
   * Sauvegarde les statistiques dans la base de données
   * 
   * @return bool True si succès, false sinon
   */
  public function save(): bool
  {
    if ($this->interactionId === null) {
      error_log("StatisticsTracker: Cannot save without interaction_id");
      return false;
    }
    
    try {
      // Arrêter le tracking si pas encore fait
      if ($this->metrics['response_time_ms'] === null && $this->startTime !== null) {
        $this->stopTracking();
      }
      
      // 🔧 MIGRATED TO DOCTRINEORM
      DoctrineOrm::execute("
        INSERT INTO {$this->prefix}rag_statistics 
        (interaction_id, response_time_ms, cache_hit, api_provider, model_used,
         tokens_prompt, tokens_completion, tokens_total, api_cost_usd,
         agent_type, classification_type, confidence_score, security_score,
         response_quality, error_occurred, error_type, error_message,
         user_id, session_id, language_id, date_added)
        VALUES 
        (?, ?, ?, ?, ?,
         ?, ?, ?, ?,
         ?, ?, ?, ?,
         ?, ?, ?, ?,
         ?, ?, ?, NOW())
      ", [
        $this->interactionId,
        $this->metrics['response_time_ms'],
        $this->metrics['cache_hit'] ? 1 : 0,
        $this->metrics['api_provider'],
        $this->metrics['model_used'],
        $this->metrics['tokens_prompt'],
        $this->metrics['tokens_completion'],
        $this->metrics['tokens_total'],
        $this->metrics['api_cost_usd'],
        $this->metrics['agent_type'],
        $this->metrics['classification_type'],
        $this->metrics['confidence_score'],
        $this->metrics['security_score'],
        $this->metrics['response_quality'],
        $this->metrics['error_occurred'] ? 1 : 0,
        $this->metrics['error_type'],
        $this->metrics['error_message'],
        $this->userId,
        $this->sessionId,
        $this->languageId
      ]);
      
      // ✅ ALWAYS LOG PERFORMANCE METRICS (Requirement 8.5)
      error_log(sprintf(
        '[RAG] Performance: interaction_id=%d, time=%dms, agent=%s, type=%s, confidence=%.2f, quality=%.2f, cache=%s',
        $this->interactionId,
        $this->metrics['response_time_ms'] ?? 0,
        $this->metrics['agent_type'] ?? 'unknown',
        $this->metrics['classification_type'] ?? 'unknown',
        $this->metrics['confidence_score'] ?? 0,
        $this->metrics['response_quality'] ?? 0,
        $this->metrics['cache_hit'] ? 'HIT' : 'MISS'
      ));
      
      return true;
      
    } catch (\Exception $e) {
      error_log("StatisticsTracker: Error saving statistics: " . $e->getMessage());
      return false;
    }
  }
  
  /**
   * Met à jour les colonnes de performance dans rag_chat_interactions
   * 
   * @return bool True si succès, false sinon
   */
  public function updateInteractionMetrics(): bool
  {
    if ($this->interactionId === null) {
      return false;
    }
    
    try {
      // 🔧 MIGRATED TO DOCTRINEORM
      DoctrineOrm::execute("
        UPDATE {$this->prefix}rag_chat_interactions 
        SET response_time = ?,
            tokens_used = ?,
            api_cost = ?
        WHERE interaction_id = ?
      ", [
        $this->metrics['response_time_ms'],
        $this->metrics['tokens_total'],
        $this->metrics['api_cost_usd'],
        $this->interactionId
      ]);
      
      return true;
      
    } catch (\Exception $e) {
      error_log("StatisticsTracker: Error updating interaction metrics: " . $e->getMessage());
      return false;
    }
  }
  
  /**
   * Sauvegarde complète (statistiques + mise à jour interaction)
   * 
   * @return bool True si succès, false sinon
   */
  public function saveAll(): bool
  {
    $statsOk = $this->save();
    $interactionOk = $this->updateInteractionMetrics();
    
    return $statsOk && $interactionOk;
  }
  
  /**
   * Récupère les métriques actuelles
   * 
   * @return array Métriques collectées
   */
  public function getMetrics(): array
  {
    return $this->metrics;
  }
  
  /**
   * Récupère une métrique spécifique
   * 
   * @param string $key Nom de la métrique
   * @return mixed Valeur de la métrique ou null
   */
  public function getMetric(string $key)
  {
    return $this->metrics[$key] ?? null;
  }
  
  /**
   * Récupère toutes les métriques
   * 
   * @return array Toutes les métriques
   */
  public function getAllMetrics(): array
  {
    return $this->metrics;
  }
}
