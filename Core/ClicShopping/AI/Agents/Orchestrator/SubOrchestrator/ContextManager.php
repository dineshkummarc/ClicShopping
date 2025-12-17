<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

/**
 * ContextManager Class
 * 
 * Gère intelligemment le contexte conversationnel pour éviter les conflits
 * entre mémoire conversationnelle et feedback learning
 */

namespace ClicShopping\AI\Agents\Orchestrator\SubOrchestrator;

use ClicShopping\AI\Domain\Patterns\AnalyticsPattern;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Agents\Orchestrator\SubOrchestrator\ContextSwitchDetector;
use ClicShopping\AI\Domain\Semantics\SubSemantics\SemanticDomainDetector;


class ContextManager
{
  private SecurityLogger $securityLogger;
  private ContextSwitchDetector $switchDetector;
  private bool $debug;

  // Options de gestion du contexte
  private array $options = [
    'auto_clear_on_domain_switch' => true,
    'prioritize_feedback_over_context' => true,
    'min_confidence_for_clear' => 0.7,
  ];

  public function __construct(bool $debug = false, array $options = [])
  {
    $this->securityLogger = new SecurityLogger();
    $this->switchDetector = new ContextSwitchDetector($debug);
    $this->debug = $debug;
    $this->options = array_merge($this->options, $options);
  }

  /**
   * Décide comment utiliser le contexte pour une nouvelle question
   *
   * @param string $query Nouvelle question
   * @param array $conversationContext Contexte conversationnel actuel
   * @param array $feedbackContext Contexte de feedback learning
   * @return array Décision sur l'utilisation du contexte
   */
  public function decideContextUsage(string $query, array $conversationContext, array $feedbackContext): array
  {
    // 1. Détecter un changement de domaine
    $switchDetection = $this->switchDetector->detectContextSwitch($query, $conversationContext);

    // 2. Détecter des marqueurs explicites de reset
    $hasExplicitReset = $this->switchDetector->hasExplicitContextReset($query);

    // 3. Évaluer la pertinence du feedback
    $hasFeedback = !empty($feedbackContext);
    $feedbackRelevance = $this->evaluateFeedbackRelevance($query, $feedbackContext);

    // 4. Décider de l'action
    $decision = $this->makeDecision(
      $switchDetection,
      $hasExplicitReset,
      $hasFeedback,
      $feedbackRelevance
    );

    if ($this->debug) {
      $this->securityLogger->logSecurityEvent(
        "Context decision: " . json_encode($decision),
        'info'
      );
    }

    return $decision;
  }

  /**
   * Prend la décision finale sur l'utilisation du contexte
   *
   * @param array $switchDetection Résultat de la détection de changement
   * @param bool $hasExplicitReset Marqueur explicite de reset
   * @param bool $hasFeedback Présence de feedback
   * @param float $feedbackRelevance Pertinence du feedback
   * @return array Décision
   */
  private function makeDecision(array $switchDetection, bool $hasExplicitReset, bool $hasFeedback, float $feedbackRelevance): array
  {
    $decision = [
      'use_conversation_context' => true,
      'use_feedback_context' => true,
      'clear_conversation_context' => false,
      'prioritize_feedback' => false,
      'reason' => '',
    ];

    // Cas 1 : Reset explicite demandé
    if ($hasExplicitReset) {
      $decision['clear_conversation_context'] = true;
      $decision['use_conversation_context'] = false;
      $decision['reason'] = 'Explicit context reset requested';
      return $decision;
    }

    // Cas 2 : Changement de domaine détecté
    if ($switchDetection['has_switch'] && $this->options['auto_clear_on_domain_switch']) {
      if ($switchDetection['confidence'] >= $this->options['min_confidence_for_clear']) {
        $decision['clear_conversation_context'] = true;
        $decision['use_conversation_context'] = false;
        $decision['reason'] = sprintf(
          'Domain switch detected: %s -> %s (confidence: %.2f)',
          $switchDetection['previous_domain'],
          $switchDetection['new_domain'],
          $switchDetection['confidence']
        );
        return $decision;
      }
    }

    // Cas 3 : Feedback pertinent disponible
    if ($hasFeedback && $feedbackRelevance > 0.6) {
      if ($this->options['prioritize_feedback_over_context']) {
        $decision['prioritize_feedback'] = true;
        $decision['use_conversation_context'] = false; // Désactiver le contexte conversationnel
        $decision['reason'] = sprintf(
          'Prioritizing feedback learning (relevance: %.2f)',
          $feedbackRelevance
        );
        return $decision;
      }
    }

    // Cas 4 : Utiliser les deux contextes (par défaut)
    $decision['reason'] = 'Using both contexts with conversation priority';
    return $decision;
  }

  /**
   * Évalue la pertinence du feedback pour la question actuelle
   *
   * @param string $query Question actuelle
   * @param array $feedbackContext Contexte de feedback
   * @return float Score de pertinence (0-1)
   */
  private function evaluateFeedbackRelevance(string $query, array $feedbackContext): float
  {
    if (empty($feedbackContext)) {
      return 0.0;
    }

    $query = mb_strtolower($query);
    $maxRelevance = 0.0;

    foreach ($feedbackContext as $feedback) {
      $originalQuery = mb_strtolower($feedback['original_query'] ?? '');
      
      // Calculer la similarité simple (peut être amélioré avec Levenshtein ou embeddings)
      $similarity = $this->calculateSimpleSimilarity($query, $originalQuery);
      
      if ($similarity > $maxRelevance) {
        $maxRelevance = $similarity;
      }
    }

    return $maxRelevance;
  }

  /**
   * Calcule une similarité simple entre deux textes
   *
   * @param string $text1 Premier texte
   * @param string $text2 Deuxième texte
   * @return float Similarité (0-1)
   */
  private function calculateSimpleSimilarity(string $text1, string $text2): float
  {
    // Tokenisation simple
    $words1 = array_unique(preg_split('/\s+/', $text1));
    $words2 = array_unique(preg_split('/\s+/', $text2));

    // Intersection
    $common = array_intersect($words1, $words2);

    // Jaccard similarity
    $union = array_unique(array_merge($words1, $words2));
    
    if (empty($union)) {
      return 0.0;
    }

    return count($common) / count($union);
  }

  /**
   * Filtre le contexte conversationnel selon la décision
   *
   * @param array $conversationContext Contexte complet
   * @param array $decision Décision de gestion
   * @return array Contexte filtré
   */
  public function filterConversationContext(
    array $conversationContext,
    array $decision
  ): array {
    if ($decision['clear_conversation_context'] || !$decision['use_conversation_context']) {
      // Effacer le contexte court terme mais GARDER la mémoire long terme
      // La mémoire long terme est basée sur la similarité sémantique, donc pertinente
      $filteredLongTerm = $this->filterLongTermMemory(
        $conversationContext['long_term_context'] ?? [],
        $decision
      );
      
      $feedbackContext = $conversationContext['feedback_context'] ?? [];
      
      return [
        'short_term_context' => [],
        'long_term_context' => $filteredLongTerm,
        'feedback_context' => $feedbackContext,
        'has_context' => !empty($feedbackContext) || !empty($filteredLongTerm),
      ];
    }

    return $conversationContext;
  }

  /**
   * Filtre le contexte à long terme en cas de changement de domaine.
   *
   * @param array $longTermContext Contexte à long terme (mémoire)
   * @param array $decision Décision de l'agent (contient le nouveau domaine)
   * @return array Contexte filtré
   */
  private function filterLongTermMemory(array $longTermContext, array $decision): array
  {
    // 1. Vérifie si la raison de la décision est un changement de domaine
    if (isset($decision['reason']) && strpos($decision['reason'], 'Domain switch') !== false) {

      // 2. Extrait le nouveau nom de domaine de la raison
      if (preg_match('/-> (\w+)/', $decision['reason'], $matches)) {
        $newDomain = $matches[1];

        // 3. Récupère les mots-clés du nouveau domaine depuis la source centralisée
        // C'est l'appel crucial qui utilise la fonction que vous avez créée dans AnalyticsPattern.php
        $keywords = AnalyticsPattern::getDomainKeywords($newDomain);

        // 4. Si aucun mot-clé n'est trouvé pour ce domaine, on ne filtre pas (on garde tout)
        if (empty($keywords)) {
          return $longTermContext;
        }

        // 5. Filtre le contexte à long terme pour ne garder que les entrées pertinentes
        return array_filter($longTermContext, function($item) use ($newDomain, $keywords) {
          $content = mb_strtolower($item['content'] ?? '');

          // Vérifie si le contenu de l'entrée de mémoire contient au moins un des mots-clés
          foreach ($keywords as $keyword) {
            if (strpos($content, $keyword) !== false) {
              return true; // Garde l'entrée
            }
          }

          return false; // Écarte l'entrée
        });
      }
    }

    // Si pas de changement de domaine ou si l'extraction a échoué, retourne le contexte original
    return $longTermContext;
  }

  /**
   * Crée un contexte enrichi avec les métadonnées de décision
   *
   * @param array $conversationContext Contexte conversationnel
   * @param array $decision Décision prise
   * @return array Contexte enrichi
   */
  public function enrichContextWithDecision(
    array $conversationContext,
    array $decision
  ): array {
    $conversationContext['context_decision'] = $decision;
    return $conversationContext;
  }

  /**
   * Configure les options de gestion du contexte
   *
   * @param array $options Nouvelles options
   * @return void
   */
  public function setOptions(array $options): void
  {
    $this->options = array_merge($this->options, $options);
  }

  /**
   * Obtient les options actuelles
   *
   * @return array Options
   */
  public function getOptions(): array
  {
    return $this->options;
  }
}
