<?php
/**
 * Fallback Keyword Patterns Configuration for Schema RAG
 * 
 * This file defines regex patterns that map query keywords to relevant tables.
 * Used as a fallback when embedding-based retrieval fails.
 * 
 * Format: 'regex_pattern' => ['table1', 'table2', 'table3']
 * 
 * Rules:
 * - Use pipe (|) for OR conditions
 * - Patterns are case-insensitive
 * - More specific patterns should come first
 * - Include both French and English terms
 */

return [
  // Stock and inventory
  'stock|inventory|quantity|quantité|disponible|available' => [
    'clic_products',
    'clic_products_description',
    'clic_products_quantity_unit',
  ],
  
  // Orders and purchases
  'order|commande|sale|vente|purchase|achat' => [
    'clic_orders',
    'clic_orders_products',
    'clic_orders_total',
    'clic_customers',
  ],
  
  // Pricing
  'price|prix|cost|coût|tarif|montant|amount' => [
    'clic_products',
    'clic_specials',
    'clic_products_description',
    'clic_orders_products',
  ],
  
  // Customers
  'customer|client|buyer|acheteur|utilisateur|user' => [
    'clic_customers',
    'clic_customers_info',
    'clic_orders',
    'clic_address_book',
  ],
  
  // Categories
  'category|categorie|catégorie|classification' => [
    'clic_categories',
    'clic_categories_description',
    'clic_products_to_categories',
  ],
  
  // Manufacturers and brands
  'brand|manufacturer|marque|fabricant|maker' => [
    'clic_manufacturers',
    'clic_manufacturers_info',
    'clic_products',
  ],
  
  // Suppliers
  'supplier|fournisseur|vendor|vendeur' => [
    'clic_suppliers',
    'clic_suppliers_info',
    'clic_manufacturers',
  ],
  
  // Reviews and ratings
  'review|avis|rating|note|comment|commentaire|feedback' => [
    'clic_reviews',
    'clic_reviews_description',
    'clic_products',
    'clic_feedback_order_reviews',
  ],
  
  // Returns and refunds
  'return|retour|refund|remboursement' => [
    'clic_return_orders',
    'clic_orders',
    'clic_products',
  ],
  
  // Sentiment analysis
  'sentiment|opinion|feeling|ressenti' => [
    'clic_reviews_sentiment',
    'clic_reviews_sentiment_description',
    'clic_reviews',
  ],
  
  // Addresses and locations
  'address|adresse|location|lieu|livraison|delivery' => [
    'clic_address_book',
    'clic_orders',
    'clic_customers',
  ],
  
  // Product attributes
  'attribute|attribut|option|caractéristique|feature' => [
    'clic_products_attributes',
    'clic_products_options',
    'clic_products',
  ],
  
  // Discounts and promotions
  'discount|remise|promotion|promo|special|spécial' => [
    'clic_specials',
    'clic_products_discount_quantity',
    'clic_discount_coupons',
    'clic_products',
  ],
];
