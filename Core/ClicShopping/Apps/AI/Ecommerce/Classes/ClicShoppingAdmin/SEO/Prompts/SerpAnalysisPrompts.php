<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\AI\Ecommerce\Classes\ClicShoppingAdmin\SEO\Prompts;

/**
 * SerpAnalysisPrompts
 *
 * LLM prompt templates for SERP analysis in SEO optimization.
 * All prompts are in English for optimal LLM performance.
 *
 * @package ClicShopping\Apps\AI\Ecommerce\Classes\ClicShoppingAdmin\SEO\Prompts
 * @since 2026-03-02
 */
class SerpAnalysisPrompts
{
  /**
   * Get intent analysis prompt
   *
   * @param string $keyword Search keyword
   * @param string $entityType Entity type (category, product, etc.)
   * @param string $entityName Entity name
   * @param string $serpResults SERP results as text
   * @return string Prompt for LLM
   */
  public static function getIntentAnalysisPrompt(
    string $keyword,
    string $entityType,
    string $entityName,
    string $serpResults
  ): string
  {
    return <<<PROMPT
Internal processing must be in English.
Analyze the following search results and classify the search intent.

Keyword: {$keyword}
Entity Type: {$entityType}
Entity Name: {$entityName}

Search Results:
{$serpResults}

Classify the intent as one of:
- transactional: User wants to buy/purchase
- informational: User wants to learn/research
- navigational: User wants to find a specific site
- local: User wants to find local businesses/services

Provide your classification with reasoning in JSON format:
{
  "intent": "transactional|informational|navigational|local",
  "confidence": 0.0-1.0,
  "reasoning": "explanation of why this intent was chosen"
}

Return ONLY the JSON object, no explanations.
PROMPT;
  }

  /**
   * Get feature detection prompt
   *
   * @param string $serpData SERP data as JSON or text
   * @return string Prompt for LLM
   */
  public static function getFeatureDetectionPrompt(string $serpData): string
  {
    return <<<PROMPT
Internal processing must be in English.
Analyze the following SERP data and identify which features are present.

SERP Data:
{$serpData}

Identify presence of:
- people_also_ask: Questions users commonly ask
- reviews: Product/service reviews
- shopping_results: Shopping ads or product listings
- ai_overview: Google AI-generated summary
- featured_snippet: Featured answer box
- local_pack: Local business results
- knowledge_panel: Information box on the right side
- image_pack: Image results
- video_results: Video results

Return JSON with boolean flags for each feature:
{
  "people_also_ask": true|false,
  "reviews": true|false,
  "shopping_results": true|false,
  "ai_overview": true|false,
  "featured_snippet": true|false,
  "local_pack": true|false,
  "knowledge_panel": true|false,
  "image_pack": true|false,
  "video_results": true|false
}

Return ONLY the JSON object, no explanations.
PROMPT;
  }

  /**
   * Get topic extraction prompt
   *
   * @param string $serpResults SERP results as text
   * @return string Prompt for LLM
   */
  public static function getTopicExtractionPrompt(string $serpResults): string
  {
    return <<<PROMPT
Internal processing must be in English.
Analyze the top search results and extract the main topics and themes.

Search Results:
{$serpResults}

Extract:
1. Main topics covered across results (3-5 topics)
2. Common keywords and phrases (5-10 keywords)
3. Content structure patterns (headings, sections)
4. User questions being answered (if any)

Return structured data in JSON format:
{
  "topics": ["topic1", "topic2", "topic3"],
  "keywords": ["keyword1", "keyword2", "keyword3"],
  "content_patterns": ["pattern1", "pattern2"],
  "user_questions": ["question1", "question2"]
}

Return ONLY the JSON object, no explanations.
PROMPT;
  }

  /**
   * Get competitor analysis prompt
   *
   * @param string $serpResults SERP results as text
   * @param string $keyword Search keyword
   * @return string Prompt for LLM
   */
  public static function getCompetitorAnalysisPrompt(
    string $serpResults,
    string $keyword
  ): string
  {
    return <<<PROMPT
Internal processing must be in English.
Analyze the top search results to identify competitor strategies.

Keyword: {$keyword}

Search Results:
{$serpResults}

Analyze:
1. Common title patterns used by top results
2. Common description patterns
3. Content types (product pages, articles, guides, etc.)
4. Unique selling points mentioned
5. Call-to-action patterns

Return structured data in JSON format:
{
  "title_patterns": ["pattern1", "pattern2"],
  "description_patterns": ["pattern1", "pattern2"],
  "content_types": {"product": 3, "article": 2, "guide": 1},
  "selling_points": ["point1", "point2"],
  "cta_patterns": ["cta1", "cta2"]
}

Return ONLY the JSON object, no explanations.
PROMPT;
  }

  /**
   * Get page type classification prompt for a SINGLE page.
   * Kept for backward compatibility — prefer getBatchPageTypePrompt() for SERP lists.
   *
   * @param string $url Page URL
   * @param string $title Page title
   * @param string $snippet Page snippet
   * @return string Prompt for LLM
   */
  public static function getPageTypePrompt(
    string $url,
    string $title,
    string $snippet
  ): string
  {
    return <<<PROMPT
Internal processing must be in English.
Classify the type of page based on the following information.

URL: {$url}
Title: {$title}
Snippet: {$snippet}

Classify as one of:
- product: Product page
- category: Category/collection page
- article: Blog article or guide
- forum: Forum or community page
- marketplace: Marketplace listing
- homepage: Homepage or landing page
- other: Other type

Return JSON:
{
  "page_type": "product|category|article|forum|marketplace|homepage|other",
  "confidence": 0.0-1.0
}

Return ONLY the JSON object, no explanations.
PROMPT;
  }

  /**
   * Get batch page type classification prompt for all SERP results in a single LLM call.
   * Use this instead of calling getPageTypePrompt() N times in a loop.
   *
   * @param array $items Array of SERP items, each with 'link', 'title', 'snippet' keys.
   * @return string Prompt for LLM
   */
  public static function getBatchPageTypePrompt(array $items): string
  {
    $lines = '';
    foreach ($items as $i => $item) {
      $position = $i + 1;
      $url      = (string)($item['link']    ?? '');
      $title    = (string)($item['title']   ?? '');
      $snippet  = (string)($item['snippet'] ?? '');
      $lines   .= "Result {$position}:\n  URL: {$url}\n  Title: {$title}\n  Snippet: {$snippet}\n\n";
    }

    return <<<PROMPT
Internal processing must be in English.
Classify the type of each page listed below.

Valid types:
- product: Product page
- category: Category/collection page
- article: Blog article or guide
- forum: Forum or community page
- marketplace: Marketplace listing
- homepage: Homepage or landing page
- other: Other type

Pages to classify:
{$lines}
Return a JSON array — one object per page in the same order, indexed from 1:
{
  "1": {"page_type": "product|category|article|forum|marketplace|homepage|other", "confidence": 0.0-1.0},
  "2": {"page_type": "...", "confidence": 0.0-1.0}
}

Return ONLY the JSON object, no explanations.
PROMPT;
  }

  /**
   * Get SERP stability analysis prompt
   *
   * @param array $domains List of domains in SERP
   * @return string Prompt for LLM
   */
  public static function getStabilityAnalysisPrompt(array $domains): string
  {
    $domainList = implode("\n", $domains);
    
    return <<<PROMPT
Internal processing must be in English.
Analyze the SERP stability based on the following domains appearing in search results.

Domains:
{$domainList}

Analyze:
1. Domain diversity (how many unique domains)
2. Presence of major players (Amazon, Wikipedia, etc.)
3. Niche vs. general sites
4. Estimated SERP volatility

Return JSON:
{
  "stability": "stable|moderate|volatile",
  "score": 0.0-1.0,
  "reasoning": "explanation",
  "major_players": ["domain1", "domain2"],
  "diversity_score": 0.0-1.0
}

Return ONLY the JSON object, no explanations.
PROMPT;
  }

  /**
   * Get cannibalization detection prompt
   *
   * @param array $results SERP results
   * @param string $baseDomain Base domain to check
   * @return string Prompt for LLM
   */
  public static function getCannibalizationPrompt(
    array $results,
    string $baseDomain
  ): string
  {
    $resultsList = '';
    foreach ($results as $i => $result) {
      $resultsList .= ($i + 1) . ". " . ($result['title'] ?? '') . " - " . ($result['link'] ?? '') . "\n";
    }
    
    return <<<PROMPT
Internal processing must be in English.
Analyze potential keyword cannibalization for domain: {$baseDomain}

Search Results:
{$resultsList}

Analyze:
1. How many results are from {$baseDomain}
2. Are they targeting the same or different aspects of the keyword
3. Risk level of cannibalization

Return JSON:
{
  "risk": "none|low|medium|high",
  "matching_results": 0,
  "pages": [
    {
      "url": "...",
      "title": "...",
      "aspect": "what aspect of keyword it targets"
    }
  ],
  "recommendation": "what to do about it"
}

Return ONLY the JSON object, no explanations.
PROMPT;
  }

  /**
   * Get AI Overview analysis prompt
   *
   * @param string $summary AI Overview summary text
   * @param string $query Search query
   * @param string $entityType Entity type
   * @return string Prompt text
   */
  public static function getAiOverviewAnalysisPrompt(
    string $summary,
    string $query,
    string $entityType
  ): string
  {
    return <<<PROMPT
Analyze the following Google AI Overview and extract key insights for SEO optimization.

Search Query: {$query}
Entity Type: {$entityType}

AI Overview Summary:
{$summary}

Extract the following insights:

1. **Key Points**: List 3-5 main points covered in the AI Overview
2. **Relevance Score**: Rate how relevant this AI Overview is to the query (0.0-1.0)
3. **Content Gaps**: Identify topics mentioned in AI Overview that might be missing from typical content
4. **Opportunities**: Suggest how to leverage these insights for SEO content
5. **User Intent Match**: Explain what user intent this AI Overview addresses

Return your analysis as JSON:
{
  "key_points": ["point 1", "point 2", ...],
  "relevance_score": 0.85,
  "content_gaps": ["gap 1", "gap 2", ...],
  "opportunities": ["opportunity 1", "opportunity 2", ...],
  "user_intent_match": "explanation of user intent"
}

Return ONLY the JSON object, no explanations.
PROMPT;
  }
}
