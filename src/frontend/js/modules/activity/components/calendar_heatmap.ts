/**
 * Calendar Heatmap Component - GitHub-style contribution grid.
 *
 * Renders an SVG calendar heatmap for the last 365 days.
 * Built via DOM API (createElementNS) for CSP compatibility.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
import { activeLocale, t, tn } from '@shared/i18n/translator';

const SVG_NS = 'http://www.w3.org/2000/svg';

/** Cell size and gap for the grid. */
const CELL_SIZE = 11;
const CELL_GAP = 2;
const CELL_STEP = CELL_SIZE + CELL_GAP;

/** Color scale: 0 = empty, 1-4 = increasing intensity. */
const COLORS = ['#ebedf0', '#9be9a8', '#40c463', '#30a14e', '#216e39'];

/** Rows (0 = Sunday) that get a day-of-week label on the left axis. */
const LABELLED_ROWS = [1, 3, 5];

/**
 * The interface locale with the Gregorian calendar forced, since the grid
 * is laid out by Gregorian month ("fa" would otherwise default to the
 * Persian calendar and name months that do not match the columns).
 */
function dateLocale(): string {
  return activeLocale() + '-u-ca-gregory';
}

/** Short month names, January first, in the interface language. */
function monthLabels(): string[] {
  const format = new Intl.DateTimeFormat(dateLocale(), { month: 'short' });
  return Array.from({ length: 12 }, (_, m) => format.format(new Date(2024, m, 1)));
}

/** Short weekday names by row (0 = Sunday), in the interface language. */
function dayLabels(): string[] {
  const format = new Intl.DateTimeFormat(dateLocale(), { weekday: 'short' });
  // 7 January 2024 was a Sunday.
  return Array.from({ length: 7 }, (_, row) => format.format(new Date(2024, 0, 7 + row)));
}

interface CalendarDay {
  date: string;
  total: number;
  created: number;
  reviewed: number;
  read: number;
  col: number;
  row: number;
}

interface DayActivity {
  total: number;
  created: number;
  reviewed: number;
  read: number;
}

interface HeatmapData {
  [date: string]: DayActivity;
}

interface CalendarHeatmapState {
  loading: boolean;
  error: string;
  init(this: CalendarHeatmapState & { $refs: Record<string, HTMLElement> }): void;
  buildCalendar(container: HTMLElement, data: HeatmapData): void;
}

/**
 * Map a raw activity count to a color index (0-4).
 */
function colorLevel(value: number, max: number): number {
  if (value === 0) return 0;
  if (max <= 0) return 1;
  const ratio = value / max;
  if (ratio <= 0.25) return 1;
  if (ratio <= 0.50) return 2;
  if (ratio <= 0.75) return 3;
  return 4;
}

/**
 * Format a date string (YYYY-MM-DD) for tooltip display.
 */
function formatDate(dateStr: string): string {
  const d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString(dateLocale(), {
    weekday: 'short', month: 'short', day: 'numeric', year: 'numeric'
  });
}

/**
 * Build a tooltip string showing the activity breakdown for a day.
 */
function formatTooltip(day: CalendarDay): string {
  const date = formatDate(day.date);
  if (day.total === 0) return t('user.statistics.no_activity_on', { date });
  const parts: string[] = [];
  if (day.created > 0) parts.push(tn('user.statistics.today_created', day.created));
  if (day.reviewed > 0) parts.push(tn('user.statistics.today_reviewed', day.reviewed));
  if (day.read > 0) parts.push(tn('user.statistics.today_read', day.read));
  return t('user.statistics.day_summary', { summary: parts.join(' · '), date });
}

/**
 * Build a grid of CalendarDay objects for the last 365 days.
 */
function buildDayGrid(data: HeatmapData): { days: CalendarDay[]; weeks: number } {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const days: CalendarDay[] = [];

  // Go back 364 days so we cover ~52 full weeks + today
  const start = new Date(today);
  start.setDate(start.getDate() - 364);
  // Align start to the previous Sunday
  start.setDate(start.getDate() - start.getDay());

  let col = 0;
  const cursor = new Date(start);
  while (cursor <= today) {
    const dateStr = cursor.toISOString().slice(0, 10);
    const row = cursor.getDay(); // 0=Sun, 6=Sat
    const entry = data[dateStr];
    days.push({
      date: dateStr,
      total: entry?.total ?? 0,
      created: entry?.created ?? 0,
      reviewed: entry?.reviewed ?? 0,
      read: entry?.read ?? 0,
      col,
      row,
    });
    cursor.setDate(cursor.getDate() + 1);
    if (cursor.getDay() === 0) col++;
  }
  return { days, weeks: col + 1 };
}

/**
 * Alpine.js component for the calendar heatmap.
 */
export function calendarHeatmap(): CalendarHeatmapState {
  return {
    loading: true,
    error: '',

    init() {
      const container = this.$refs.svgContainer;
      if (!container) return;

      fetch('/api/v1/activity/calendar')
        .then(r => r.json())
        .then((data: HeatmapData) => {
          this.loading = false;
          this.buildCalendar(container, data);
        })
        .catch(() => {
          this.loading = false;
          this.error = t('user.statistics.load_failed');
        });
    },

    buildCalendar(container: HTMLElement, data: HeatmapData) {
      const { days, weeks } = buildDayGrid(data);
      const maxVal = Math.max(...days.map(d => d.total), 1);

      const days7 = dayLabels();
      const months = monthLabels();
      // Space for the day-of-week labels: "Mon" fits in 30px, "الاثنين" does not.
      const longest = Math.max(...LABELLED_ROWS.map(row => days7[row].length));
      const leftPad = Math.max(30, longest * 6 + 6);
      const topPad = 16;    // space for month labels
      const svgWidth = leftPad + weeks * CELL_STEP;
      const svgHeight = topPad + 7 * CELL_STEP;

      const svg = document.createElementNS(SVG_NS, 'svg');
      svg.setAttribute('width', String(svgWidth));
      svg.setAttribute('height', String(svgHeight));
      svg.setAttribute('viewBox', `0 0 ${svgWidth} ${svgHeight}`);
      svg.style.display = 'block';
      // A timeline laid out left to right by date. In an RTL page the text
      // would inherit direction: rtl and hang leftwards from its x, pushing
      // the weekday labels off the canvas.
      svg.setAttribute('direction', 'ltr');

      // Day-of-week labels
      for (const row of LABELLED_ROWS) {
        const text = document.createElementNS(SVG_NS, 'text');
        text.setAttribute('x', '0');
        text.setAttribute('y', String(topPad + row * CELL_STEP + CELL_SIZE));
        text.setAttribute('font-size', '10');
        text.setAttribute('fill', '#767676');
        text.textContent = days7[row];
        svg.appendChild(text);
      }

      // Month labels — place at first Sunday of each month
      const monthsPlaced = new Set<number>();
      for (const day of days) {
        const d = new Date(day.date + 'T00:00:00');
        const m = d.getMonth();
        if (day.row === 0 && !monthsPlaced.has(m)) {
          monthsPlaced.add(m);
          const text = document.createElementNS(SVG_NS, 'text');
          text.setAttribute('x', String(leftPad + day.col * CELL_STEP));
          text.setAttribute('y', '10');
          text.setAttribute('font-size', '10');
          text.setAttribute('fill', '#767676');
          text.textContent = months[m];
          svg.appendChild(text);
        }
      }

      // Day cells
      const tooltip = document.createElement('div');
      tooltip.style.cssText =
        'position:fixed;padding:4px 8px;background:#24292f;color:#fff;' +
        'border-radius:4px;font-size:11px;pointer-events:none;display:none;z-index:100;';
      document.body.appendChild(tooltip);

      for (const day of days) {
        const rect = document.createElementNS(SVG_NS, 'rect');
        const x = leftPad + day.col * CELL_STEP;
        const y = topPad + day.row * CELL_STEP;
        rect.setAttribute('x', String(x));
        rect.setAttribute('y', String(y));
        rect.setAttribute('width', String(CELL_SIZE));
        rect.setAttribute('height', String(CELL_SIZE));
        rect.setAttribute('rx', '2');
        const level = colorLevel(day.total, maxVal);
        rect.setAttribute('fill', COLORS[level]);
        if (level === 0) {
          // A fill attribute cannot read a CSS variable, and this one follows
          // the theme, so that a dark page does not show a wall of white.
          rect.style.fill = 'var(--bulma-border, #ebedf0)';
        }

        const label = formatTooltip(day);
        rect.addEventListener('mouseenter', (e: MouseEvent) => {
          tooltip.textContent = label;
          tooltip.style.display = 'block';
          tooltip.style.left = e.clientX + 8 + 'px';
          tooltip.style.top = e.clientY - 28 + 'px';
        });
        rect.addEventListener('mouseleave', () => {
          tooltip.style.display = 'none';
        });

        svg.appendChild(rect);
      }

      container.appendChild(svg);
    },
  };
}

Alpine.data('calendarHeatmap', calendarHeatmap);
