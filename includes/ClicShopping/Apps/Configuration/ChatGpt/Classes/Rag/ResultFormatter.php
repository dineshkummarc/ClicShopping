<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM)  at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag;

/**
 * ResultFormatter Class
 *
 * Cette classe gère le formatage des résultats d'analyse pour l'affichage
 * dans l'interface utilisateur.
 */
class ResultFormatter
{
  /**
   * Formate les résultats selon leur type
   *
   * @param array $results Résultats à formater
   * @return array Résultats formatés
   */
  public function format(array $results): array
  {
    // Si c'est une erreur, la retourner telle quelle
    if (isset($results['type']) && $results['type'] === 'error') {
      return $results;
    }

    // Si c'est un résultat d'analytics, le formater correctement
    if (isset($results['type']) && ($results['type'] === 'analytics_results' || $results['type'] === 'analytics_response')) {
      return [
        'type' => 'formatted_results',
        'content' => $this->formatAnalyticsResults($results)
      ];
    }

    // Si c'est un résultat de recherche sémantique, le formater
    if (isset($results['type']) && $results['type'] === 'semantic_results') {
      return [
        'type' => 'formatted_results',
        'content' => $this->formatSemanticResults($results)
      ];
    }

    // Type non géré, retourner un message d'erreur formaté
    return [
      'type' => 'formatted_results',
      'content' => $this->formatUnknownResults($results)
    ];
  }

  /**
   * Formate les résultats d'analytics
   *
   * @param array $results Résultats d'analytics
   * @return string HTML formaté
   */
  private function formatAnalyticsResults(array $results): string
  {


    $output = "<div class='analytics-results'>";
    $output .= "<h3>Résultats pour : " . htmlspecialchars($results['question'] ?? $results['query'] ?? 'Requête inconnue') . "</h3>";

    // Afficher la requête SQL si disponible
    if (!empty($results['sql_query'])) {
      $output .= "<div class='sql-query'><strong>Requête SQL :</strong> <pre>" . htmlspecialchars($results['sql_query']) . "</pre></div>";
    }

    // Afficher l'interprétation
    if (!empty($results['interpretation'])) {
      $output .= "<div class='interpretation'><strong>Interprétation :</strong> " . $results['interpretation'] . "</div>";
    }

    // Afficher les résultats sous forme de tableau si disponibles
    if (!empty($results['results']) && is_array($results['results'])) {
      $output .= "<div class='results-table'>";
      $output .= "<h4>Données brutes :</h4>";
      $output .= "<table class='table table-bordered table-striped'>";

      // En-têtes de tableau
      $output .= "<thead><tr>";
      $firstRow = reset($results['results']);
      if (is_array($firstRow)) {
        foreach (array_keys($firstRow) as $key) {
          if (!is_numeric($key)) { // Éviter les clés numériques dupliquées
            $output .= "<th>" . htmlspecialchars($key) . "</th>";
          }
        }
      }
      $output .= "</tr></thead>";

      // Données
      $output .= "<tbody>";
      foreach ($results['results'] as $row) {
        $output .= "<tr>";
        foreach ($row as $key => $value) {
          if (!is_numeric($key)) { // Éviter les clés numériques dupliquées
            $output .= "<td>" . htmlspecialchars($value) . "</td>";
          }
        }
        $output .= "</tr>";
      }
      $output .= "</tbody>";

      $output .= "</table>";
      $output .= "</div>";
    }

    $output .= "</div>";

    return $output;
  }

  /**
   * Formate les résultats de recherche sémantique
   *
   * @param array $results Résultats de recherche sémantique
   * @return string HTML formaté
   */
  private function formatSemanticResults(array $results): string
  {
    $output = "<div class='semantic-results'>";
    $output .= "<h3>Résultats pour : " . htmlspecialchars($results['query'] ?? 'Requête inconnue') . "</h3>";

    // Afficher la réponse
    if (!empty($results['response'])) {
      $output .= "<div class='response'>" . $results['response'] . "</div>";
    }

    // Afficher les sources si disponibles
    if (!empty($results['sources']) && is_array($results['sources'])) {
      $output .= "<div class='sources'>";
      $output .= "<h4>Sources :</h4>";
      $output .= "<ul>";
      foreach ($results['sources'] as $source) {
        $output .= "<li>";
        if (isset($source['title'])) {
          $output .= "<strong>" . htmlspecialchars($source['title']) . "</strong>";
        }
        if (isset($source['content'])) {
          $output .= "<p>" . htmlspecialchars(substr($source['content'], 0, 200)) . "...</p>";
        }
        $output .= "</li>";
      }
      $output .= "</ul>";
      $output .= "</div>";
    }

    $output .= "</div>";
    return $output;
  }

  /**
   * Formate les résultats de type inconnu
   *
   * @param array $results Résultats de type inconnu
   * @return string HTML formaté
   */
  private function formatUnknownResults(array $results): string
  {
    $output = "<div class='unknown-results'>";
    $output .= "<h3>Type de résultat non géré : " . htmlspecialchars($results['type'] ?? 'inconnu') . "</h3>";
    $output .= "<p>Contenu brut des résultats : </p>";
    $output .= "<pre>" . htmlspecialchars(json_encode($results, JSON_PRETTY_PRINT)) . "</pre>";
    $output .= "</div>";
    return $output;
  }
}




