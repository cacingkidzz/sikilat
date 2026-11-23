/* =====================================================================
   SIKILAT - Grafik laporan dan analisis hasil ujian (Tahap 5)
   Data dibaca dari atribut data-* pada canvas agar aman terhadap CSP
   script-src 'self' (tanpa inline script).

   Mendukung:
   - bar / line / doughnut / pie / scatter
   - satu dataset (data-values) atau banyak dataset (data-datasets)
   - garis bantu (data-guides) untuk ambang KKM/kesukaran
   - bar bertumpuk (data-stacked) dan grafik horizontal (data-horizontal)
   ===================================================================== */
(function () {
    'use strict';

    if (typeof window.Chart === 'undefined') {
        return;
    }

    var primary = '#0d6efd';
    var ink = '#475467';
    var grid = 'rgba(16, 24, 40, 0.08)';
    var palette = [
        '#0d6efd', '#20c997', '#fd7e14', '#6f42c1', '#d63384',
        '#198754', '#ffc107', '#0dcaf0', '#dc3545', '#6c757d'
    ];

    window.Chart.defaults.font.family = '"Segoe UI", system-ui, -apple-system, "Helvetica Neue", Arial, sans-serif';
    window.Chart.defaults.font.size = 11;
    window.Chart.defaults.color = ink;

    function parseJson(value, fallback) {
        if (!value) {
            return fallback;
        }

        try {
            var parsed = JSON.parse(value);
            return parsed === null || typeof parsed === 'undefined' ? fallback : parsed;
        } catch (error) {
            return fallback;
        }
    }

    function parseArray(value) {
        var parsed = parseJson(value, []);
        return Array.isArray(parsed) ? parsed : [];
    }

    function formatNumber(value) {
        var numeric = Number(value);
        if (!isFinite(numeric)) {
            return '';
        }

        var rounded = Math.round(numeric * 100) / 100;
        return String(rounded).replace('.', ',');
    }

    function withAlpha(color, alpha) {
        if (typeof color !== 'string') {
            return color;
        }

        var match = color.match(/^#([0-9a-f]{6})$/i);
        if (!match) {
            return color;
        }

        var hex = match[1];
        var r = parseInt(hex.slice(0, 2), 16);
        var g = parseInt(hex.slice(2, 4), 16);
        var b = parseInt(hex.slice(4, 6), 16);

        return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + alpha + ')';
    }

    /* Garis bantu horizontal/vertikal, mis. ambang 30% dan 70%. */
    var guidesPlugin = {
        id: 'reportGuides',
        afterDatasetsDraw: function (chart, args, options) {
            var guides = options && Array.isArray(options.guides) ? options.guides : [];
            if (guides.length === 0) {
                return;
            }

            var ctx = chart.ctx;
            var area = chart.chartArea;
            var defaultAxis = chart.options.indexAxis === 'y' ? 'x' : 'y';

            ctx.save();

            guides.forEach(function (guide) {
                var axis = guide.axis === 'x' || guide.axis === 'y' ? guide.axis : defaultAxis;
                var scale = chart.scales[axis];
                if (!scale) {
                    return;
                }

                var position = scale.getPixelForValue(Number(guide.value));
                if (!isFinite(position)) {
                    return;
                }

                var color = guide.color || '#98a2b3';

                ctx.beginPath();
                ctx.setLineDash([5, 4]);
                ctx.lineWidth = 1;
                ctx.strokeStyle = color;

                if (axis === 'x') {
                    ctx.moveTo(position, area.top);
                    ctx.lineTo(position, area.bottom);
                } else {
                    ctx.moveTo(area.left, position);
                    ctx.lineTo(area.right, position);
                }

                ctx.stroke();
                ctx.setLineDash([]);

                if (guide.label) {
                    ctx.fillStyle = color;
                    ctx.font = '600 10px "Segoe UI", system-ui, sans-serif';

                    if (axis === 'x') {
                        ctx.textAlign = 'left';
                        ctx.textBaseline = 'top';
                        ctx.fillText(String(guide.label), position + 4, area.top + 2);
                    } else {
                        ctx.textAlign = 'right';
                        ctx.textBaseline = 'bottom';
                        ctx.fillText(String(guide.label), area.right - 2, position - 3);
                    }
                }
            });

            ctx.restore();
        }
    };

    window.Chart.register(guidesPlugin);

    function legendOptions(type, showLegend) {
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
            display: showLegend,
            position: 'bottom',
            labels: {
                usePointStyle: true,
                pointStyle: 'rectRounded',
                boxWidth: 9,
                boxHeight: 9,
                padding: 12
            }
        };
    }

    function tooltipText(context, type, canvas, multiple) {
        var raw = context.raw;
        var suffix = canvas.getAttribute('data-suffix') || '';
        var xSuffix = canvas.getAttribute('data-x-suffix') || '';
        var ySuffix = canvas.getAttribute('data-y-suffix') || '';

        if (type === 'scatter' && raw && typeof raw === 'object') {
            var name = raw.label ? raw.label + ': ' : '';
            var xTitle = canvas.getAttribute('data-x-title') || 'X';
            var yTitle = canvas.getAttribute('data-y-title') || 'Y';

            return name + xTitle + ' ' + formatNumber(raw.x) + xSuffix
                + ' \u00b7 ' + yTitle + ' ' + formatNumber(raw.y) + ySuffix;
        }

        var value = context.parsed;
        var numeric = typeof value === 'object' && value !== null ? value.y : value;

        if (typeof numeric !== 'number') {
            return '';
        }

        var prefix = '';
        if (multiple) {
            prefix = (context.dataset.label || '') + ': ';
        } else {
            var label = canvas.getAttribute('data-label') || '';
            prefix = label !== '' ? label + ': ' : '';
        }

        return prefix + formatNumber(numeric) + suffix;
    }

    function buildSingleDataset(canvas, type) {
        var values = parseArray(canvas.getAttribute('data-values'));
        var colors = parseArray(canvas.getAttribute('data-colors'));
        var label = canvas.getAttribute('data-label') || '';
        var isLine = type === 'line';
        var baseColor = colors.length > 0 ? colors[0] : primary;

        var dataset = {
            label: label,
            data: values,
            backgroundColor: isLine ? withAlpha(primary, 0.12) : (colors.length > 0 ? colors : withAlpha(primary, 0.78)),
            borderColor: isLine ? primary : baseColor,
            borderWidth: isLine ? 2 : 1,
            borderRadius: isLine ? 0 : 6,
            maxBarThickness: 42,
            tension: 0.3,
            fill: false,
            pointRadius: Number(canvas.getAttribute('data-point-radius') || 2.6),
            pointHoverRadius: 4,
            pointBackgroundColor: isLine
                ? (colors.length > 0 ? colors : primary)
                : baseColor,
            pointBorderColor: '#ffffff',
            pointBorderWidth: 1
        };

        if (canvas.getAttribute('data-fill') === '1') {
            dataset.fill = true;
        }

        return dataset;
    }

    function buildDatasets(canvas, type) {
        var raw = parseJson(canvas.getAttribute('data-datasets'), null);
        if (!Array.isArray(raw) || raw.length === 0) {
            return [buildSingleDataset(canvas, type)];
        }

        var isLine = type === 'line' || type === 'scatter';
        var pointRadius = Number(canvas.getAttribute('data-point-radius') || (type === 'scatter' ? 5 : 2.6));

        return raw.map(function (entry, index) {
            var color = entry.color || palette[index % palette.length];
            var values = Array.isArray(entry.points)
                ? entry.points
                : (Array.isArray(entry.values) ? entry.values : []);
            var colors = Array.isArray(entry.colors) ? entry.colors : [];

            var dataset = {
                label: entry.label || '',
                data: values,
                backgroundColor: isLine
                    ? withAlpha(color, 0.1)
                    : (colors.length > 0 ? colors : withAlpha(color, 0.78)),
                borderColor: color,
                borderWidth: Number(entry.borderWidth || (isLine ? 2 : 1)),
                borderRadius: isLine ? 0 : 5,
                maxBarThickness: 42,
                tension: typeof entry.tension === 'number' ? entry.tension : 0.3,
                fill: entry.fill === true,
                pointRadius: typeof entry.pointRadius === 'number' ? entry.pointRadius : pointRadius,
                pointHoverRadius: pointRadius + 2,
                pointBackgroundColor: Array.isArray(entry.pointColors)
                    ? entry.pointColors
                    : (colors.length > 0 ? colors : color),
                pointBorderColor: '#ffffff',
                pointBorderWidth: 1
            };

            if (entry.stack) {
                dataset.stack = String(entry.stack);
            }

            if (entry.dashed === true) {
                dataset.borderDash = [6, 4];
            }

            return dataset;
        });
    }

    function buildScales(canvas, type) {
        var horizontal = canvas.getAttribute('data-horizontal') === '1';
        var stacked = canvas.getAttribute('data-stacked') === '1';
        var yMin = canvas.getAttribute('data-y-min');
        var yMax = canvas.getAttribute('data-y-max');
        var xMin = canvas.getAttribute('data-x-min');
        var xMax = canvas.getAttribute('data-x-max');
        var yPrecision = canvas.getAttribute('data-y-precision');
        var xPrecision = canvas.getAttribute('data-x-precision');
        var xTitle = canvas.getAttribute('data-x-title') || '';
        var yTitle = canvas.getAttribute('data-y-title') || '';

        if (type === 'scatter') {
            return {
                x: {
                    type: 'linear',
                    min: xMin !== null ? Number(xMin) : 0,
                    max: xMax !== null ? Number(xMax) : undefined,
                    title: {
                        display: xTitle !== '',
                        text: xTitle
                    },
                    grid: {
                        color: grid
                    },
                    ticks: {
                        precision: xPrecision !== null ? Number(xPrecision) : 0
                    }
                },
                y: {
                    type: 'linear',
                    min: yMin !== null ? Number(yMin) : undefined,
                    max: yMax !== null ? Number(yMax) : undefined,
                    title: {
                        display: yTitle !== '',
                        text: yTitle
                    },
                    grid: {
                        color: grid
                    },
                    ticks: {
                        precision: yPrecision !== null ? Number(yPrecision) : 2
                    }
                }
            };
        }

        return {
            x: {
                stacked: stacked,
                beginAtZero: horizontal && xMin === null,
                min: xMin !== null ? Number(xMin) : undefined,
                max: xMax !== null ? Number(xMax) : undefined,
                title: {
                    display: xTitle !== '',
                    text: xTitle
                },
                grid: {
                    display: horizontal,
                    color: grid
                },
                ticks: {
                    maxRotation: 0,
                    autoSkip: true,
                    autoSkipPadding: 12,
                    precision: xPrecision !== null ? Number(xPrecision) : (horizontal ? 0 : undefined)
                }
            },
            y: {
                stacked: stacked,
                beginAtZero: yMin === null,
                min: yMin !== null ? Number(yMin) : undefined,
                max: yMax !== null ? Number(yMax) : undefined,
                title: {
                    display: yTitle !== '',
                    text: yTitle
                },
                grid: {
                    display: !horizontal,
                    color: grid
                },
                ticks: {
                    precision: yPrecision !== null ? Number(yPrecision) : 0
                }
            }
        };
    }

    function buildConfig(canvas) {
        var type = canvas.getAttribute('data-report-chart') || 'bar';
        var labels = parseArray(canvas.getAttribute('data-labels'));

        if (type !== 'scatter' && labels.length === 0) {
            return null;
        }

        if (type === 'doughnut' || type === 'pie') {
            var values = parseArray(canvas.getAttribute('data-values'));
            var colors = parseArray(canvas.getAttribute('data-colors'));

            return {
                type: type,
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: colors.length > 0 ? colors : palette,
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
                        legend: legendOptions(type, true),
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

        var datasets = buildDatasets(canvas, type);
        var horizontal = canvas.getAttribute('data-horizontal') === '1';
        var multiple = datasets.length > 1;
        var showLegend = canvas.getAttribute('data-legend') === '1' || multiple;
        var guides = parseJson(canvas.getAttribute('data-guides'), []);

        return {
            type: type,
            data: {
                labels: labels,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: horizontal ? 'y' : 'x',
                interaction: {
                    mode: type === 'scatter' ? 'nearest' : 'index',
                    intersect: type === 'scatter'
                },
                plugins: {
                    legend: legendOptions(type, showLegend),
                    reportGuides: {
                        guides: Array.isArray(guides) ? guides : []
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return tooltipText(context, type, canvas, multiple);
                            }
                        }
                    }
                },
                scales: buildScales(canvas, type)
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
