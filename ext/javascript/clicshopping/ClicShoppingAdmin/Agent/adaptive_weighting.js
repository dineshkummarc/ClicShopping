/**
 * Adaptive Weighting Dashboard JavaScript
 * 
 * Handles export functionality and dynamic interactions for the adaptive weighting dashboard
 */

/**
 * Export adaptive weighting metrics
 * @param {string} format - Export format ('csv' or 'json')
 */
function exportMetrics(format) {
  const urlParams = new URLSearchParams(window.location.search);
  const period = urlParams.get('period') || '7';
  const domain = urlParams.get('domain') || '';
  
  // Get base URL from page configuration
  const baseUrl = typeof CLICSHOPPING_BASE_URL !== 'undefined' 
    ? CLICSHOPPING_BASE_URL 
    : window.location.origin + '/ClicShoppingAdmin/';
  
  // Build export URL
  let exportUrl = baseUrl + 'ajax/ChatGpt/export_adaptive_weighting_metrics.php?format=' + format + '&period=' + period;
  
  if (domain) {
    exportUrl += '&domain=' + encodeURIComponent(domain);
  }
  
  console.log('📥 Exporting adaptive weighting metrics:', { format, period, domain, url: exportUrl });
  
  // Trigger download
  window.location.href = exportUrl;
}

/**
 * Refresh dashboard with new period
 * @param {number} period - Period in days (7, 30, 90)
 */
function changePeriod(period) {
  const urlParams = new URLSearchParams(window.location.search);
  urlParams.set('period', period);
  
  const newUrl = window.location.pathname + '?' + urlParams.toString();
  console.log('🔄 Changing period to:', period, 'days');
  
  window.location.href = newUrl;
}

/**
 * Filter by domain
 * @param {string} domain - Domain name to filter by
 */
function filterByDomain(domain) {
  const urlParams = new URLSearchParams(window.location.search);
  
  if (domain && domain !== '') {
    urlParams.set('domain', domain);
  } else {
    urlParams.delete('domain');
  }
  
  const newUrl = window.location.pathname + '?' + urlParams.toString();
  console.log('🔍 Filtering by domain:', domain);
  
  window.location.href = newUrl;
}

/**
 * Show weight explanation modal
 * @param {string} evaluationId - Evaluation ID to show explanation for
 */
function showWeightExplanation(evaluationId) {
  console.log('📊 Showing weight explanation for evaluation:', evaluationId);
  
  // TODO: Implement modal display with AJAX call to fetch explanation
  // This would be implemented in Phase 5 when we add advanced features
  alert('Weight explanation feature coming soon!\nEvaluation ID: ' + evaluationId);
}

/**
 * Initialize dashboard on page load
 */
document.addEventListener('DOMContentLoaded', function() {
  console.log('✅ Adaptive Weighting Dashboard initialized');
  
  // Add any initialization code here
  // For example: tooltips, popovers, etc.
  
  // Initialize Bootstrap tooltips if present
  if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
      return new bootstrap.Tooltip(tooltipTriggerEl);
    });
  }
});

/**
 * Format weight value for display
 * @param {number} weight - Weight value to format
 * @returns {string} Formatted weight
 */
function formatWeight(weight) {
  return parseFloat(weight).toFixed(4);
}

/**
 * Get badge class for severity
 * @param {string} severity - Severity level
 * @returns {string} Bootstrap badge class
 */
function getSeverityBadgeClass(severity) {
  const severityMap = {
    'critical': 'danger',
    'high': 'warning',
    'medium': 'info',
    'low': 'secondary'
  };
  
  return 'bg-' + (severityMap[severity] || 'secondary');
}

console.log('📦 Adaptive Weighting Dashboard JS loaded');
