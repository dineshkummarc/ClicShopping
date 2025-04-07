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
   * Formate les résultats d'analyse pour l'affichage
   *
   * @param array $results Résultats d'analyse
   * @param string $prompt Requête originale
   * @return string Résultats formatés pour l'affichage
   */
  public function formatResults(array $results, string $prompt): string
  {
    $formattedResponse = "Résultats pour votre requête : \"{$prompt}\"\n\n";

    // Cas spécial : résultat direct d'une recherche par référence (format simplifié)
    if (isset($results['id']) && isset($results['data'])) {
      $formattedResponse .= "Produit trouvé par référence :\n\n";

      if (isset($results['data']['products_model'])) {
        $formattedResponse .= "Référence: {$results['data']['products_model']}\n";
      }

      if (isset($results['data']['products_sku'])) {
        $formattedResponse .= "SKU: {$results['data']['products_sku']}\n";
      }

      if (isset($results['data']['products_ean'])) {
        $formattedResponse .= "EAN: {$results['data']['products_ean']}\n";
      }

      if (isset($results['data']['products_quantity'])) {
        $formattedResponse .= "Stock: {$results['data']['products_quantity']} unités\n";
      } else {
        $formattedResponse .= "Information de stock non disponible pour ce produit.\n";
      }

      if (isset($results['data']['products_price'])) {
        $formattedResponse .= "Prix: {$results['data']['products_price']}\n";
      }

      return $formattedResponse;
    }

    // Cas spécial : uniquement un count sans type
    if (!isset($results['type'], $results['count']) && count($results) === 1) {
      return "Rrésultats : " . $results['count'];
    }

    // Traitement standard pour les autres types de résultats
    if (isset($results['type'])) {
      switch ($results['type']) {

      //  var_dump($results['type']);

        case 'reference_search':
        case 'entity_list':
        // Ok fonctionne
          $formattedResponse .= "Produits trouvés :\n";
          if (isset($results['count'], $results['items']) && $results['count'] > 0) {
            $formattedResponse .= "Nombre de résultats : {$results['count']}\n\n";

            foreach ($results['items'] as $item) {
              $formattedResponse .= "- Products Id: {$item['id']}\n";

              if (isset($item['data']['products_name'])) {
                $formattedResponse .= "- Nom: {$item['data']['products_name']}\n";
              }

              if (isset($item['data']['products_description'])) {
                $formattedResponse .= "- Description : {$item['data']['products_description']}\n";
              }

              if (isset($item['data']['products_price'])) {
                $formattedResponse .= "- Prix HT: {$item['data']['products_price']}\n";
              }

              if (isset($item['data']['products_quantity'])) {
                $formattedResponse .= "- Quantité: {$item['data']['products_quantity']}\n";
              }

              $formattedResponse .= "\n";
            }
          } else {
            $formattedResponse .= "Aucun produit trouvé pour cette référence.\n";
          }
          break;


          // Ok fonctionne
        case 'stock_alert_info':
          $formattedResponse .= "Information de stock d'alerte :\n\n";
          if (isset($results['count'], $results['items']) && $results['count'] > 0) {
            foreach ($results['items'] as $item) {
              $formattedResponse .= "- Produit: {$item['data']['products_name']}\n";
              $formattedResponse .= "- Référence: {$item['data']['products_model']}\n";
              $formattedResponse .= "- Stock actuel: {$item['data']['products_quantity']} unités\n";
              $formattedResponse .= "- Niveau d'alerte: {$item['data']['products_quantity_alert']} unités\n";

              if (isset($item['data']['is_below_alert']) && $item['data']['is_below_alert']) {
                $formattedResponse .= "- ATTENTION: Le stock est inférieur ou égal au niveau d'alerte! : {$item['data']['is_below_alert']} unités \n";
              }

              $formattedResponse .= "\n";
            }
          } else {
            $formattedResponse .= "Aucune information de stock d'alerte trouvée pour cette référence.\n";
          }
          break;








        case 'stock_analysis':
          $formattedResponse .= "Analyse de stock :\n";
          if (isset($results['count'], $results['items']) && $results['count'] > 0) {
            $formattedResponse .= "- Nombre de produits : {$results['count']}\n\n";
            foreach ($results['items'] as $item) {
              $formattedResponse .= "- {$item['data']['products_name']} : {$item['data']['products_quantity']} en stock\n";
            }
          } elseif (isset($results['data']['total_value'])) {
            $formattedResponse .= "- Valeur totale de l'inventaire : {$results['data']['total_value']}\n";
          } else {
            $formattedResponse .= "- Aucune information de stock disponible.\n";
          }
          break;




        case 'orders':
          $formattedResponse .= "Liste des commandes :\n\n";
          if (isset($results['count'], $results['items']) && $results['count'] > 0) {
            $formattedResponse .= "Nombre de commandes : {$results['count']}\n\n";
            foreach ($results['items'] as $item) {
              $formattedResponse .= "- Commande #{$item['id']}\n";
              $formattedResponse .= "  Client: {$item['data']['customer_name']}\n";
              $formattedResponse .= "  Date: {$item['data']['date_purchased']}\n";
              $formattedResponse .= "  Montant: {$item['data']['order_total']}\n";
              $formattedResponse .= "  Statut: {$item['data']['status']}\n\n";
            }
          } else {
            $formattedResponse .= "Aucune commande trouvée.\n";
          }
          break;





        case 'statistical_analysis':
          $formattedResponse .= "Analyse statistique :\n";

          var_dump($results['data']);


          if (isset($results['data']['count'])) {
            $formattedResponse .= "Nombre total : {$results['data']['count']}\n";
          }
          if (isset($results['data']['sum'])) {
            $formattedResponse .= "Somme : {$results['data']['sum']}\n";
          }
          if (isset($results['data']['average'])) {
            $formattedResponse .= "Moyenne : {$results['data']['average']}\n";
          }
          if (isset($results['data']['minimum'])) {
            $formattedResponse .= "Minimum : {$results['data']['minimum']}\n";
          }
          if (isset($results['data']['maximum'])) {
            $formattedResponse .= "Maximum : {$results['data']['maximum']}\n";
          }
          if (empty($results['data'])) {
            $formattedResponse .= "Aucune donnée statistique disponible.\n";
          }
          break;

        case 'error':
          $formattedResponse .= "Erreur : {$results['message']}\n";
          break;

        default:
          $formattedResponse .= "Type de résultat non géré : {$results['type']}\n";
          $formattedResponse .= "Contenu brut des résultats : " . json_encode($results, JSON_PRETTY_PRINT);
          break;
      }

    } else {
      // Si le format n'est pas reconnu, afficher le contenu brut pour le débogage
      $formattedResponse .= "Format de résultat non reconnu. Contenu brut :\n";
      $formattedResponse .= json_encode($results, JSON_PRETTY_PRINT);
    }

    return $formattedResponse;
  }
}



