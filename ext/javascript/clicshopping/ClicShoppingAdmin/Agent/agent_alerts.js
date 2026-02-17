/**
 * Agent Alerts Dashboard JavaScript
 * Handles loading and displaying system alerts
 */

(function() {
  'use strict';

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAgentAlerts);
  } else {
    initAgentAlerts();
  }

  function initAgentAlerts() {
    console.log('Agent Alerts: Initializing...');
    
    if (!window.AgentAlertsConfig) {
      console.log('Agent Alerts: Config not found, skipping initialization');
      return;
    }

    loadAlerts();
    setInterval(loadAlerts, 30000);
  }

  function loadAlerts() {
    console.log('Agent Alerts: Loading data...');
    
    const endpoint = window.AgentAlertsConfig.baseUrl + window.AgentAlertsConfig.alertsEndpoint;
    
    fetch(endpoint)
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
      })
      .then(data => {
        console.log('Agent Alerts: Data received', data);
        
        if (data.success) {
          updateAlertsDisplay(data.data);
        } else {
          console.error('Agent Alerts: API returned error', data.error);
          showError('Failed to load alerts: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(error => {
        console.error('Agent Alerts: Fetch error', error);
        showError('Failed to load alerts: ' + error.message);
      });
  }

  function updateAlertsDisplay(data) {
    console.log('Agent Alerts: Updating display');
    
    // Update summary cards
    if (data.summary) {
      updateElement('alert-overdue', data.summary.overdue_objectives || 0);
      updateElement('alert-systematic', data.summary.systematic_issues || 0);
      updateElement('alert-consensus', data.summary.failed_consensus || 0);
      updateElement('alert-failed', data.summary.failed_objectives || 0);
    }
    
    // Update tables
    updateOverdueTable(data.overdue_objectives || []);
    updateSystematicTable(data.systematic_issues || []);
    updateConsensusTable(data.failed_consensus || []);
    updateFailedTable(data.failed_objectives || []);
  }

  function updateOverdueTable(objectives) {
    const tbody = document.getElementById('overdue-tbody');
    if (!tbody) return;

    if (objectives.length === 0) {
      tbody.innerHTML = '<tr><td colspan="8" class="text-center">No overdue objectives</td></tr>';
      return;
    }

    tbody.innerHTML = objectives.map(obj => `
      <tr>
        <td>${escapeHtml(obj.objective_id || '')}</td>
        <td>${escapeHtml(obj.agent_id || '')}</td>
        <td>${escapeHtml(obj.goal_statement || '')}</td>
        <td><span class="badge bg-${getPriorityClass(obj.priority)}">${escapeHtml(obj.priority || '')}</span></td>
        <td>${formatDate(obj.created_at)}</td>
        <td>${obj.estimated_completion_time ? formatDuration(obj.estimated_completion_time) : 'N/A'}</td>
        <td class="text-danger">${calculateOverdue(obj.created_at, obj.estimated_completion_time)}</td>
        <td>
          <button class="btn btn-sm btn-warning" onclick="escalateObjective('${obj.objective_id}')">
            <i class="bi bi-exclamation-triangle"></i> Escalate
          </button>
        </td>
      </tr>
    `).join('');
  }

  function updateSystematicTable(issues) {
    const tbody = document.getElementById('systematic-tbody');
    if (!tbody) return;

    if (issues.length === 0) {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center">No systematic issues detected</td></tr>';
      return;
    }

    tbody.innerHTML = issues.map(issue => `
      <tr>
        <td>${escapeHtml(issue.agent_id || '')}</td>
        <td>${issue.evaluation_count || 0}</td>
        <td>${(issue.avg_score || 0).toFixed(3)}</td>
        <td>${(issue.min_score || 0).toFixed(3)}</td>
        <td>${(issue.max_score || 0).toFixed(3)}</td>
        <td><span class="badge bg-${issue.severity === 'critical' ? 'danger' : 'warning'}">${escapeHtml(issue.severity || '')}</span></td>
        <td>
          <button class="btn btn-sm btn-info" onclick="viewAgentDetails('${issue.agent_id}')">
            <i class="bi bi-eye"></i> Details
          </button>
        </td>
      </tr>
    `).join('');
  }

  function updateConsensusTable(sessions) {
    const tbody = document.getElementById('consensus-tbody');
    if (!tbody) return;

    if (sessions.length === 0) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center">No failed consensus sessions</td></tr>';
      return;
    }

    tbody.innerHTML = sessions.map(session => `
      <tr>
        <td>${escapeHtml(session.session_id || '')}</td>
        <td>${escapeHtml(session.output_id || '')}</td>
        <td>${Array.isArray(session.participating_agents) ? session.participating_agents.length : 0}</td>
        <td>${formatScores(session.initial_scores)}</td>
        <td>${formatDate(session.created_at)}</td>
        <td>
          <button class="btn btn-sm btn-info" onclick="viewConsensusDetails('${session.session_id}')">
            <i class="bi bi-eye"></i> Details
          </button>
        </td>
      </tr>
    `).join('');
  }

  function updateFailedTable(objectives) {
    const tbody = document.getElementById('failed-tbody');
    if (!tbody) return;

    if (objectives.length === 0) {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center">No failed objectives</td></tr>';
      return;
    }

    tbody.innerHTML = objectives.map(obj => `
      <tr>
        <td>${escapeHtml(obj.objective_id || '')}</td>
        <td>${escapeHtml(obj.agent_id || '')}</td>
        <td>${escapeHtml(obj.goal_statement || '')}</td>
        <td><span class="badge bg-${getPriorityClass(obj.priority)}">${escapeHtml(obj.priority || '')}</span></td>
        <td>${escapeHtml(obj.failure_reason || 'N/A')}</td>
        <td>${formatDate(obj.completed_at)}</td>
        <td>
          <button class="btn btn-sm btn-info" onclick="viewObjectiveDetails('${obj.objective_id}')">
            <i class="bi bi-eye"></i> Details
          </button>
        </td>
      </tr>
    `).join('');
  }

  function calculateOverdue(createdAt, estimatedTime) {
    if (!createdAt || !estimatedTime) return 'N/A';
    
    const created = new Date(createdAt);
    const deadline = new Date(created.getTime() + estimatedTime * 1000);
    const now = new Date();
    const overdue = Math.floor((now - deadline) / 1000);
    
    if (overdue <= 0) return 'Not overdue';
    
    return formatDuration(overdue);
  }

  function formatScores(scores) {
    if (!scores || typeof scores !== 'object') return 'N/A';
    return Object.entries(scores).map(([agent, score]) => 
      `${agent.split('_').pop()}: ${score.toFixed(2)}`
    ).join(', ');
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
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    
    if (days > 0) return `${days}d ${hours}h`;
    if (hours > 0) return `${hours}h ${minutes}m`;
    return `${minutes}m`;
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function updateElement(id, value) {
    const element = document.getElementById(id);
    if (element) {
      element.textContent = value;
    }
  }

  function showError(message) {
    console.error('Agent Alerts: Error -', message);
  }

  // Global functions
  window.refreshAlerts = loadAlerts;
  window.escalateObjective = function(id) { console.log('Escalate:', id); };
  window.viewAgentDetails = function(id) { console.log('View agent:', id); };
  window.viewConsensusDetails = function(id) { console.log('View consensus:', id); };
  window.viewObjectiveDetails = function(id) { console.log('View objective:', id); };

  console.log('Agent Alerts: Script loaded');
})();
