<?php
/**
 * SemanticDomainDetector Class
 * 
 * Détection sémantique enrichie des domaines avec support de synonymes,
 * termes techniques et concepts liés
 */

namespace ClicShopping\AI\Helper\Detection;

use AllowDynamicProperties;
use ClicShopping\AI\Security\SecurityLogger;

#[AllowDynamicProperties]
class SemanticDomainDetector
{
  private SecurityLogger $securityLogger;
  private bool $debug;
  private array $enrichedDomains;

  // Poids par type de terme
  private array $termWeights = [
    'core' => 1.0,        // Termes principaux
    'technical' => 0.9,   // Termes techniques
    'synonyms' => 0.8,    // Synonymes
    'concepts' => 0.7,    // Concepts liés
    'attributes' => 0.6,  // Attributs
    'documents' => 0.6,   // Documents
    'status' => 0.5,      // États
    'hierarchy' => 0.5,   // Hiérarchie
    'analysis' => 0.8,    // Analyse (pour sentiment, etc.)
  ];

  public function __construct(bool $debug = false)
  {
    $this->securityLogger = new SecurityLogger();
    $this->debug = $debug;

    // Pure LLM mode: patterns are disabled, use empty domains
    if ($debug) {
      error_log("SemanticDomainDetector::__construct() - Pure LLM mode, using empty domains");
    }
    $this->enrichedDomains = [];
  }

  /**
   * Détecte le domaine d'une question avec scoring sémantique
   *
   * @param string $query Question à analyser
   * @return array Résultat avec domaine, score et détails
   */
  public function detectDomain(string $query): array
  {
    $query = mb_strtolower($query);
    $scores = [];

    // Calculer le score pour chaque domaine
    foreach ($this->enrichedDomains as $domain => $termGroups) {
      $domainScore = 0;
      $matchedTerms = [];

      foreach ($termGroups as $groupType => $terms) {
        $weight = $this->termWeights[$groupType] ?? 0.5;

        foreach ($terms as $term) {
          if (strpos($query, $term) !== false) {
            $domainScore += $weight;
            $matchedTerms[] = [
              'term' => $term,
              'type' => $groupType,
              'weight' => $weight,
            ];
          }
        }
      }

      if ($domainScore > 0) {
        $scores[$domain] = [
          'score' => $domainScore,
          'matched_terms' => $matchedTerms,
        ];
      }
    }

    // Désambiguïser les domaines similaires
    $scores = $this->disambiguateSimilarDomains($query, $scores);

    // Trier par score décroissant
    uasort($scores, function($a, $b) {
      return $b['score'] <=> $a['score'];
    });

    // Retourner le meilleur domaine
    if (empty($scores)) {
      return [
        'domain' => 'unknown',
        'score' => 0.0,
        'confidence' => 0.0,
        'matched_terms' => [],
      ];
    }

    $topDomain = array_key_first($scores);
    $topScore = $scores[$topDomain]['score'];

    // Calculer la confiance (0-1)
    // Confiance élevée si score > 1.5 et écart significatif avec le 2ème
    $secondScore = 0;
    if (count($scores) > 1) {
      $domains = array_keys($scores);
      $secondScore = $scores[$domains[1]]['score'];
    }

    $scoreDiff = $topScore - $secondScore;
    $confidence = min(1.0, ($topScore / 2.0) + ($scoreDiff / 2.0));

    return [
      'domain' => $topDomain,
      'score' => $topScore,
      'confidence' => $confidence,
      'matched_terms' => $scores[$topDomain]['matched_terms'],
      'all_scores' => $scores,
    ];
  }

  /**
   * Obtient tous les termes d'un domaine (pour compatibilité)
   *
   * @param string $domain Nom du domaine
   * @return array Liste de tous les termes
   */
  public function getDomainTerms(string $domain): array
  {
    if (!isset($this->enrichedDomains[$domain])) {
      return [];
    }

    $allTerms = [];
    foreach ($this->enrichedDomains[$domain] as $termGroup) {
      $allTerms = array_merge($allTerms, $termGroup);
    }

    return array_unique($allTerms);
  }

  /**
   * Désambiguïse entre domaines similaires en analysant le contexte
   *
   * @param string $query Question à analyser
   * @param array $scores Scores de tous les domaines
   * @return array Scores ajustés après désambiguïsation
   */
  private function disambiguateSimilarDomains(string $query, array $scores): array
  {
    // Cas 1 : Confusion suppliers vs manufacturers
    if (isset($scores['suppliers']) && isset($scores['manufacturers'])) {
      $supplierScore = $scores['suppliers']['score'];
      $manufacturerScore = $scores['manufacturers']['score'];
      
      // Si scores proches, utiliser des indices contextuels
      if (abs($supplierScore - $manufacturerScore) < 0.5) {
        // Indices pour suppliers
        $supplierIndicators = ['purchase', 'buy', 'order-from', 'vendor', 'supply'];
        // Indices pour manufacturers
        $manufacturerIndicators = ['brand', 'made-by', 'produced-by', 'origin'];
        
        $supplierBonus = 0;
        $manufacturerBonus = 0;
        
        foreach ($supplierIndicators as $indicator) {
          if (strpos($query, $indicator) !== false) {
            $supplierBonus += 1.0; // Augmenté pour mieux différencier
          }
        }
        
        foreach ($manufacturerIndicators as $indicator) {
          if (strpos($query, $indicator) !== false) {
            $manufacturerBonus += 1.0; // Augmenté pour mieux différencier
          }
        }
        
        $scores['suppliers']['score'] += $supplierBonus;
        $scores['manufacturers']['score'] += $manufacturerBonus;
        
        // Si "product" est aussi détecté, réduire son score pour éviter confusion
        if (isset($scores['products']) && ($supplierBonus > 0 || $manufacturerBonus > 0)) {
          $scores['products']['score'] *= 0.7;
        }
      }
    }

    // Cas 2 : Confusion reviews vs reviews_sentiment
    if (isset($scores['reviews']) && isset($scores['reviews_sentiment'])) {
      $reviewScore = $scores['reviews']['score'];
      $sentimentScore = $scores['reviews_sentiment']['score'];
      
      if (abs($reviewScore - $sentimentScore) < 0.5) {
        // Indices pour sentiment analysis
        $sentimentIndicators = ['sentiment', 'feeling', 'positive', 'negative', 'emotion', 'analysis'];
        
        $sentimentBonus = 0;
        foreach ($sentimentIndicators as $indicator) {
          if (strpos($query, $indicator) !== false) {
            $sentimentBonus += 0.5;
          }
        }
        
        if ($sentimentBonus > 0) {
          $scores['reviews_sentiment']['score'] += $sentimentBonus;
          // Réduire le score de reviews si sentiment est détecté
          $scores['reviews']['score'] *= 0.7;
        } else {
          // Si pas d'indices de sentiment, favoriser reviews
          $scores['reviews']['score'] += 0.3;
        }
      }
    }

    // Cas 3 : Si "product" domine mais contexte indique autre chose
    if (isset($scores['products'])) {
      $productScore = $scores['products']['score'];
      
      // Vérifier si d'autres domaines ont des scores significatifs
      foreach ($scores as $domain => $data) {
        if ($domain !== 'products' && $data['score'] > 0.5) {
          // Si autre domaine a un score raisonnable, réduire légèrement products
          $scores['products']['score'] *= 0.95;
          break;
        }
      }
    }

    return $scores;
  }

  //********************
  //Not Used
  //********************
  /**
   * Ajoute des termes personnalisés à un domaine
   *
   * @param string $domain Nom du domaine
   * @param string $groupType Type de groupe (core, technical, etc.)
   * @param array $terms Termes à ajouter
   * @return void
   */
  public function addCustomTerms(string $domain, string $groupType, array $terms): void
  {
    if (!isset($this->enrichedDomains[$domain])) {
      $this->enrichedDomains[$domain] = [];
    }

    if (!isset($this->enrichedDomains[$domain][$groupType])) {
      $this->enrichedDomains[$domain][$groupType] = [];
    }

    $this->enrichedDomains[$domain][$groupType] = array_merge(
      $this->enrichedDomains[$domain][$groupType],
      $terms
    );

    if ($this->debug) {
      $this->securityLogger->logSecurityEvent(
        "Added custom terms to domain '{$domain}' group '{$groupType}': " . implode(', ', $terms),
        'info'
      );
    }
  }

  /**
   * Obtient les domaines enrichis complets
   *
   * @return array Tous les domaines avec leurs termes
   */
  public function getEnrichedDomains(): array
  {
    return $this->enrichedDomains;
  }

  /**
   * Compare deux domaines pour détecter un changement significatif
   *
   * @param string $previousDomain Domaine précédent
   * @param string $newDomain Nouveau domaine
   * @param float $newConfidence Confiance du nouveau domaine
   * @return bool True si changement significatif
   */
  public function isSignificantChange(
    string $previousDomain,
    string $newDomain,
    float $newConfidence
  ): bool {
    // Pas de changement si même domaine
    if ($previousDomain === $newDomain) {
      return false;
    }

    // Changement significatif si confiance élevée
    if ($newConfidence >= 0.75) {
      return true;
    }

    // Changement modéré si confiance moyenne
    if ($newConfidence >= 0.6) {
      return true;
    }

    // Pas de changement si confiance faible
    return false;
  }
}
