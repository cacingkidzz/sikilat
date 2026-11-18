/* =====================================================================
   SIKILAT - Grafik dashboard
   Membaca data dari atribut canvas (bukan inline script) agar aman
   terhadap Content Security Policy.
   ===================================================================== */
(function () {
    'use strict';

    var canvas = document.getElementById('chart-hasil');
    if (!canvas || typeof window.Chart === 'undefined') {
        return;
    }

    function parseJson(value, fallback) {
        try {
            var parsed = JSON.parse(value || '');
            return Array.isArray(parsed) ? parsed : fallback;
        } catch (error) {
            return fallback;
        }
    }

    var labels = parseJson(canvas.getAttribute('data-labels'), []);
    var rataRata = parseJson(canvas.getAttribute('data-rata'), []);
    var peserta = parseJson(canvas.getAttribute('data-peserta'), []);

    if (labels.length === 0) {
        return;
    }

    new window.Chart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Rata-rata nilai',
                data: rataRata,
                backgroundColor: 'rgba(13, 110, 253, 0.78)',
                hoverBackgroundColor: '#0a58ca',
                borderRadius: 6,
                maxBarThickness: 46
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: '#101828',
                    padding: 12,
                    titleFont: { size: 13, weight: '600' },
                    bodyFont: { size: 12 },
                    callbacks: {
                        label: function (context) {
                            var value = Number(context.parsed.y || 0);
                            return ' Rata-rata: ' + value.toFixed(2).replace('.', ',');
                        },
                        footer: function (items) {
                            var index = items[0].dataIndex;
                            return 'Peserta: ' + (peserta[index] || 0) + ' siswa';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        stepSize: 20,
                        color: '#667085'
                    },
                    grid: {
                        color: '#eef0f3'
                    }
                },
                x: {
                    ticks: {
                        color: '#667085',
                        maxRotation: 30,
                        minRotation: 0,
                        autoSkip: false,
                        callback: function (value) {
                            var label = String(this.getLabelForValue(value));
                            return label.length > 18 ? label.slice(0, 17) + '…' : label;
                        }
                    },
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
})();
