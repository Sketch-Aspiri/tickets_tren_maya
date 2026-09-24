// Graficas del panel de seguimiento (Chart.js empaquetado por Vite; sin CDN ni scripts en linea, compatible
// con la CSP `script-src 'self'`). Se carga bajo demanda desde app.js solo cuando la pagina tiene un
// <canvas data-chart>. El servidor entrega la configuracion como JSON plano en `data-chart` (tipo, paleta,
// etiquetas y numeros ya traducidos); aqui nunca se evalua texto como codigo. Cada grafica tiene ademas una
// tabla equivalente en la pagina (alternativa accesible), por eso un fallo aqui no deja al usuario sin datos.
import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    DoughnutController,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';

Chart.register(
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    DoughnutController,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
);

const SERIES = ['#1F7460', '#D97706'];

const PALETTES = {
    status: {
        pending: '#9CA3AF',
        in_progress: '#1F7460',
        in_review: '#D97706',
        completed: '#06534D',
        cancelled: '#B91C1C',
    },
    priority: {
        low: '#9CA3AF',
        medium: '#70C2AD',
        high: '#D97706',
        urgent: '#B91C1C',
    },
};

const TYPES = new Set(['line', 'bar', 'doughnut']);

function colorsFor(config) {
    const palette = PALETTES[config.palette];

    if (!palette || !Array.isArray(config.keys)) {
        return SERIES;
    }

    return config.keys.map((key) => palette[key] ?? '#6B7280');
}

function buildDatasets(config) {
    const colors = colorsFor(config);

    return config.datasets.map((dataset, index) => {
        if (config.type === 'line') {
            const color = SERIES[index % SERIES.length];

            return {
                label: String(dataset.label),
                data: dataset.data,
                borderColor: color,
                backgroundColor: color,
                borderDash: index === 0 ? [] : [6, 4],
                pointStyle: index === 0 ? 'circle' : 'rect',
                pointRadius: 3,
                tension: 0.25,
            };
        }

        return {
            label: String(dataset.label),
            data: dataset.data,
            backgroundColor: colors,
            borderColor: config.type === 'doughnut' ? '#FFFFFF' : colors,
            borderWidth: config.type === 'doughnut' ? 2 : 0,
        };
    });
}

function buildOptions(config, reduceMotion) {
    const options = {
        responsive: true,
        maintainAspectRatio: false,
        animation: reduceMotion ? false : undefined,
        plugins: {
            legend: {
                display: config.type !== 'bar',
                position: 'bottom',
            },
        },
    };

    if (config.type !== 'doughnut') {
        options.scales = {
            y: { beginAtZero: true, ticks: { precision: 0 } },
            x: { ticks: { maxRotation: 0, autoSkip: true } },
        };
        options.interaction = { mode: 'index', intersect: false };
    }

    return options;
}

function parse(canvas) {
    try {
        const config = JSON.parse(canvas.dataset.chart ?? '');

        if (!TYPES.has(config.type) || !Array.isArray(config.labels) || !Array.isArray(config.datasets)) {
            return null;
        }

        return config;
    } catch {
        return null;
    }
}

export function initCharts(root = document) {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    root.querySelectorAll('canvas[data-chart]').forEach((canvas) => {
        const config = parse(canvas);

        if (config === null) {
            return;
        }

        new Chart(canvas, {
            type: config.type,
            data: { labels: config.labels.map(String), datasets: buildDatasets(config) },
            options: buildOptions(config, reduceMotion),
        });
    });
}
