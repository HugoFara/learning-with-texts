/**
 * XPath Utilities - Pure functions for XPath operations in feed wizard.
 *
 * These functions are stateless and can be used independently of Alpine.js.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import type { XPathOption, AdvancedXPathOption } from '../types/feed_wizard_types';

/**
 * Execute an XPath expression and return matching elements as an array.
 * Supports pipe-separated multiple expressions (e.g., "//div | //span").
 *
 * @param expression - XPath expression to evaluate
 * @param context - Context node for evaluation (defaults to document)
 * @returns Array of matched HTMLElements
 */
export function xpathQuery(expression: string, context: Node = document): HTMLElement[] {
  const results: HTMLElement[] = [];

  // Handle pipe-separated expressions (e.g., "//div[@id='x'] | //p[@class='y']")
  const expressions = expression.split(/\s*\|\s*/).filter(e => e.trim());

  for (const expr of expressions) {
    try {
      const xpathResult = document.evaluate(
        expr,
        context,
        null,
        XPathResult.ORDERED_NODE_SNAPSHOT_TYPE,
        null
      );
      for (let i = 0; i < xpathResult.snapshotLength; i++) {
        const node = xpathResult.snapshotItem(i);
        if (node instanceof HTMLElement && !results.includes(node)) {
          results.push(node);
        }
      }
    } catch {
      // Invalid XPath - skip this expression
    }
  }

  return results;
}

/**
 * Validate an XPath expression without executing it fully.
 *
 * @param expression - XPath expression to validate
 * @returns true if expression is valid, false otherwise
 */
export function isValidXPath(expression: string): boolean {
  if (!expression || expression.trim() === '') {
    return false;
  }
  try {
    document.evaluate(expression, document, null, XPathResult.ANY_TYPE, null);
    return true;
  } catch {
    return false;
  }
}

/**
 * Combine multiple XPath expressions with pipe separator.
 *
 * @param xpaths - Array of XPath expressions
 * @returns Combined expression with pipe separators
 */
export function combineXPaths(xpaths: string[]): string {
  return xpaths.filter(x => x.trim()).join(' | ');
}

/**
 * Convert an XPath expression to a display label for the UI.
 *
 * @param xpath - XPath expression
 * @returns Human-readable label
 */
export function xpathToDisplayLabel(xpath: string): string {
  // Extract the main tag and key attributes for display
  return xpath
    .replace(/@/g, '')
    .replace('//', '')
    .replace(/ and /g, '][')
    .replace('§', '>')
    .substring(0, 50);
}

/**
 * Get all ancestor elements of an element (excluding html and body).
 *
 * @param element - Starting element
 * @returns Array of ancestor HTMLElements from immediate parent outward
 */
export function getAncestors(element: HTMLElement): HTMLElement[] {
  const ancestors: HTMLElement[] = [];
  let current = element.parentElement;
  while (current && current !== document.body && current !== document.documentElement) {
    ancestors.push(current);
    current = current.parentElement;
  }
  return ancestors;
}

/**
 * Get an element and all its descendants.
 *
 * @param element - Starting element
 * @returns Array including the element and all descendants
 */
export function getDescendantsAndSelf(element: HTMLElement): HTMLElement[] {
  const result: HTMLElement[] = [element];
  element.querySelectorAll('*').forEach(child => {
    if (child instanceof HTMLElement) {
      result.push(child);
    }
  });
  return result;
}

/**
 * Get an element and all its ancestors (excluding html and body).
 *
 * @param element - Starting element
 * @returns Array including the element and all ancestors
 */
export function getAncestorsAndSelf(element: HTMLElement): HTMLElement[] {
  const result: HTMLElement[] = [element];
  let current = element.parentElement;
  while (current && current !== document.body && current !== document.documentElement) {
    result.push(current);
    current = current.parentElement;
  }
  return result;
}

/**
 * Build attribute string for XPath from element attributes.
 *
 * @param element - Element to extract attributes from
 * @param excludeClasses - Classes to exclude (e.g., wizard CSS classes)
 * @returns Object with xpath predicate and display string
 */
function buildAttributeXPath(
  element: HTMLElement,
  excludeClasses: string[] = []
): { xpath: string; display: string } | null {
  const attrs = element.attributes;
  if (attrs.length === 0) return null;

  const parts: string[] = [];
  let display = '';

  for (let i = 0; i < attrs.length; i++) {
    const attr = attrs.item(i)!;
    const name = attr.nodeName;
    let value = attr.nodeValue ?? '';

    // Skip empty or wizard-related attributes
    if (!value || name.startsWith('data-')) continue;

    // Filter out wizard classes from class attribute
    if (name === 'class') {
      const classes = value.split(/\s+/).filter(c =>
        c && !excludeClasses.includes(c) && !c.startsWith('lwt_')
      );
      if (classes.length === 0) continue;
      value = classes.join(' ');
    }

    parts.push(`@${name}="${value}"`);
    display += ` ${name}="${value.substring(0, 20)}${value.length > 20 ? '...' : ''}"`;
  }

  if (parts.length === 0) return null;

  return {
    xpath: '[' + parts.join(' and ') + ']',
    display: display.trim()
  };
}

/**
 * Determine the attribute mode for XPath generation based on element structure.
 *
 * @param element - Element to analyze
 * @param forceAll - Whether to force all-attributes mode
 * @returns Attribute mode number
 */
function determineAttributeMode(element: HTMLElement, forceAll: boolean): number {
  if (forceAll) return 5;
  if (element.id) return 1;
  if (element.parentElement?.id) return 2;
  if (element.className) return 3;
  if (element.parentElement?.className) return 4;
  return 5;
}

/**
 * Generate mark action options for an element based on selection mode.
 *
 * This is the main function for generating XPath options when a user clicks
 * on an element in the feed content.
 *
 * @param element - Clicked element
 * @param selectionMode - Current selection mode ('smart', 'all', 'adv')
 * @returns Array of XPath options for the dropdown
 */
export function generateMarkActionOptions(
  element: HTMLElement,
  selectionMode: 'smart' | 'all' | 'adv'
): XPathOption[] {
  const options: XPathOption[] = [];
  const tagName = element.tagName.toLowerCase();
  const forceAll = selectionMode !== 'smart';
  const attrMode = determineAttributeMode(element, forceAll);

  // Build element's own attributes
  let attrXPath = '';
  const attrs = element.attributes;
  for (let i = 0; i < attrs.length; i++) {
    const attr = attrs.item(i)!;
    const name = attr.nodeName;
    const value = attr.nodeValue ?? '';

    // Include based on mode
    if (attrMode === 5 ||
        (name === 'class' && attrMode !== 1) ||
        name === 'id') {
      if (attrXPath) attrXPath += ' and ';
      attrXPath += `@${name}="${value}"`;
    }
  }
  if (attrXPath) attrXPath = '[' + attrXPath + ']';

  // Build parent's attributes if needed
  let parentXPath = '';
  if (attrMode !== 1 && attrMode !== 3 && element.parentElement) {
    const parent = element.parentElement;
    const parentAttrs = parent.attributes;
    const parts: string[] = [];

    for (let i = 0; i < parentAttrs.length; i++) {
      const attr = parentAttrs.item(i)!;
      const name = attr.nodeName;
      const value = attr.nodeValue ?? '';

      if (attrMode === 5 ||
          (name === 'class' && attrMode !== 2) ||
          name === 'id') {
        parts.push(`@${name}="${value}"`);
      }
    }

    if (parts.length > 0) {
      parentXPath = parent.tagName.toLowerCase() + '[' + parts.join(' and ') + ']§';
    }
  }

  // Remove body from parent path
  parentXPath = parentXPath.replace('body§', '');

  // Build the full XPath
  const fullXPath = '//' + parentXPath.replace('=""', '').replace('[ and @', '[@') +
    tagName + attrXPath.replace('=""', '').replace('[ and @', '[@');

  // Build display label
  let displayAttrs = '';
  for (let i = 0; i < Math.min(attrs.length, 2); i++) {
    const attr = attrs.item(i)!;
    displayAttrs += ` ${attr.nodeName}="${(attr.nodeValue ?? '').substring(0, 15)}"`;
  }
  if (attrs.length > 2) displayAttrs += '...';

  options.push({
    value: fullXPath,
    label: `<${tagName}${displayAttrs}>`,
    tagName: element.tagName
  });

  return options;
}

/**
 * Generate advanced XPath options for an element.
 *
 * This creates the radio button options shown in the advanced selection modal.
 *
 * @param element - Element to generate options for
 * @param parentTagName - Tag name from parent context (optional)
 * @returns Array of advanced XPath options
 */
export function generateAdvancedXPathOptions(
  element: HTMLElement,
  parentTagName?: string
): AdvancedXPathOption[] {
  const options: AdvancedXPathOption[] = [];
  const tagName = parentTagName || element.tagName.toLowerCase();

  // Element's own ID
  if (element.id) {
    const idParts = element.id.split(/\s+/);
    for (const idPart of idParts) {
      if (!idPart) continue;
      options.push({
        type: 'id',
        label: `contains id: «${idPart}»`,
        xpath: `//*[@id[contains(concat(" ",normalize-space(.)," ")," ${idPart} ")]]`
      });
    }
  }

  // Element's classes
  if (element.className) {
    const classes = element.className.split(/\s+/).filter(c =>
      c && !c.startsWith('lwt_')
    );
    for (const cls of classes) {
      options.push({
        type: 'class',
        label: `contains class: «${cls}»`,
        xpath: `//*[@class[contains(concat(" ",normalize-space(.)," ")," ${cls} ")]]`
      });
    }
  }

  // Parent's ID
  const parent = element.parentElement;
  if (parent && parent.id) {
    const idParts = parent.id.split(/\s+/);
    for (const idPart of idParts) {
      if (!idPart) continue;
      options.push({
        type: 'parent-id',
        label: `parent contains id: «${idPart}»`,
        xpath: `//*[@id[contains(concat(" ",normalize-space(.)," ")," ${idPart} ")]]/${tagName}`
      });
    }
  }

  // Parent's classes
  if (parent && parent.className) {
    const classes = parent.className.split(/\s+/).filter(c =>
      c && !c.startsWith('lwt_') && c !== 'lwt_filtered_text'
    );
    for (const cls of classes) {
      options.push({
        type: 'parent-class',
        label: `parent contains class: «${cls}»`,
        xpath: `//*[@class[contains(concat(" ",normalize-space(.)," ")," ${cls} ")]]/${tagName}`
      });
    }
  }

  // Full path with all attributes
  const fullPath = buildFullXPathWithAncestors(element);
  if (fullPath) {
    options.push({
      type: 'all',
      label: `all: « ${fullPath.display} »`,
      xpath: fullPath.xpath
    });
  }

  return options;
}

/**
 * Build a full XPath expression including ancestor attributes.
 *
 * @param element - Target element
 * @returns Object with xpath and display label, or null if unable to build
 */
function buildFullXPathWithAncestors(
  element: HTMLElement
): { xpath: string; display: string } | null {
  const tagName = element.tagName.toLowerCase();
  const attrResult = buildAttributeXPath(element);
  const attrXPath = attrResult?.xpath ?? '';

  // Build ancestor path
  let ancestorPath = '';
  const ancestors = getAncestors(element);

  for (const ancestor of ancestors) {
    const ancestorAttrs = buildAttributeXPath(ancestor, ['lwt_filtered_text']);
    const ancestorXPath = ancestorAttrs?.xpath ?? '';
    ancestorPath = ancestor.tagName.toLowerCase() + ancestorXPath + '/' + ancestorPath;
  }

  const fullXPath = '/' + ancestorPath + tagName + attrXPath;
  const displayPath = fullXPath.replace(/=""/g, '');

  return {
    xpath: fullXPath,
    display: displayPath.substring(0, 60) + (displayPath.length > 60 ? '...' : '')
  };
}

/**
 * Convert an XPath expression to a CSS selector for querySelector.
 *
 * This is a simplified conversion that handles common patterns.
 * Not all XPath expressions can be converted to CSS selectors.
 *
 * @param xpath - XPath expression
 * @returns CSS selector string, or null if conversion not possible
 */
export function xpathToCssSelector(xpath: string): string | null {
  try {
    // Simple conversions for common patterns
    return xpath
      .replace(/@/g, '')
      .replace('//', '')
      .replace(/ and /g, '][')
      .replace('§', '>');
  } catch {
    return null;
  }
}

/**
 * Check if an element or its ancestors have a specific class.
 *
 * @param element - Element to check
 * @param className - Class name to look for
 * @returns True if element or any ancestor has the class
 */
export function hasClassInAncestry(element: HTMLElement, className: string): boolean {
  let current: HTMLElement | null = element;
  while (current && current !== document.body) {
    if (current.classList.contains(className)) {
      return true;
    }
    current = current.parentElement;
  }
  return false;
}

// Export functions to window for backward compatibility with PHP views
declare global {
  interface Window {
    xpathQuery: typeof xpathQuery;
    isValidXPath: typeof isValidXPath;
  }
}

window.xpathQuery = xpathQuery;
window.isValidXPath = isValidXPath;
