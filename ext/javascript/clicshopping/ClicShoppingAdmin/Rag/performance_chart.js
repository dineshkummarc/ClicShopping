// ====================================================================
// PERFORMANCE CHART
// ====================================================================

document.addEventListener('DOMContentLoaded', () => {
  const perfCtx = document.getElementById('performanceChart')?.getContext('2d');
  if (!perfCtx) return;

  const systemReport = window.APP_DATA?.systemReport || {};

  const analyticsSuccess = parseFloat((systemReport.analytics?.success_rate || '0').replace('%', '')) || 0;
  const semanticSuccess = parseFloat((systemReport.semantic?.success_rate || '0').replace('%', '')) || 0;
  const hybridSuccess = parseFloat((systemReport.hybrid?.success_rate || '0').replace('%', '')) || 0;
  const orchestratorSuccess = parseFloat((systemReport.orchestrator?.success_rate || '0').replace('%', '')) || 0;
  const cacheSuccess = systemReport.cache?.average_quality_score
    ? Math.round(systemReport.cache.average_quality_score * 1000) / 10
    : 0;

  new Chart(perfCtx, {
    type: 'line',
    data: {
      labels: ['Analytics', 'Semantic', 'Hybrid', 'Orchestrator', 'Cache RAG'],
      datasets: [{
        label: 'Taux de Succès / Efficacité (%)',
        data: [analyticsSuccess, semanticSuccess, hybridSuccess, orchestratorSuccess, cacheSuccess],
        borderColor: 'rgb(102, 126, 234)',
        backgroundColor: 'rgba(102, 126, 234, 0.1)',
        tension: 0.4,
        fill: true,
        pointRadius: 5,
        pointHoverRadius: 7,
        pointBackgroundColor: 'rgb(102, 126, 234)',
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      scales: {
        y: {
          beginAtZero: true,
          max: 100,
          ticks: { callback: value => value + '%' }
        }
      },
      plugins: {
        legend: {
          display: true,
          labels: { usePointStyle: true, padding: 15 }
        },
        tooltip: {
          backgroundColor: 'rgba(0,0,0,0.8)',
          titleFont: { size: 13 },
          bodyFont: { size: 13 },
          padding: 12,
          callbacks: {
            label: ctx => `${ctx.dataset.label}: ${ctx.parsed.y.toFixed(1)}%`
          }
        }
      }
    }
  });
});
