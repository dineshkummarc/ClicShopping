<?php
/**
 * Common Tables Configuration for Schema RAG
 * 
 * This file defines the most frequently used tables that serve as
 * a fallback when no specific tables can be determined from the query.
 * 
 * These tables are returned when:
 * - Embedding-based retrieval fails
 * - Keyword matching finds no matches
 * - Query is too vague or generic
 * 
 * Order matters: Most important tables should be listed first.
 */

return [
  // Core e-commerce tables (most frequently queried)
  'clic_products',
  'clic_products_description',
  'clic_orders',
  'clic_customers',
  'clic_categories',
  
  // Secondary important tables
  'clic_manufacturers',
  'clic_suppliers',
  'clic_reviews',
  'clic_orders_products',
  'clic_address_book',
  
  // Additional useful tables
  'clic_categories_description',
  'clic_return_orders',
  'clic_reviews_sentiment',
  'clic_specials',
  'clic_products_attributes',
];
