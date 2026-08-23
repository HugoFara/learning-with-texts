/**
 * Tests for the banner shown when a text parses into (almost) nothing (#278).
 */
import { describe, it, expect } from 'vitest';
import { renderParseWarning } from '../../../src/frontend/js/modules/text/pages/reading/text_renderer';

const warning = {
  headline: 'None of this text could be turned into words.',
  detail: "The language's Word Characters setting does not match this text.",
  linkLabel: 'Check the language settings',
  linkHref: '/languages/7/edit'
};

describe('renderParseWarning', () => {
  it('renders nothing when the parse was fine', () => {
    expect(renderParseWarning(null)).toBe('');
  });

  it('shows what went wrong and where to fix it', () => {
    const html = renderParseWarning(warning);

    expect(html).toContain('notification is-warning');
    expect(html).toContain('None of this text could be turned into words.');
    expect(html).toContain('does not match this text');
    expect(html).toContain('href="/languages/7/edit"');
    expect(html).toContain('Check the language settings');
  });

  it('escapes the server-supplied text', () => {
    const html = renderParseWarning({
      ...warning,
      headline: '<script>alert(1)</script>',
      linkHref: '/languages/1/edit"onmouseover="alert(1)'
    });

    expect(html).not.toContain('<script>');
    expect(html).toContain('&lt;script&gt;');
    // The quote must not close the href and start a new attribute
    expect(html).not.toContain('onmouseover="alert(1)"');
    expect(html).toContain('&quot;');
  });

  it('keeps the link relative', () => {
    expect(renderParseWarning(warning)).toContain('href="/languages/7/edit"');
  });
});
