jQuery(document).ready(function($) {
    if (typeof Chart === 'undefined' || !document.getElementById('cg-issuance-chart')) return; // No canvases on the empty state.

    var rawData = $('#cg-analytics-data').text();
    if (!rawData) return;

    try {
        var data = JSON.parse(rawData);
    } catch(e) {
        console.error('Failed to parse analytics data', e);
        return;
    }

    // Issuance Over Time Chart
    if (data.issuance_over_time && data.issuance_over_time.length > 0) {
        var issuanceCtx = document.getElementById('cg-issuance-chart').getContext('2d');
        var issuanceLabels = data.issuance_over_time.map(function(item) { return item.date; }).reverse();
        var issuanceCounts = data.issuance_over_time.map(function(item) { return parseInt(item.count); }).reverse();

        new Chart(issuanceCtx, {
            type: 'line',
            data: {
                labels: issuanceLabels,
                datasets: [{
                    label: 'Certificates Issued',
                    data: issuanceCounts,
                    borderColor: '#2271b1',
                    backgroundColor: 'rgba(34, 113, 177, 0.1)',
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 } }
                }
            }
        });
    }

    // By Type Chart
    if (data.by_type && data.by_type.length > 0) {
        var typeCtx = document.getElementById('cg-type-chart').getContext('2d');
        var typeLabels = data.by_type.map(function(item) { return item.certificate_type || 'Unknown'; });
        var typeCounts = data.by_type.map(function(item) { return parseInt(item.count); });
        var typeColors = ['#2271b1', '#135e96', '#0a4b78', '#073d61', '#05304a', '#d63638', '#dba617', '#00a32a', '#7e5bef', '#ff6384'];

        new Chart(typeCtx, {
            type: 'bar',
            data: {
                labels: typeLabels,
                datasets: [{
                    label: 'Certificates',
                    data: typeCounts,
                    backgroundColor: typeColors.slice(0, typeLabels.length)
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 } }
                }
            }
        });
    }

    // By Method Chart
    if (data.by_method && data.by_method.length > 0) {
        var methodCtx = document.getElementById('cg-method-chart').getContext('2d');
        var methodLabels = data.by_method.map(function(item) { return item.generated_via.charAt(0).toUpperCase() + item.generated_via.slice(1); });
        var methodCounts = data.by_method.map(function(item) { return parseInt(item.count); });

        new Chart(methodCtx, {
            type: 'doughnut',
            data: {
                labels: methodLabels,
                datasets: [{
                    data: methodCounts,
                    backgroundColor: ['#2271b1', '#00a32a', '#dba617', '#d63638']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }

    // Expiration Status Chart
    if (data.expiration_status && data.expiration_status.length > 0) {
        var expCtx = document.getElementById('cg-expiration-chart').getContext('2d');
        var expLabels = data.expiration_status.map(function(item) { return item.status; });
        var expCounts = data.expiration_status.map(function(item) { return parseInt(item.count); });

        new Chart(expCtx, {
            type: 'pie',
            data: {
                labels: expLabels,
                datasets: [{
                    data: expCounts,
                    backgroundColor: ['#00a32a', '#dba617', '#d63638']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }
});
