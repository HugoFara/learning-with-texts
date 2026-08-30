/**
 * Reader for the JSON config islands that PHP views use to seed a page.
 *
 * A view renders `<script type="application/json" id="thing-config">` (see
 * `ConfigIsland` on the PHP side) and the page's Alpine component reads it
 * here. The indirection is forced by the CSP build of Alpine, which cannot
 * evaluate inline expressions, so server values can never be interpolated
 * straight into `x-data`.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since 3.6.1
 */

/**
 * Read a config island, falling back to defaults for anything missing.
 *
 * Never throws. A missing element, an empty blob or malformed JSON all
 * resolve to `defaults`, because a page that renders with default state is
 * far better than one whose component dies during `init()` and leaves the
 * user staring at an inert shell.
 *
 * Values are merged shallowly over `defaults`, so a partial blob only
 * overrides the keys it actually carries. `null` is treated as absent —
 * PHP nulls are usually "not set" rather than a meaningful value.
 *
 * @param id       DOM id of the script element, e.g. 'book-list-config'
 * @param defaults Complete default config; also fixes the return type
 *
 * @returns The parsed config merged over the defaults
 */
export function readPageConfig<T extends object>(id: string, defaults: T): T {
  const el = document.getElementById(id);
  if (!el) {
    return { ...defaults };
  }

  let parsed: unknown;
  try {
    parsed = JSON.parse(el.textContent || '{}');
  } catch {
    return { ...defaults };
  }

  if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
    return { ...defaults };
  }

  const merged = { ...defaults } as Record<string, unknown>;
  for (const [key, value] of Object.entries(parsed as Record<string, unknown>)) {
    if (value !== null && value !== undefined) {
      merged[key] = value;
    }
  }
  return merged as T;
}

/**
 * Whether a config island is present on the page.
 *
 * Entry points use this to decide whether the current page is theirs before
 * registering components or firing requests.
 *
 * @param id DOM id of the script element
 *
 * @returns True when the element exists
 */
export function hasPageConfig(id: string): boolean {
  return document.getElementById(id) !== null;
}
