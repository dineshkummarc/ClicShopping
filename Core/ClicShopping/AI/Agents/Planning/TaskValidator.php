<?php
/**
 * TaskValidator - Validateurs pour plans déterministes
 * 
 * Gère la validation des données externes et les politiques d'accès
 * Assure un comportement déterministe même si les sources externes échouent
 */

namespace ClicShopping\AI\Agents\Planning;

use AllowDynamicProperties;

#[AllowDynamicProperties]
class TaskValidator
{
    /**
     * Vérifie si des données concurrentes externes valides sont disponibles
     * 
     * @param array|null $competitorData Données concurrents
     * @return array [bool $ok, array $reasoning]
     */
    public static function validateCompetitorDataAvailability(?array $competitorData): array
    {
        $reasoning = [];
        
        if (is_null($competitorData)) {
            $reasoning[] = 'no_competitor_payload';
            return [false, $reasoning];
        }
        
        if (!is_array($competitorData) || empty($competitorData)) {
            $reasoning[] = 'empty_competitor_dataset';
            return [false, $reasoning];
        }
        
        // Vérifier structure minimale attendue
        $sample = reset($competitorData);
        if (!is_array($sample) || !array_key_exists('name', $sample) || !array_key_exists('price', $sample)) {
            $reasoning[] = 'invalid_competitor_record_schema';
            return [false, $reasoning];
        }
        
        // Valeurs suspectes
        foreach ($competitorData as $rec) {
            if (!is_numeric($rec['price']) && !is_null($rec['price'])) {
                $reasoning[] = 'non_numeric_price_found';
                return [false, $reasoning];
            }
        }
        
        // Si tout passe
        return [true, ['validated_competitor_dataset']];
    }
    
    /**
     * Politique : autoriser/rejeter l'appel vers sources externes
     * 
     * @param array $policy Configuration de politique
     * @return bool True si accès externe autorisé
     */
    public static function externalAccessAllowed(array $policy): bool
    {
        // Politique minimale : présence d'un flag explicite
        if (isset($policy['allow_external']) && $policy['allow_external'] === true) {
            return true;
        }
        
        // Vérifier si SerpApi est configuré
        if (isset($policy['serpapi_configured']) && $policy['serpapi_configured'] === true) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Valide la disponibilité du cache interne concurrent
     * 
     * @param array|null $internalCache Cache interne
     * @return array [bool $ok, array $data]
     */
    public static function validateInternalCompetitorCache(?array $internalCache): array
    {
        if (is_null($internalCache) || empty($internalCache)) {
            return [false, []];
        }
        
        // Filtrer les données valides
        $validData = [];
        foreach ($internalCache as $item) {
            if (is_array($item) && isset($item['name']) && isset($item['price'])) {
                $validData[] = $item;
            }
        }
        
        return [count($validData) > 0, $validData];
    }
    
    /**
     * Détermine la stratégie de fallback pour step_2
     * 
     * @param array $context Contexte d'exécution
     * @return array [string $strategy, array $data]
     */
    public static function determineFallbackStrategy(array $context): array
    {
        $policy = $context['policy'] ?? ['allow_external' => false];
        
        // Si externe bloqué par politique
        if (!self::externalAccessAllowed($policy)) {
            [$hasCache, $cacheData] = self::validateInternalCompetitorCache(
                $context['internal_competitor_cache'] ?? null
            );
            
            if ($hasCache) {
                return ['internal_cache', $cacheData];
            } else {
                return ['no_data', []];
            }
        }
        
        // Si externe autorisé mais données invalides
        $competitorPayload = $context['competitor_payload'] ?? null;
        [$isValid, $reasoning] = self::validateCompetitorDataAvailability($competitorPayload);
        
        if (!$isValid) {
            [$hasCache, $cacheData] = self::validateInternalCompetitorCache(
                $context['internal_competitor_cache'] ?? null
            );
            
            if ($hasCache) {
                return ['fallback_to_cache', $cacheData];
            } else {
                return ['external_failed_no_cache', []];
            }
        }
        
        return ['external_valid', $competitorPayload];
    }
}