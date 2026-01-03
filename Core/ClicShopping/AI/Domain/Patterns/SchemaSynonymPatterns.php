<?php
/**
 * Synonym Configuration for Schema RAG
 * 
 * This file contains multilingual synonyms used for keyword matching
 * in the dynamic schema retrieval system.
 * 
 * @package ClicShopping\AI\Domain\Patterns
 */

namespace ClicShopping\AI\Domain\Patterns;

/**
 * SchemaSynonymPatterns
 * 
 * Provides multilingual synonyms for Schema RAG keyword matching
 * 
 * Format: 'keyword' => ['synonym1', 'synonym2', 'synonym3']
 * 
 * Rules:
 * - Include both French and English terms
 * - Add variations (singular/plural, masculine/feminine)
 * - Keep bidirectional mappings (if A→B, then B→A)
 */
class SchemaSynonymPatterns
{
  /**
   * Get all synonyms
   * 
   * @return array Synonym mappings
   */
  public static function getSynonyms(): array
  {
    return [
      // Weight / Poids
      'poids' => ['weight', 'poids', 'masse'],
      'weight' => ['weight', 'poids', 'masse'],
      'masse' => ['weight', 'poids', 'masse'],
      
      // Price / Prix
      'prix' => ['price', 'prix', 'cost', 'tarif', 'cout'],
      'price' => ['price', 'prix', 'cost', 'tarif', 'cout'],
      'cost' => ['price', 'prix', 'cost', 'tarif', 'cout'],
      'tarif' => ['price', 'prix', 'cost', 'tarif', 'cout'],
      'cout' => ['price', 'prix', 'cost', 'tarif', 'cout'],
      
      // Product / Produit
      'produit' => ['product', 'produit', 'item', 'article'],
      'product' => ['product', 'produit', 'item', 'article'],
      'item' => ['product', 'produit', 'item', 'article'],
      'article' => ['product', 'produit', 'item', 'article'],
      
      // Category / Catégorie
      'categorie' => ['category', 'categorie', 'categ'],
      'category' => ['category', 'categorie', 'categ'],
      'categ' => ['category', 'categorie', 'categ'],
      
      // Order / Commande
      'commande' => ['order', 'commande', 'purchase', 'achat'],
      'order' => ['order', 'commande', 'purchase', 'achat'],
      'purchase' => ['order', 'commande', 'purchase', 'achat'],
      'achat' => ['order', 'commande', 'purchase', 'achat'],
      
      // Customer / Client
      'client' => ['customer', 'client', 'buyer', 'acheteur'],
      'customer' => ['customer', 'client', 'buyer', 'acheteur'],
      'buyer' => ['customer', 'client', 'buyer', 'acheteur'],
      'acheteur' => ['customer', 'client', 'buyer', 'acheteur'],
      
      // Address / Adresse
      'adresse' => ['address', 'adresse', 'location'],
      'address' => ['address', 'adresse', 'location'],
      'location' => ['address', 'adresse', 'location'],
      
      // Stock / Inventory
      'stock' => ['stock', 'inventory', 'quantity', 'quantite'],
      'inventory' => ['stock', 'inventory', 'quantity', 'quantite'],
      'quantity' => ['stock', 'inventory', 'quantity', 'quantite'],
      'quantite' => ['stock', 'inventory', 'quantity', 'quantite'],
      
      // Minimum / Minimale
      'minimum' => ['minimum', 'mini', 'minimale', 'minimal'],
      'minimale' => ['minimum', 'mini', 'minimale', 'minimal'],
      'minimal' => ['minimum', 'mini', 'minimale', 'minimal'],
      'mini' => ['minimum', 'mini', 'minimale', 'minimal'],
      
      // Dimension / Size
      'dimension' => ['dimension', 'width', 'height', 'depth', 'largeur', 'hauteur', 'profondeur', 'size', 'taille'],
      'dimensions' => ['dimension', 'width', 'height', 'depth', 'largeur', 'hauteur', 'profondeur', 'size', 'taille'],
      'width' => ['dimension', 'width', 'largeur'],
      'height' => ['dimension', 'height', 'hauteur'],
      'depth' => ['dimension', 'depth', 'profondeur'],
      'largeur' => ['dimension', 'width', 'largeur'],
      'hauteur' => ['dimension', 'height', 'hauteur'],
      'profondeur' => ['dimension', 'depth', 'profondeur'],
      'size' => ['dimension', 'width', 'height', 'depth', 'size', 'taille'],
      'taille' => ['dimension', 'width', 'height', 'depth', 'size', 'taille'],
      
      // Volume / Capacity
      'volume' => ['volume', 'capacity', 'capacite'],
      'capacity' => ['volume', 'capacity', 'capacite'],
      'capacite' => ['volume', 'capacity', 'capacite'],
      
      // Alert / Alerte
      'alerte' => ['alert', 'alerte', 'warning', 'threshold', 'seuil'],
      'alert' => ['alert', 'alerte', 'warning', 'threshold', 'seuil'],
      'warning' => ['alert', 'alerte', 'warning', 'threshold', 'seuil'],
      'threshold' => ['alert', 'alerte', 'warning', 'threshold', 'seuil'],
      'seuil' => ['alert', 'alerte', 'warning', 'threshold', 'seuil'],
      
      // Manufacturer / Fabricant
      'fabricant' => ['manufacturer', 'fabricant', 'maker', 'brand', 'marque'],
      'manufacturer' => ['manufacturer', 'fabricant', 'maker', 'brand', 'marque'],
      'maker' => ['manufacturer', 'fabricant', 'maker', 'brand', 'marque'],
      'brand' => ['manufacturer', 'fabricant', 'maker', 'brand', 'marque'],
      'marque' => ['manufacturer', 'fabricant', 'maker', 'brand', 'marque'],
      
      // Supplier / Fournisseur
      'fournisseur' => ['supplier', 'fournisseur', 'vendor'],
      'supplier' => ['supplier', 'fournisseur', 'vendor'],
      'vendor' => ['supplier', 'fournisseur', 'vendor'],
      
      // Review / Avis
      'avis' => ['review', 'avis', 'rating', 'note', 'comment', 'commentaire'],
      'review' => ['review', 'avis', 'rating', 'note', 'comment', 'commentaire'],
      'rating' => ['review', 'avis', 'rating', 'note'],
      'note' => ['review', 'avis', 'rating', 'note'],
      'comment' => ['review', 'avis', 'comment', 'commentaire'],
      'commentaire' => ['review', 'avis', 'comment', 'commentaire'],
      
      // Return / Retour
      'retour' => ['return', 'retour', 'refund', 'remboursement'],
      'return' => ['return', 'retour', 'refund', 'remboursement'],
      'refund' => ['return', 'retour', 'refund', 'remboursement'],
      'remboursement' => ['return', 'retour', 'refund', 'remboursement'],
      
      // Sentiment / Opinion
      'sentiment' => ['sentiment', 'opinion', 'feeling'],
      'opinion' => ['sentiment', 'opinion', 'feeling'],
      'feeling' => ['sentiment', 'opinion', 'feeling'],
    ];
  }
}
