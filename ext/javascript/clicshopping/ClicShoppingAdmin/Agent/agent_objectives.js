/**
 * Agent Objectives Dashboard JavaScript
 * Handles loading and displaying agent objectives data
 */

(function() {
  'use strict';

  let allObjectives = [];

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAgentObjectives);
  } else {
    initAgentObjectives();
  }

  function initAgentObjectives() {
    console.log('Agent Objectives: Initializing...');
    
    // Check if we're on the objectives page
    if (!window.AgentObjectivesConfig) {
      console.log('Agent Objectives: Config not found, skipping initialization');
      return;
    }

    loadObjectives();
    
    // Refresh every 30 seconds
    setInterval(loadObjectives, 30000);
  }

  function getFilters() {
    return {
      agent_id: (document.getElementById('filter-agent')?.value || '').trim(),
      status: (document.getElementById('filter-status')?.value || '').trim(),
      priority: (document.getElementById('filter-priority')?.value || '').trim(),
    };
  }

  function loadObjectives(filters = null) {
    console.log('Agent Objectives: Loading data...');
    
    const endpoint = window.AgentObjectivesConfig.baseUrl + window.AgentObjectivesConfig.objectivesEndpoint;
    const url = new URL(endpoint, window.location.origin);
    const activeFilters = filters || null;
    if (activeFilters) {
      Object.keys(activeFilters).forEach(key => {
        if (activeFilters[key]) {
          url.searchParams.set(key, activeFilters[key]);
        }
      });
    }
    
    fetch(url.toString())
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
      })
      .then(data => {
        console.log('Agent Objectives: Data received', data);
        
        if (data.success) {
          updateObjectivesDisplay(data.data);
        } else {
          console.error('Agent Objectives: API returned error', data.error);
          showError('Failed to load objectives: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(error => {
        console.error('Agent Objectives: Fetch error', error);
        showError('Failed to load objectives: ' + error.message);
      });
  }

  function updateObjectivesDisplay(data) {
    console.log('Agent Objectives: Updating display with', data);
    
    const objectives = data.objectives || [];
    allObjectives = objectives;
    
    // Calculate summary from objectives if not provided
    const summary = data.summary || calculateSummary(objectives);
    
    // Update summary cards
    updateSummaryCards(summary);
    
    // Update single objectives table with all objectives
    updateObjectivesTable(allObjectives);
  }

  function calculateSummary(objectives) {
    const summary = {
      total: objectives.length,
      active: 0,
      completed: 0,
      failed: 0,
      pending: 0
    };
    
    objectives.forEach(obj => {
      const status = obj.status || '';
      if (summary.hasOwnProperty(status)) {
        summary[status]++;
      }
    });
    
    return summary;
  }

  function updateSummaryCards(summary) {
    const cards = {
      'total-objectives': summary.total || 0,
      'active-objectives': summary.active || 0,
      'completed-objectives': summary.completed || 0,
      'failed-objectives': summary.failed || 0
    };

    Object.keys(cards).forEach(id => {
      const element = document.getElementById(id);
      if (element) {
        element.textContent = cards[id];
      }
    });
  }

  function updateObjectivesTable(objectives) {
    const tbody = document.getElementById('objectives-tbody');
    if (!tbody) {
      console.warn('Agent Objectives: Table body not found');
      return;
    }

    if (objectives.length === 0) {
      tbody.innerHTML = '<tr><td colspan="8" class="text-center">No objectives found</td></tr>';
      return;
    }

    tbody.innerHTML = objectives.map(obj => `
      <tr>
        <td>${escapeHtml(obj.objective_id || '').substring(0, 12)}...</td>
        <td>${escapeHtml(obj.agent_id || '')}</td>
        <td>${escapeHtml(obj.goal_statement || '')}</td>
        <td><span class="badge bg-${getPriorityClass(obj.priority)}">${escapeHtml(obj.priority || '')}</span></td>
        <td><span class="badge bg-${getStatusClass(obj.status)}">${escapeHtml(obj.status || '')}</span></td>
        <td>${formatDate(obj.created_at)}</td>
        <td>${obj.estimated_completion_time ? formatDuration(obj.estimated_completion_time) : 'N/A'}</td>
        <td>
          <button class="btn btn-sm btn-info" onclick="viewObjectiveDetails('${obj.objective_id}')">
            <i class="bi bi-eye"></i> View
          </button>
        </td>
      </tr>
    `).join('');
  }

  function applyFiltersToObjectives(objectives) {
    const agentFilter = (document.getElementById('filter-agent')?.value || '').trim().toLowerCase();
    const statusFilter = (document.getElementById('filter-status')?.value || '').trim().toLowerCase();
    const priorityFilter = (document.getElementById('filter-priority')?.value || '').trim().toLowerCase();

    return objectives.filter(obj => {
      const agentValue = (obj.agent_id || '').toLowerCase();
      const statusValue = (obj.status || '').toLowerCase();
      const priorityValue = (obj.priority || '').toLowerCase();

      const agentOk = !agentFilter || agentValue === agentFilter || agentValue.includes(agentFilter);
      const statusOk = !statusFilter || statusValue === statusFilter || statusValue.includes(statusFilter);
      const priorityOk = !priorityFilter || priorityValue === priorityFilter || priorityValue.includes(priorityFilter);
      return agentOk && statusOk && priorityOk;
    });
  }

  function getStatusClass(status) {
    const classes = {
      'active': 'primary',
      'completed': 'success',
      'failed': 'danger',
      'pending': 'warning',
      'approved': 'info',
      'cancelled': 'secondary'
    };
    return classes[status] || 'secondary';
  }

  function getPriorityClass(priority) {
    const classes = {
      'critical': 'danger',
      'high': 'warning',
      'medium': 'info',
      'low': 'secondary'
    };
    return classes[priority] || 'secondary';
  }

  function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleString();
  }

  function formatDuration(seconds) {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    return `${hours}h ${minutes}m`;
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function showError(message) {
    console.error('Agent Objectives: Error -', message);
    // You can add a toast notification here if available
  }

  window.applyFilters = function() {
    updateObjectivesTable(applyFiltersToObjectives(allObjectives));
  };

  window.clearFilters = function() {
    const agent = document.getElementById('filter-agent');
    const status = document.getElementById('filter-status');
    const priority = document.getElementById('filter-priority');

    if (agent) agent.value = '';
    if (status) status.value = '';
    if (priority) priority.value = '';

    updateObjectivesTable(allObjectives);
  };

  // Make viewObjectiveDetails available globally
  window.viewObjectiveDetails = function(objectiveId) {
    console.log('Viewing objective:', objectiveId);
    
    // Fetch objective details
    const endpoint = window.AgentObjectivesConfig.baseUrl + 
                    window.AgentObjectivesConfig.objectivesEndpoint + 
                    '?objective_id=' + objectiveId;
    
    fetch(endpoint)
      .then(response => response.json())
      .then(data => {
        if (data.success && data.data.objectives.length > 0) {
          const objective = data.data.objectives[0];
          showObjectiveModal(objective);
        } else {
          alert('Objectif non trouvé');
        }
      })
      .catch(error => {
        console.error('Error loading objective details:', error);
        alert('Erreur lors du chargement des détails');
      });
  };

  function showObjectiveModal(objective) {
    const modalBody = document.getElementById('objective-details-body');
    if (!modalBody) {
      console.error('Modal body not found');
      return;
    }
    
    modalBody.innerHTML = `
      <div class="row">
        <div class="col-md-6">
          <h5>Informations Générales</h5>
          <table class="table table-sm">
            <tr><th>ID:</th><td>${escapeHtml(objective.objective_id)}</td></tr>
            <tr><th>Agent:</th><td>${escapeHtml(objective.agent_id)}</td></tr>
            <tr><th>Priorité:</th><td><span class="badge bg-${getPriorityClass(objective.priority)}">${escapeHtml(objective.priority)}</span></td></tr>
            <tr><th>Statut:</th><td><span class="badge bg-${getStatusClass(objective.status)}">${escapeHtml(objective.status)}</span></td></tr>
            <tr><th>Créé:</th><td>${formatDate(objective.created_at)}</td></tr>
            <tr><th>Échéance:</th><td>${formatDate(objective.target_completion_date)}</td></tr>
          </table>
        </div>
        <div class="col-md-6">
          <h5>Métriques</h5>
          <table class="table table-sm">
            <tr><th>Progression:</th><td>${(objective.progress_percentage || 0).toFixed(1)}%</td></tr>
            <tr><th>Temps estimé:</th><td>${objective.estimated_completion_time ? formatDuration(objective.estimated_completion_time) : 'N/A'}</td></tr>
            <tr><th>Temps réel:</th><td>${objective.actual_completion_time ? formatDuration(objective.actual_completion_time) : 'En cours'}</td></tr>
            <tr><th>Dépendances:</th><td>${objective.dependencies_count || 0}</td></tr>
          </table>
        </div>
      </div>
      <div class="row mt-3">
        <div class="col-md-12">
          <h5>Objectif</h5>
          <p>${escapeHtml(objective.goal_statement || 'Aucune description')}</p>
        </div>
      </div>
      ${objective.success_criteria ? `
      <div class="row mt-3">
        <div class="col-md-12">
          <h5>Critères de Succès</h5>
          <p>${escapeHtml(objective.success_criteria)}</p>
        </div>
      </div>
      ` : ''}
      ${objective.failure_reason ? `
      <div class="row mt-3">
        <div class="col-md-12">
          <h5>Raison de l'Échec</h5>
          <div class="alert alert-danger">${escapeHtml(objective.failure_reason)}</div>
        </div>
      </div>
      ` : ''}
    `;
    
    // Show the modal using Bootstrap
    const modal = new bootstrap.Modal(document.getElementById('objectiveDetailsModal'));
    modal.show();
  }

  window.refreshData = function() {
    console.log('Agent Objectives: Refresh button clicked (refreshData)');
    loadObjectives();
    alert('Objectifs actualisés!');
  };

  window.refreshObjectives = function() {
    console.log('Agent Objectives: Refresh button clicked (refreshObjectives)');
    loadObjectives();
    alert('Objectifs actualisés!');
  };

  console.log('Agent Objectives: Script loaded');
  console.log('Agent Objectives: refreshObjectives function available:', typeof window.refreshObjectives);
  console.log('Agent Objectives: refreshData function available:', typeof window.refreshData);
})();
