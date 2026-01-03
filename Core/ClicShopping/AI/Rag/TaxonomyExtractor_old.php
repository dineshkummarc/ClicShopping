<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Rag;

/**
 * TaxonomyExtractor Class
 *
 * This class extracts and processes taxonomy metadata from document content.
 * It separates taxonomy information from embedding content to eliminate semantic collision
 * in vector similarity searches.
 *
 * Key features:
 * - Detect taxonomy sections in content
 * - Extract and parse taxonomy data to JSON
 * - Clean content by removing taxonomy markers
 * - Validate taxonomy structure
 * - Normalize whitespace and line endings
 *
 * Taxonomy Format:
 * Content may contain taxonomy in the following format:
 * [Taxonomy]: domaine: "...", type_produit: "...", ...
 * or
 * [taxonomy]: domaine: "...", type_produit: "...", ...
 *
 * @package ClicShopping\AI\Rag
 */
class TaxonomyExtractor
{
  /**
   * Taxonomy marker patterns
   */
  private const TAXONOMY_MARKERS = [
    '/\[Taxonomy\]:\s*/i',
    '/\[taxonomy\]:\s*/i'
  ];

  /**
   * Required taxonomy fields
   */
  private const REQUIRED_FIELDS = [
    'domaine',
    'type_produit',
    'type_usage'
  ];

  /**
   * Optional taxonomy fields
   */
  private const OPTIONAL_FIELDS = [
    'sujet_produit',
    'sujet_usage',
    'type_objectif',
    'sujet_objectif',
    'articles'
  ];

  /**
   * Extract taxonomy from content
   *
   * This method detects taxonomy sections, extracts the taxonomy data,
   * parses it to JSON, and returns both the cleaned content and the taxonomy.
   *
   * @param string $content Content with embedded taxonomy
   * @return array ['content' => string, 'taxonomy' => array|null]
   */
  public function extract(string $content): array
  {
    // Check if content has taxonomy
    if (!$this->hasTaxonomy($content)) {
      return [
        'content' => $this->normalizeWhitespace($content),
        'taxonomy' => null
      ];
    }

    // Find taxonomy section
    $taxonomyString = $this->extractTaxonomyString($content);
    
    if (empty($taxonomyString)) {
      return [
        'content' => $this->normalizeWhitespace($content),
        'taxonomy' => null
      ];
    }

    // Parse taxonomy to array
    $taxonomy = $this->parseTaxonomy($taxonomyString);

    // Clean content
    $cleanedContent = $this->cleanContent($content);

    return [
      'content' => $cleanedContent,
      'taxonomy' => $taxonomy
    ];
  }

  /**
   * Detect if content contains taxonomy
   *
   * @param string $content Content to check
   * @return bool True if taxonomy found
   */
  public function hasTaxonomy(string $content): bool
  {
    foreach (self::TAXONOMY_MARKERS as $pattern) {
      if (preg_match($pattern, $content)) {
        return true;
      }
    }
    return false;
  }

  /**
   * Parse taxonomy string to structured array
   *
   * This method parses the taxonomy string format:
   * domaine: "value", type_produit: "value", ...
   *
   * @param string $taxonomyString Raw taxonomy text
   * @return array|null Structured taxonomy data or null on error
   */
  public function parseTaxonomy(string $taxonomyString): ?array
  {
    if (empty($taxonomyString)) {
      return null;
    }

    $taxonomy = [];

    // Parse simple key: "value" pairs
    $pattern = '/(\w+):\s*"([^"]*)"/';
    preg_match_all($pattern, $taxonomyString, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
      $key = $match[1];
      $value = $match[2];
      
      // Handle array fields (sujet_produit, sujet_usage, sujet_objectif)
      if (strpos($key, 'sujet_') === 0) {
        // Check if value contains comma-separated items
        if (strpos($value, ',') !== false) {
          $taxonomy[$key] = array_map('trim', explode(',', $value));
        } else {
          $taxonomy[$key] = [$value];
        }
      } else {
        $taxonomy[$key] = $value;
      }
    }

    // Parse articles array if present
    if (preg_match('/articles:\s*\[(.*?)\]/s', $taxonomyString, $articlesMatch)) {
      $articlesString = $articlesMatch[1];
      $articles = $this->parseArticles($articlesString);
      if (!empty($articles)) {
        $taxonomy['articles'] = $articles;
      }
    }

    // Return null if no fields were parsed
    if (empty($taxonomy)) {
      return null;
    }

    // Ensure required fields exist (use null for missing)
    foreach (self::REQUIRED_FIELDS as $field) {
      if (!isset($taxonomy[$field])) {
        $taxonomy[$field] = null;
      }
    }

    return $taxonomy;
  }

  /**
   * Clean content by removing taxonomy markers and LLM prompts
   *
   * This method removes taxonomy sections, LLM generation prompts,
   * and normalizes whitespace.
   *
   * @param string $content Content with taxonomy
   * @return string Cleaned content
   */
  public function cleanContent(string $content): string
  {
    // Remove taxonomy section (case-insensitive)
    $content = preg_replace('/\[Taxonomy\]:.*$/is', '', $content);
    $content = preg_replace('/\[taxonomy\]:.*$/is', '', $content);

    // Remove "Tu es un système d'analyse documentaire" instruction blocks
    // Simple approach: remove all lines containing this phrase and the following instruction lines
    $lines = explode("\n", $content);
    $cleanedLines = [];
    $skipNext = false;
    
    foreach ($lines as $line) {
      // Skip lines with "Tu es un système"
      if (stripos($line, 'Tu es un système') !== false) {
        $skipNext = true;
        continue;
      }
      
      // Skip instruction field lines after "Tu es"
      if ($skipNext && preg_match('/^\[(?:domaine|processus|modes_paiement|validation_commande|conditions_éligibilité|délai_traitement|tarification|livraison|responsabilité|référence_légale|critères|dimension_symbolique|source)\]\s*:/i', $line)) {
        continue;
      }
      
      // Stop skipping when we hit a real data field
      if ($skipNext && preg_match('/^\[(?:Titre|Nom|Commande|Information|Caractéristiques|Historique|Total)/i', $line)) {
        $skipNext = false;
      }
      
      $cleanedLines[] = $line;
    }
    
    $content = implode("\n", $cleanedLines);

    // Remove LLM taxonomy generation instruction prompts
    // These are the full instruction blocks that were embedded during taxonomy generation
    $llmInstructionPatterns = [
      // Full instruction block: "Extrait une taxonomie conceptuelle structurée du texte suivant..."
      '/Extrait une taxonomie conceptuelle structurée du texte suivant\..*?(?=\[|$)/is',
      
      // Instruction with field descriptions
      '/\[domaine\]\s*:\s*Domaine principal.*?(?=\[|$)/is',
      '/\[sous_domaine\]\s*:\s*Spécialisation au sein du domaine.*?(?=\[|$)/is',
      '/\[type_politique\]\s*:\s*Type de document.*?(?=\[|$)/is',
      '/\[portée\]\s*:\s*Portée géographique.*?(?=\[|$)/is',
      '/\[cadre_legal\]\s*:\s*Références aux cadres juridiques.*?(?=\[|$)/is',
      '/\[objectif\]\s*:\s*Finalité de la politique.*?(?=\[|$)/is',
      '/\[ton\]\s*:\s*Ton général du document.*?(?=\[|$)/is',
      '/\[source\]\s*:\s*Origine ou type d\'auteur.*?(?=\[|$)/is',
      '/\[mots_cles\]\s*:\s*Liste libre de mots-clés.*?(?=\[|$)/is',
      
      // Additional instruction fields
      '/\[processus\]\s*:\s*Description du déroulement.*?(?=\[|$)/is',
      '/\[modes_paiement\]\s*:\s*Moyens de paiement.*?(?=\[|$)/is',
      '/\[validation_commande\]\s*:\s*Conditions de validation.*?(?=\[|$)/is',
      '/\[conditions_éligibilité\]\s*:\s*Conditions d\'accès.*?(?=\[|$)/is',
      '/\[délai_traitement\]\s*:\s*Délais ou étapes.*?(?=\[|$)/is',
      '/\[tarification\]\s*:\s*Mentions relatives.*?(?=\[|$)/is',
      '/\[livraison\]\s*:\s*Informations sur les modalités.*?(?=\[|$)/is',
      '/\[responsabilité\]\s*:\s*Acteurs impliqués.*?(?=\[|$)/is',
      '/\[référence_légale\]\s*:\s*Références à des cadres.*?(?=\[|$)/is',
      '/\[critères\]\s*:\s*Normes de qualité.*?(?=\[|$)/is',
      '/\[dimension_symbolique\]\s*:\s*Valeurs ou principes.*?(?=\[|$)/is',
    ];

    foreach ($llmInstructionPatterns as $pattern) {
      $content = preg_replace($pattern, '', $content);
    }

    // Remove LLM taxonomy generation prompts (French)
    // These prompts were embedded during taxonomy generation and cause false positives
    $llmPromptPatterns = [
      // "Le texte correspond à une politique e-commerce relative aux..."
      '/Le texte correspond à une politique e-commerce.*?(?=\n\n|\[|$)/is',
      
      // "Ce texte décrit une politique..."
      '/Ce texte décrit une politique.*?(?=\n\n|\[|$)/is',
      
      // "Il s'agit d'une politique..."
      '/Il s\'agit d\'une politique.*?(?=\n\n|\[|$)/is',
      
      // "Cette page présente la politique..."
      '/Cette page présente la politique.*?(?=\n\n|\[|$)/is',
      
      // Generic "politique" in taxonomy context
      '/^.*?politique.*?taxonomy.*?$/im',
      '/^.*?taxonomy.*?politique.*?$/im',
      
      // Remove any remaining taxonomy-related prompt fragments
      '/\[Analyse taxonomy\].*?(?=\n\n|\[|$)/is',
      '/Génération de taxonomy.*?(?=\n\n|\[|$)/is',
    ];

    foreach ($llmPromptPatterns as $pattern) {
      $content = preg_replace($pattern, '', $content);
    }

    // Normalize whitespace
    return $this->normalizeWhitespace($content);
  }

  /**
   * Validate taxonomy structure
   *
   * This method checks if the taxonomy has the required structure
   * and validates field types.
   *
   * @param array $taxonomy Taxonomy data to validate
   * @return array Validation results ['valid' => bool, 'errors' => array]
   */
  public function validate(array $taxonomy): array
  {
    $errors = [];

    // Check if taxonomy is empty
    if (empty($taxonomy)) {
      $errors[] = 'Taxonomy is empty';
      return ['valid' => false, 'errors' => $errors];
    }

    // Validate required fields exist
    foreach (self::REQUIRED_FIELDS as $field) {
      if (!array_key_exists($field, $taxonomy)) {
        $errors[] = "Missing required field: {$field}";
      }
    }

    // Validate field types
    $stringFields = ['domaine', 'type_produit', 'type_usage', 'type_objectif'];
    foreach ($stringFields as $field) {
      if (isset($taxonomy[$field]) && $taxonomy[$field] !== null && !is_string($taxonomy[$field])) {
        $errors[] = "Field {$field} must be a string or null";
      }
    }

    $arrayFields = ['sujet_produit', 'sujet_usage', 'sujet_objectif'];
    foreach ($arrayFields as $field) {
      if (isset($taxonomy[$field]) && $taxonomy[$field] !== null && !is_array($taxonomy[$field])) {
        $errors[] = "Field {$field} must be an array or null";
      }
    }

    // Validate articles structure if present
    if (isset($taxonomy['articles'])) {
      if (!is_array($taxonomy['articles'])) {
        $errors[] = "Field 'articles' must be an array";
      } else {
        foreach ($taxonomy['articles'] as $index => $article) {
          if (!is_array($article)) {
            $errors[] = "Article at index {$index} must be an array";
            continue;
          }
          
          $requiredArticleFields = ['numero', 'titre', 'contenu'];
          foreach ($requiredArticleFields as $articleField) {
            if (!isset($article[$articleField])) {
              $errors[] = "Article at index {$index} missing field: {$articleField}";
            }
          }
        }
      }
    }

    return [
      'valid' => empty($errors),
      'errors' => $errors
    ];
  }

  /**
   * Extract taxonomy string from content
   *
   * @param string $content Content with taxonomy
   * @return string Taxonomy string
   */
  private function extractTaxonomyString(string $content): string
  {
    // Find the taxonomy marker and extract everything after it
    if (preg_match('/\[Taxonomy\]:\s*(.*)$/is', $content, $matches)) {
      return trim($matches[1]);
    }
    if (preg_match('/\[taxonomy\]:\s*(.*)$/is', $content, $matches)) {
      return trim($matches[1]);
    }
    return '';
  }

  /**
   * Parse articles array from taxonomy string
   *
   * @param string $articlesString Articles string
   * @return array Parsed articles
   */
  private function parseArticles(string $articlesString): array
  {
    $articles = [];
    
    // Match article objects: {numero: X, titre: "...", contenu: "..."}
    $pattern = '/\{([^}]+)\}/';
    preg_match_all($pattern, $articlesString, $matches);

    foreach ($matches[1] as $articleString) {
      $article = [];
      
      // Parse numero (integer)
      if (preg_match('/numero:\s*(\d+)/', $articleString, $numeroMatch)) {
        $article['numero'] = (int)$numeroMatch[1];
      }
      
      // Parse titre (string)
      if (preg_match('/titre:\s*"([^"]*)"/', $articleString, $titreMatch)) {
        $article['titre'] = $titreMatch[1];
      }
      
      // Parse contenu (string)
      if (preg_match('/contenu:\s*"([^"]*)"/', $articleString, $contenuMatch)) {
        $article['contenu'] = $contenuMatch[1];
      }
      
      // Only add article if it has all required fields
      if (isset($article['numero']) && isset($article['titre']) && isset($article['contenu'])) {
        $articles[] = $article;
      }
    }

    return $articles;
  }

  /**
   * Normalize whitespace and line endings
   *
   * @param string $content Content to normalize
   * @return string Normalized content
   */
  private function normalizeWhitespace(string $content): string
  {
    // Normalize line endings to \n
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    
    // Remove trailing whitespace from each line
    $lines = explode("\n", $content);
    $lines = array_map('rtrim', $lines);
    
    // Remove trailing empty lines
    while (!empty($lines) && trim(end($lines)) === '') {
      array_pop($lines);
    }
    
    // Rejoin lines
    $content = implode("\n", $lines);
    
    // Trim overall content
    return trim($content);
  }
}
