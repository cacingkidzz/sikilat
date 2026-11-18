/* =====================================================================
   SIKILAT - Grafik laporan dan analisis hasil ujian (Tahap 5)
   Data dibaca dari atribut data-* pada canvas agar aman terhadap CSP
   script-src 'self' (tanpa inline script).
   ===================================================================== */
(function () {
    'use strict';

    if (typeof window.Chart === 'undefined') {
        return;
    }

    var primary = '#0d6efd';
    var ink = '#475467';
    var grid = 'rgba(16, 24, 40, 0.08)';

    window.Chart.defaults.font.family = '"Segoe UI", system-ui, -apple-system, "Helvetica Neue", Arial, sans-serif';
    window.Chart.defaults.font.size = 11;
    window.Chart.defaults.color = ink;

    function parseJson(value, fallback) {
        try {
            var parsed = JSON.parse(value || '');
            return Array.isArray(parsed) ? parsed : fallback;
        } catch (error) {
            return fallback;
        }
    }

    function tooltipSuffix(suffix) {
        return function (context) {
            var value = context.parsed;
            var numeric = typeof value === 'object' && value !== null ? value.y : value;
            if (typeof numeric !== 'number') {
                return '';
            }
            return String(numeric).replace('.', ',') + (suffix || '');
        };
    }

    function legendOptions(type) {
        if (type === 'doughnut' || type === 'pie') {
            return {
                display: true,
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    pointStyle: 'circle',
                    boxWidth: 8,
                    padding: 12
                }
            };
        }

        return {
            display: false
        };
    }

    function buildConfig(canvas) {
        var type = canvas.getAttribute('data-report-chart') || 'bar';
        var labels = parseJson(canvas.getAttribute('data-labels'), []);
        var values = parseJson(canvas.getAttribute('data-values'), []);
        var colors = parseJson(canvas.getAttribute('data-colors'), []);
        var label = canvas.getAttribute('data-label') || '';
        var suffix = canvas.getAttribute('data-suffix') || '';
        var horizontal = canvas.getAttribute('data-horizontal') === '1';

        if (labels.length === 0) {
            return null;
        }

        if (type === 'doughnut' || type === 'pie') {
            return {
                type: type,
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: colors.length > 0 ? colors : [primary, '#20c997', '#ffc107', '#fd7e14', '#dc3545'],
                        borderColor: '#ffffff',
                        borderWidth: 2,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: type === 'doughnut' ? '62%' : undefined,
                    plugins: {
                        legend: legendOptions(type),
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    var total = context.dataset.data.reduce(function (sum, item) {
                                        return sum + Number(item || 0);
                                    }, 0);
                                    var value = Number(context.parsed || 0);
                                    var percent = total > 0 ? Math.round((value / total) * 1000) / 10 : 0;
                                    return context.label + ': ' + value + ' (' + String(percent).replace('.', ',') + '%)';
                                }
                            }
                        }
                    }
                }
            };
        }

        var isLine = type === 'line';
        var dataset = {
            label: label,
            data: values,
            backgroundColor: isLine ? 'rgba(13, 110, 253, 0.12)' : (colors.length > 0 ? colors : 'rgba(13, 110, 253, 0.78)'),
            borderColor: isLine ? primary : (colors.length > 0 ? colors : 'rgba(13, 110, 253, 1)'),
            borderWidth: isLine ? 2 : 1,
            borderRadius: isLine ? 0 : 6,
            maxBarThickness: 42,
            tension: 0.3,
            fill: false,
            pointRadius: 2.6,
            pointHoverRadius: 4,
            pointBackgroundColor: primary
        };

        if (isLine) {
            dataset.backgroundColor = 'rgba(13, 110, 253, 0.12)';
        }

        return {
            type: isLine ? 'line' : 'bar',
            data: {
                labels: labels,
                datasets: [dataset]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: horizontal ? 'y' : 'x',
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: legendOptions(type),
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var text = label !== '' ? label + ': ' : '';
                                return text + tooltipSuffix(suffix)(context);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: horizontal,
                            color: grid
                        },
                        ticks: {
                            maxRotation: 0,
                            autoSkip: true,
                            autoSkipPadding: 12
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            display: !horizontal,
                            color: grid
                        },
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        };
    }

    document.querySelectorAll('canvas[data-report-chart]').forEach(function (canvas) {
        var config = buildConfig(canvas);
        if (config === null) {
            return;
        }

        try {
            new window.Chart(canvas, config);
        } catch (error) {
            console.error('Grafik laporan gagal dibuat:', error);
        }
    });

    document.querySelectorAll('form[data-auto-submit]').forEach(function (form) {
        form.querySelectorAll('select').forEach(function (select) {
            select.addEventListener('change', function () {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        });
    });
})();
