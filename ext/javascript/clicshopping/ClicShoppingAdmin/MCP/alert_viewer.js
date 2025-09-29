const alertsPerPage = 10;
let currentPage = 1;

function loadAlerts(page = 1, filter = 'all') {
  fetch(`${gptAlerts}?page=${page}&filter=${filter}&per_page=${alertsPerPage}`)
    .then(response => response.json())
    .then(data => {
      const alertsList = document.getElementById('mcpAlertsList');
      alertsList.innerHTML = '';

      if (data.alerts.length === 0) {
        alertsList.innerHTML = `<tr><td colspan="3" class="text-center">${text_no_alert}</td></tr>`;
        return;
      }

      data.alerts.forEach(alert => {
        const row = document.createElement('tr');

        let rowClass = '';
        let badgeClass = '';

        switch (alert.type) {
          case 'critical':
          case 'error':
            rowClass = 'table-danger';
            badgeClass = 'text-bg-danger';
            break;
          case 'warning':
            rowClass = 'table-warning';
            badgeClass = 'text-bg-warning';
            break;
          case 'info':
            rowClass = 'table-info';
            badgeClass = 'text-bg-info';
            break;
          default:
            rowClass = '';
            badgeClass = 'text-bg-secondary';
        }

        row.className = rowClass;
        row.innerHTML = `
          <td>${alert.alert_timestamp}</td>
          <td><span class="badge ${badgeClass}">${alert.type}</span></td>
          <td>${alert.message}</td>
        `;
        alertsList.appendChild(row);
      });

      updatePagination(data.total_pages, page);
    })
    .catch(error => {
      console.error('Failed to load alerts:', error);
      document.getElementById('mcpAlertsList').innerHTML =
        '<tr><td colspan="3" class="text-center text-danger">Failed to load alerts</td></tr>';
    });
}

function updatePagination(totalPages, currentPage) {
  const pagination = document.getElementById('mcpAlertsPagination');
  pagination.innerHTML = '';
  if (totalPages <= 1) return;

  const nav = document.createElement('nav');
  const ul = document.createElement('ul');
  ul.className = 'pagination pagination-sm';

  const prevLi = document.createElement('li');
  prevLi.className = `page-item ${currentPage === 1 ? 'disabled' : ''}`;
  prevLi.innerHTML = `<a class="page-link" href="#" data-page="${currentPage - 1}">Previous</a>`;
  ul.appendChild(prevLi);

  for (let i = 1; i <= totalPages; i++) {
    const li = document.createElement('li');
    li.className = `page-item ${i === currentPage ? 'active' : ''}`;
    li.innerHTML = `<a class="page-link" href="#" data-page="${i}">${i}</a>`;
    ul.appendChild(li);
  }

  const nextLi = document.createElement('li');
  nextLi.className = `page-item ${currentPage === totalPages ? 'disabled' : ''}`;
  nextLi.innerHTML = `<a class="page-link" href="#" data-page="${currentPage + 1}">Next</a>`;
  ul.appendChild(nextLi);

  nav.appendChild(ul);
  pagination.appendChild(nav);

  ul.addEventListener('click', (e) => {
    e.preventDefault();
    const page = e.target.dataset.page;
    if (page) loadAlerts(parseInt(page), document.getElementById('alertFilter').value);
  });
}

loadAlerts();

document.getElementById('alertFilter').addEventListener('change', function () {
  loadAlerts(1, this.value);
});

document.getElementById('clearAlerts').addEventListener('click', function () {
  if (!confirm('Are you sure you want to clear all alerts?')) return;
  fetch(clearAlerts, {
    method: 'POST',
    body: JSON.stringify({ 'clear': true }),
    headers: {
      'Content-Type': 'application/json'
    }
  })
    .then(response => {
      if (!response.ok) {
        return response.json().then(errorData => {
          throw new Error(errorData.message || 'Server returned an error.');
        });
      }
      return response.json();
    })
    .then(data => {
      if (data.success) {
        loadAlerts();
        alert(data.message);
      } else {
        alert(data.message);
      }
    })
    .catch(error => {
      console.error('Failed to clear alerts:', error);
      alert('Failed to clear alerts. See console for details.');
    });
});

setInterval(() => loadAlerts(currentPage, document.getElementById('alertFilter').value), 60000);
