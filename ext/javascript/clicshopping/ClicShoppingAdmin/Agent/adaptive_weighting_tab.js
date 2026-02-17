/**
 * Adaptive Weighting Tab JavaScript
 * Handles loading and displaying adaptive weighting data in AgentActorCritic dashboard
 */

(function() {
  'use strict';

  // Initialize when tab is shown
  document.addEventListener('DOMContentLoaded', function() {
    console.log('Adaptive Weighting Tab: Initializing...');
    
    if (!window.AdaptiveWeightingConfig) {
      console.log('Adaptive Weighting Tab: Config not found');
      return;
    }

    // Load data when tab is clicked
    const tab = document.getElementById('adaptive-weighting-tab');
    if (tab) {
      tab.addEventListener('shown.bs.tab', function() {
        console.log('Adaptive Weighting Tab: Tab shown, loading data...');
        loadAdaptiveWeightingData();
      });
    }

    // Also load if tab is already active
    if (tab && tab.classList.contains('active')) {
      loadAdaptiveWeightingData();
    }
  });

  function loadAdaptiveWeightingData() {
    loadAdaptiveWeights();
    loadConsensusComparison();
  }

  function loadAdaptiveWeights() {
    console.log('Adaptive Weighting: Loading weights...');
    
    const endpoint = window.AdaptiveWeightingConfig.baseUrl + window.AdaptiveWeightingConfig.weightsEndpoint;
    
    fetch(endpoint + '?limit=20')
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
      })
      .then(data => {
        console.log('Adaptive Weighting: Weights data received', data);
        
        if (data.success) {
          updateWeightsDisplay(data.data);
        } else {
          console.error('Adaptive Weighting: API error', data.error);
          showError('weights', data.error);
        }
      })
      .catch(error => {
        console.error('Adaptive Weighting: Fetch error', error);
        showError('weights', error.message);
      });
  }

  function loadConsensusComparison() {
    console.log('Adaptive Weighting: Loading consensus...');
    
    const endpoint = window.AdaptiveWeightingConfig.baseUrl + window.AdaptiveWeightingConfig.consensusEndpoint;
    
    fetch(endpoint + '?limit=20')
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
      })
      .then(data => {
        console.log('Adaptive Weighting: Consensus data received', data);
        
        if (data.success) {
          updateConsensusDisplay(data.data);
        } else {
          console.error('Adaptive Weighting: API error', data.error);
          showError('consensus', data.error);
        }
      })
      .catch(error => {
        console.error('Adaptive Weighting: Fetch error', error);
        showError('consensus', error.message);
      });
  }

  function updateWeightsDisplay(data) {
    const weights = data.weights || [];
    const stats = data.statistics || {};
    
    // Update statistics
    updateElement('stat-total-weights', stats.total || 0);
    updateElement('stat-avg-weight', (stats.avg_weight || 0).toFixed(4));
    
    // Update table
    const tbody = document.getElementById('adaptive-weights-tbody');
    if (!tbody) {
      console.warn('Adaptive Weighting: Weights tbody not found');
      return;
    }

    if (weights.length === 0) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center">No adaptive weights found</td></tr>';
      return;
    }

    tbody.innerHTML = weights.map(w => `
      <tr>
        <td>${escapeHtml(w.evaluation_id).substring(0, 20)}...</td>
        <td>${escapeHtml(w.critic_id)}</td>
        <td>${(w.raw_weight || 0).toFixed(4)}</td>
        <td><strong>${(w.normalized_weight || 0).toFixed(4)}</strong></td>
        <td>${escapeHtml(w.llm_explanation || 'N/A').substring(0, 50)}...</td>
        <td>${formatDate(w.created_at)}</td>
      </tr>
    `).join('');
  }

  function updateConsensusDisplay(data) {
    const sessions = Array.isArray(data) ? data : (data.data || []);
    
    // Update statistics
    updateElement('stat-consensus-count', sessions.length);
    
    if (sessions.length > 0) {
      const avgDiff = sessions.reduce((sum, s) => sum + Math.abs(s.difference || 0), 0) / sessions.length;
      updateElement('stat-avg-difference', (avgDiff * 100).toFixed(2) + '%');
    }
    
    // Update table
    const tbody = document.getElementById('consensus-comparison-tbody');
    if (!tbody) {
      console.warn('Adaptive Weighting: Consensus tbody not found');
      return;
    }

    if (sessions.length === 0) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center">No consensus sessions found</td></tr>';
      return;
    }

    tbody.innerHTML = sessions.map(s => {
      const diffPercent = ((s.difference_percent || (s.difference * 100))).toFixed(2);
      const diffClass = Math.abs(s.difference) > 0.05 ? 'text-warning' : 'text-success';
      
      return `
      <tr>
        <td>${escapeHtml(s.evaluation_id || '').substring(0, 20)}...</td>
        <td>${(s.dynamic_consensus || 0).toFixed(3)}</td>
        <td>${(s.static_consensus || 0).toFixed(3)}</td>
        <td class="${diffClass}">${(s.difference || 0).toFixed(3)}</td>
        <td class="${diffClass}">${diffPercent}%</td>
        <td>${formatDate(s.created_at)}</td>
      </tr>
      `;
    }).join('');
  }

  function updateElement(id, value) {
    const element = document.getElementById(id);
    if (element) {
      element.textContent = value;
    }
  }

  function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleString();
  }

  function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function showError(type, message) {
    const tbody = document.getElementById(type === 'weights' ? 'adaptive-weights-tbody' : 'consensus-comparison-tbody');
    if (tbody) {
      tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">Error: ${escapeHtml(message)}</td></tr>`;
    }
  }

  console.log('Adaptive Weighting Tab: Script loaded');
})();
