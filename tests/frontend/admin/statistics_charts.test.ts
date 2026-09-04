/**
 * Tests for statistics_charts.ts - Chart.js visualizations for statistics page
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// Track Chart constructor calls
const chartConstructorCalls: Array<[HTMLCanvasElement | null, unknown]> = [];

// Mock chart.js before importing the module
vi.mock('chart.js', () => {
  const MockChart = vi.fn().mockImplementation(function(
    this: Record<string, unknown>,
    canvas: HTMLCanvasElement,
    config: unknown
  ) {
    chartConstructorCalls.push([canvas, config]);
    this.destroy = vi.fn();
    this.update = vi.fn();
    this.data = { datasets: [] };
    this.options = {};
    return this;
  });

  // Add static method
  (MockChart as unknown as { register: () => void }).register = vi.fn();

  return {
    Chart: MockChart,
    registerables: [],
    BarController: {},
    BarElement: {},
    LineController: {},
    LineElement: {},
    PointElement: {},
    CategoryScale: {},
    LinearScale: {},
    Tooltip: {},
    Legend: {},
    Filler: {},
  };
});

import {
  initIntensityChart,
  initFrequencyChart,
  initStatisticsCharts,
  statisticsApp
} from '../../../src/frontend/js/modules/admin/pages/statistics_charts';

describe('statistics_charts.ts', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
    chartConstructorCalls.length = 0;
  });

  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  // ===========================================================================
  // initIntensityChart Tests
  // ===========================================================================

  describe('initIntensityChart', () => {
    it('returns null when canvas element does not exist', async () => {
      const result = await initIntensityChart('nonexistent-canvas', []);

      expect(result).toBeNull();
    });

    it('returns null when canvas ID is empty', async () => {
      const result = await initIntensityChart('', []);

      expect(result).toBeNull();
    });

    it('creates chart when canvas exists with empty data', async () => {
      document.body.innerHTML = '<canvas id="intensityChart"></canvas>';

      const result = await initIntensityChart('intensityChart', []);

      expect(chartConstructorCalls.length).toBe(1);
      expect(result).not.toBeNull();
    });

    it('creates chart with single language data', async () => {
      document.body.innerHTML = '<canvas id="intensityChart"></canvas>';

      const data = [
        { name: 'English', s1: 10, s2: 20, s3: 30, s4: 15, s5: 25, s99: 100 }
      ];

      const result = await initIntensityChart('intensityChart', data);

      expect(chartConstructorCalls.length).toBe(1);
      expect(result).not.toBeNull();

      // Verify Chart was called with correct structure
      const config = chartConstructorCalls[0][1] as Record<string, unknown>;
      expect(config.type).toBe('bar');
    });

    it('creates chart with multiple language data', async () => {
      document.body.innerHTML = '<canvas id="intensityChart"></canvas>';

      const data = [
        { name: 'English', s1: 10, s2: 20, s3: 30, s4: 15, s5: 25, s99: 100 },
        { name: 'Spanish', s1: 5, s2: 10, s3: 15, s4: 20, s5: 50, s99: 200 },
        { name: 'French', s1: 8, s2: 12, s3: 18, s4: 22, s5: 40, s99: 150 }
      ];

      const result = await initIntensityChart('intensityChart', data);

      expect(chartConstructorCalls.length).toBe(1);
      expect(result).not.toBeNull();

      // Verify labels are set correctly
      const config = chartConstructorCalls[0][1] as { data?: { labels?: string[] } };
      expect(config.data?.labels).toEqual(['English', 'Spanish', 'French']);
    });

    it('configures stacked bar chart options', async () => {
      document.body.innerHTML = '<canvas id="intensityChart"></canvas>';

      const data = [{ name: 'Test', s1: 1, s2: 2, s3: 3, s4: 4, s5: 5, s99: 6 }];

      await initIntensityChart('intensityChart', data);

      const config = chartConstructorCalls[0][1] as { options?: { scales?: { x?: { stacked?: boolean }; y?: { stacked?: boolean } } } };
      expect(config.options?.scales?.x?.stacked).toBe(true);
      expect(config.options?.scales?.y?.stacked).toBe(true);
    });

    it('creates correct number of datasets (6 status levels)', async () => {
      document.body.innerHTML = '<canvas id="intensityChart"></canvas>';

      const data = [{ name: 'Test', s1: 1, s2: 2, s3: 3, s4: 4, s5: 5, s99: 6 }];

      await initIntensityChart('intensityChart', data);

      const config = chartConstructorCalls[0][1] as { data?: { datasets?: unknown[] } };
      const datasets = config.data?.datasets;
      expect(datasets).toHaveLength(6);
    });

    it('sets correct labels for each dataset', async () => {
      document.body.innerHTML = '<canvas id="intensityChart"></canvas>';

      const data = [{ name: 'Test', s1: 1, s2: 2, s3: 3, s4: 4, s5: 5, s99: 6 }];

      await initIntensityChart('intensityChart', data);

      const config = chartConstructorCalls[0][1] as { data?: { datasets?: Array<{ label?: string }> } };
      const datasets = config.data?.datasets || [];
      const labels = datasets.map(d => d.label);

      // Status 1 is a word being learnt, not an unknown one -- "Unknown" is
      // the pseudo-status 0 for a word not in the vocabulary at all. The chart
      // called it "Unknown (1)" until the labels came from the shared store
      // (#238), which also makes them localizable.
      expect(labels).toContain('Learning (1)');
      expect(labels).toContain('Learning (2)');
      expect(labels).toContain('Learning (3)');
      expect(labels).toContain('Learning (4)');
      expect(labels).toContain('Learned (5)');
      // 99 is a manual flag, not a stage, so the store gives it a bare name.
      expect(labels).toContain('Well Known');
    });

    it('handles data with zero values', async () => {
      document.body.innerHTML = '<canvas id="intensityChart"></canvas>';

      const data = [{ name: 'Empty', s1: 0, s2: 0, s3: 0, s4: 0, s5: 0, s99: 0 }];

      const result = await initIntensityChart('intensityChart', data);

      expect(chartConstructorCalls.length).toBe(1);
      expect(result).not.toBeNull();
    });

    it('handles large numeric values', async () => {
      document.body.innerHTML = '<canvas id="intensityChart"></canvas>';

      const data = [
        { name: 'Large', s1: 10000, s2: 20000, s3: 30000, s4: 40000, s5: 50000, s99: 100000 }
      ];

      const result = await initIntensityChart('intensityChart', data);

      expect(chartConstructorCalls.length).toBe(1);
      expect(result).not.toBeNull();
    });
  });

  // ===========================================================================
  // initFrequencyChart Tests
  // ===========================================================================

  describe('initFrequencyChart', () => {
    it('returns null when canvas element does not exist', async () => {
      const totals = {
        ct: 0, at: 0, kt: 0,
        cy: 0, ay: 0, ky: 0,
        cw: 0, aw: 0, kw: 0,
        cm: 0, am: 0, km: 0,
        ca: 0, aa: 0, ka: 0
      };

      const result = await initFrequencyChart('nonexistent-canvas', totals);

      expect(result).toBeNull();
    });

    it('creates chart when canvas exists', async () => {
      document.body.innerHTML = '<canvas id="frequencyChart"></canvas>';

      const totals = {
        ct: 5, at: 10, kt: 2,
        cy: 3, ay: 8, ky: 1,
        cw: 20, aw: 50, kw: 10,
        cm: 100, am: 200, km: 50,
        ca: 500, aa: 1000, ka: 300
      };

      const result = await initFrequencyChart('frequencyChart', totals);

      expect(chartConstructorCalls.length).toBe(1);
      expect(result).not.toBeNull();
    });

    it('configures line chart type', async () => {
      document.body.innerHTML = '<canvas id="frequencyChart"></canvas>';

      const totals = {
        ct: 1, at: 2, kt: 3,
        cy: 4, ay: 5, ky: 6,
        cw: 7, aw: 8, kw: 9,
        cm: 10, am: 11, km: 12,
        ca: 13, aa: 14, ka: 15
      };

      await initFrequencyChart('frequencyChart', totals);

      const config = chartConstructorCalls[0][1] as { type?: string };
      expect(config.type).toBe('line');
    });

    it('creates correct time period labels', async () => {
      document.body.innerHTML = '<canvas id="frequencyChart"></canvas>';

      const totals = {
        ct: 0, at: 0, kt: 0,
        cy: 0, ay: 0, ky: 0,
        cw: 0, aw: 0, kw: 0,
        cm: 0, am: 0, km: 0,
        ca: 0, aa: 0, ka: 0
      };

      await initFrequencyChart('frequencyChart', totals);

      const config = chartConstructorCalls[0][1] as { data?: { labels?: string[] } };
      expect(config.data?.labels).toEqual([
        'Today',
        'Yesterday',
        'Last 7 Days',
        'Last 30 Days',
        'Last 365 Days'
      ]);
    });

    it('creates three datasets (Created, Activity, Known)', async () => {
      document.body.innerHTML = '<canvas id="frequencyChart"></canvas>';

      const totals = {
        ct: 0, at: 0, kt: 0,
        cy: 0, ay: 0, ky: 0,
        cw: 0, aw: 0, kw: 0,
        cm: 0, am: 0, km: 0,
        ca: 0, aa: 0, ka: 0
      };

      await initFrequencyChart('frequencyChart', totals);

      const config = chartConstructorCalls[0][1] as { data?: { datasets?: Array<{ label?: string }> } };
      const datasets = config.data?.datasets || [];

      expect(datasets).toHaveLength(3);
      expect(datasets[0].label).toBe('Created');
      expect(datasets[1].label).toBe('Activity');
      expect(datasets[2].label).toBe('Known');
    });

    it('maps data correctly to datasets', async () => {
      document.body.innerHTML = '<canvas id="frequencyChart"></canvas>';

      const totals = {
        ct: 1, at: 2, kt: 3,
        cy: 4, ay: 5, ky: 6,
        cw: 7, aw: 8, kw: 9,
        cm: 10, am: 11, km: 12,
        ca: 13, aa: 14, ka: 15
      };

      await initFrequencyChart('frequencyChart', totals);

      const config = chartConstructorCalls[0][1] as { data?: { datasets?: Array<{ data?: number[] }> } };
      const datasets = config.data?.datasets || [];

      // Created dataset
      expect(datasets[0].data).toEqual([1, 4, 7, 10, 13]);
      // Activity dataset
      expect(datasets[1].data).toEqual([2, 5, 8, 11, 14]);
      // Known dataset
      expect(datasets[2].data).toEqual([3, 6, 9, 12, 15]);
    });

    it('sets y-axis to begin at zero', async () => {
      document.body.innerHTML = '<canvas id="frequencyChart"></canvas>';

      const totals = {
        ct: 0, at: 0, kt: 0,
        cy: 0, ay: 0, ky: 0,
        cw: 0, aw: 0, kw: 0,
        cm: 0, am: 0, km: 0,
        ca: 0, aa: 0, ka: 0
      };

      await initFrequencyChart('frequencyChart', totals);

      const config = chartConstructorCalls[0][1] as { options?: { scales?: { y?: { beginAtZero?: boolean } } } };
      expect(config.options?.scales?.y?.beginAtZero).toBe(true);
    });

    it('handles all zero values', async () => {
      document.body.innerHTML = '<canvas id="frequencyChart"></canvas>';

      const totals = {
        ct: 0, at: 0, kt: 0,
        cy: 0, ay: 0, ky: 0,
        cw: 0, aw: 0, kw: 0,
        cm: 0, am: 0, km: 0,
        ca: 0, aa: 0, ka: 0
      };

      const result = await initFrequencyChart('frequencyChart', totals);

      expect(result).not.toBeNull();
    });
  });

  // ===========================================================================
  // initStatisticsCharts Tests
  // ===========================================================================

  describe('initStatisticsCharts', () => {
    it('does nothing when no data elements exist', () => {
      vi.spyOn(console, 'warn').mockImplementation(() => {}); // Suppress deprecation warning
      document.body.innerHTML = '<div>No statistics here</div>';

      initStatisticsCharts();

      expect(chartConstructorCalls.length).toBe(0);
    });

    it('initializes intensity chart from data element (statisticsApp)', async () => {
      const intensityData = [
        { name: 'English', s1: 10, s2: 20, s3: 30, s4: 15, s5: 25, s99: 100 }
      ];

      document.body.innerHTML = `
        <script type="application/json" id="statistics-intensity-data">
          ${JSON.stringify({ languages: intensityData })}
        </script>
        <canvas id="intensityChart"></canvas>
      `;

      const app = statisticsApp();
      // Mock $nextTick to call immediately
      (app as unknown as { $nextTick: (cb: () => void) => void }).$nextTick = (cb) => cb();
      await app.init();

      expect(chartConstructorCalls.length).toBe(1);
    });

    it('initializes frequency chart from data element (statisticsApp)', async () => {
      const frequencyTotals = {
        ct: 5, at: 10, kt: 2,
        cy: 3, ay: 8, ky: 1,
        cw: 20, aw: 50, kw: 10,
        cm: 100, am: 200, km: 50,
        ca: 500, aa: 1000, ka: 300
      };

      document.body.innerHTML = `
        <script type="application/json" id="statistics-frequency-data">
          ${JSON.stringify({ totals: frequencyTotals })}
        </script>
        <canvas id="frequencyChart"></canvas>
      `;

      const app = statisticsApp();
      (app as unknown as { $nextTick: (cb: () => void) => void }).$nextTick = (cb) => cb();
      await app.init();

      expect(chartConstructorCalls.length).toBe(1);
    });

    it('initializes both charts when both data elements exist (statisticsApp)', async () => {
      const intensityData = [
        { name: 'English', s1: 10, s2: 20, s3: 30, s4: 15, s5: 25, s99: 100 }
      ];
      const frequencyTotals = {
        ct: 5, at: 10, kt: 2,
        cy: 3, ay: 8, ky: 1,
        cw: 20, aw: 50, kw: 10,
        cm: 100, am: 200, km: 50,
        ca: 500, aa: 1000, ka: 300
      };

      document.body.innerHTML = `
        <script type="application/json" id="statistics-intensity-data">
          ${JSON.stringify({ languages: intensityData })}
        </script>
        <canvas id="intensityChart"></canvas>
        <script type="application/json" id="statistics-frequency-data">
          ${JSON.stringify({ totals: frequencyTotals })}
        </script>
        <canvas id="frequencyChart"></canvas>
      `;

      const app = statisticsApp();
      (app as unknown as { $nextTick: (cb: () => void) => void }).$nextTick = (cb) => cb();
      await app.init();

      expect(chartConstructorCalls.length).toBe(2);
    });

    it('handles empty intensity data array', () => {
      vi.spyOn(console, 'warn').mockImplementation(() => {}); // Suppress deprecation warning
      document.body.innerHTML = `
        <div id="statistics-intensity-data" data-languages='[]'></div>
        <canvas id="intensityChart"></canvas>
      `;

      initStatisticsCharts();

      // Should not create chart for empty data
      expect(chartConstructorCalls.length).toBe(0);
    });

    it('handles missing data attribute on intensity element', () => {
      vi.spyOn(console, 'warn').mockImplementation(() => {}); // Suppress deprecation warning
      document.body.innerHTML = `
        <div id="statistics-intensity-data"></div>
        <canvas id="intensityChart"></canvas>
      `;

      // Should not throw
      expect(() => initStatisticsCharts()).not.toThrow();
    });

    it('handles missing data attribute on frequency element', () => {
      vi.spyOn(console, 'warn').mockImplementation(() => {}); // Suppress deprecation warning
      document.body.innerHTML = `
        <div id="statistics-frequency-data"></div>
        <canvas id="frequencyChart"></canvas>
      `;

      // Should not throw
      expect(() => initStatisticsCharts()).not.toThrow();
    });

    it('handles invalid JSON in intensity data (statisticsApp)', () => {
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

      document.body.innerHTML = `
        <script type="application/json" id="statistics-intensity-data">invalid json</script>
        <canvas id="intensityChart"></canvas>
      `;

      const app = statisticsApp();
      (app as unknown as { $nextTick: (cb: () => void) => void }).$nextTick = (cb) => cb();
      app.init();

      expect(consoleSpy).toHaveBeenCalled();
      expect(chartConstructorCalls.length).toBe(0);
    });

    it('handles invalid JSON in frequency data (statisticsApp)', () => {
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

      document.body.innerHTML = `
        <script type="application/json" id="statistics-frequency-data">not valid json</script>
        <canvas id="frequencyChart"></canvas>
      `;

      const app = statisticsApp();
      (app as unknown as { $nextTick: (cb: () => void) => void }).$nextTick = (cb) => cb();
      app.init();

      expect(consoleSpy).toHaveBeenCalled();
      expect(chartConstructorCalls.length).toBe(0);
    });

    it('handles data element without canvas', () => {
      vi.spyOn(console, 'warn').mockImplementation(() => {}); // Suppress deprecation warning
      const intensityData = [
        { name: 'English', s1: 10, s2: 20, s3: 30, s4: 15, s5: 25, s99: 100 }
      ];

      document.body.innerHTML = `
        <div id="statistics-intensity-data" data-languages='${JSON.stringify(intensityData)}'></div>
      `;

      // Should not throw when canvas is missing
      expect(() => initStatisticsCharts()).not.toThrow();
    });
  });

  // ===========================================================================
  // Edge Cases
  // ===========================================================================

  describe('Edge Cases', () => {
    it('handles language names with special characters', async () => {
      document.body.innerHTML = '<canvas id="intensityChart"></canvas>';

      const data = [
        { name: '日本語 (Japanese)', s1: 10, s2: 20, s3: 30, s4: 15, s5: 25, s99: 100 },
        { name: 'Português', s1: 5, s2: 10, s3: 15, s4: 20, s5: 50, s99: 200 }
      ];

      const result = await initIntensityChart('intensityChart', data);

      expect(result).not.toBeNull();
      const config = chartConstructorCalls[0][1] as { data?: { labels?: string[] } };
      expect(config.data?.labels).toContain('日本語 (Japanese)');
      expect(config.data?.labels).toContain('Português');
    });

    it('handles negative values in data (edge case)', async () => {
      document.body.innerHTML = '<canvas id="frequencyChart"></canvas>';

      // In real usage these should never be negative, but test graceful handling
      const totals = {
        ct: -1, at: 0, kt: 0,
        cy: 0, ay: 0, ky: 0,
        cw: 0, aw: 0, kw: 0,
        cm: 0, am: 0, km: 0,
        ca: 0, aa: 0, ka: 0
      };

      const result = await initFrequencyChart('frequencyChart', totals);

      expect(result).not.toBeNull();
    });

    it('handles decimal values', async () => {
      document.body.innerHTML = '<canvas id="intensityChart"></canvas>';

      const data = [
        { name: 'Test', s1: 10.5, s2: 20.3, s3: 30.7, s4: 15.1, s5: 25.9, s99: 100.0 }
      ];

      const result = await initIntensityChart('intensityChart', data);

      expect(result).not.toBeNull();
    });

    it('handles very long language names', async () => {
      document.body.innerHTML = '<canvas id="intensityChart"></canvas>';

      const longName = 'A'.repeat(100);
      const data = [{ name: longName, s1: 1, s2: 2, s3: 3, s4: 4, s5: 5, s99: 6 }];

      const result = await initIntensityChart('intensityChart', data);

      expect(result).not.toBeNull();
    });

    it('handles many languages', async () => {
      document.body.innerHTML = '<canvas id="intensityChart"></canvas>';

      const data = Array.from({ length: 50 }, (_, i) => ({
        name: `Language ${i + 1}`,
        s1: i,
        s2: i * 2,
        s3: i * 3,
        s4: i * 4,
        s5: i * 5,
        s99: i * 10
      }));

      const result = await initIntensityChart('intensityChart', data);

      expect(result).not.toBeNull();
      const config = chartConstructorCalls[0][1] as { data?: { labels?: string[] } };
      expect(config.data?.labels).toHaveLength(50);
    });
  });
});
