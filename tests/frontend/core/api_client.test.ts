/**
 * Tests for core/api_client.ts - Centralized API client
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  apiGet,
  apiPost,
  apiPut,
  apiDelete,
  apiPostForm
} from '../../../src/frontend/js/shared/api/client';

describe('core/api_client.ts', () => {
  const mockFetch = vi.fn();
  const originalFetch = global.fetch;

  beforeEach(() => {
    vi.clearAllMocks();
    global.fetch = mockFetch;
  });

  afterEach(() => {
    vi.restoreAllMocks();
    global.fetch = originalFetch;
  });

  // ===========================================================================
  // apiGet Tests
  // ===========================================================================

  describe('apiGet', () => {
    it('makes GET request to correct URL', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{"data": "test"}')
      });

      await apiGet('/terms/1');

      expect(mockFetch).toHaveBeenCalledWith(
        expect.stringContaining('/api/v1/terms/1'),
        expect.objectContaining({ method: 'GET' })
      );
    });

    it('includes default headers', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiGet('/test');

      expect(mockFetch).toHaveBeenCalledWith(
        expect.any(String),
        expect.objectContaining({
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json'
          }
        })
      );
    });

    it('appends query parameters to URL', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiGet('/search', { q: 'test', limit: 10 });

      const calledUrl = mockFetch.mock.calls[0][0];
      expect(calledUrl).toContain('q=test');
      expect(calledUrl).toContain('limit=10');
    });

    it('omits undefined parameters', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiGet('/search', { q: 'test', limit: undefined });

      const calledUrl = mockFetch.mock.calls[0][0];
      expect(calledUrl).toContain('q=test');
      expect(calledUrl).not.toContain('limit');
    });

    it('returns parsed JSON data on success', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{"id": 1, "name": "test"}')
      });

      const result = await apiGet('/test');

      expect(result.data).toEqual({ id: 1, name: 'test' });
    });

    it('returns error on HTTP error response', async () => {
      mockFetch.mockResolvedValue({
        ok: false,
        status: 404,
        statusText: 'Not Found',
        text: () => Promise.resolve('{"message": "Resource not found"}')
      });

      const result = await apiGet('/nonexistent');

      expect(result.error).toBe('Resource not found');
    });

    it('returns generic error when no message in response', async () => {
      mockFetch.mockResolvedValue({
        ok: false,
        status: 500,
        statusText: 'Internal Server Error',
        text: () => Promise.resolve('{}')
      });

      const result = await apiGet('/test');

      expect(result.error).toBe('HTTP 500: Internal Server Error');
    });

    it('returns error on fetch failure', async () => {
      mockFetch.mockRejectedValue(new Error('Network error'));

      const result = await apiGet('/test');

      expect(result.error).toBe('Error: Network error');
    });

    it('handles empty response body', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('')
      });

      const result = await apiGet('/test');

      expect(result.data).toEqual({});
    });

    it('handles non-JSON response', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('plain text response')
      });

      const result = await apiGet('/test');

      expect(result.data).toEqual({ raw: 'plain text response' });
    });

    it('handles boolean query parameters', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiGet('/test', { active: true, disabled: false });

      const calledUrl = mockFetch.mock.calls[0][0];
      expect(calledUrl).toContain('active=true');
      expect(calledUrl).toContain('disabled=false');
    });
  });

  // ===========================================================================
  // apiPost Tests
  // ===========================================================================

  describe('apiPost', () => {
    it('makes POST request to correct URL', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiPost('/terms', { text: 'hello' });

      expect(mockFetch).toHaveBeenCalledWith(
        expect.stringContaining('/api/v1/terms'),
        expect.objectContaining({ method: 'POST' })
      );
    });

    it('sends JSON body', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiPost('/terms', { text: 'hello', langId: 1 });

      expect(mockFetch).toHaveBeenCalledWith(
        expect.any(String),
        expect.objectContaining({
          body: JSON.stringify({ text: 'hello', langId: 1 })
        })
      );
    });

    it('includes JSON content type header', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiPost('/terms', {});

      expect(mockFetch).toHaveBeenCalledWith(
        expect.any(String),
        expect.objectContaining({
          headers: expect.objectContaining({
            'Content-Type': 'application/json'
          })
        })
      );
    });

    it('returns parsed JSON data on success', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{"id": 123}')
      });

      const result = await apiPost('/terms', { text: 'test' });

      expect(result.data).toEqual({ id: 123 });
    });

    it('returns error on HTTP error response', async () => {
      mockFetch.mockResolvedValue({
        ok: false,
        status: 400,
        statusText: 'Bad Request',
        text: () => Promise.resolve('{"message": "Invalid data"}')
      });

      const result = await apiPost('/terms', {});

      expect(result.error).toBe('Invalid data');
    });

    it('returns error on fetch failure', async () => {
      mockFetch.mockRejectedValue(new Error('Connection refused'));

      const result = await apiPost('/terms', {});

      expect(result.error).toBe('Error: Connection refused');
    });

    it('handles nested objects in body', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      const body = { data: { nested: { value: 123 } } };
      await apiPost('/test', body);

      expect(mockFetch).toHaveBeenCalledWith(
        expect.any(String),
        expect.objectContaining({
          body: JSON.stringify(body)
        })
      );
    });

    it('handles arrays in body', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      const body = { items: [1, 2, 3], tags: ['a', 'b'] };
      await apiPost('/test', body);

      expect(mockFetch).toHaveBeenCalledWith(
        expect.any(String),
        expect.objectContaining({
          body: JSON.stringify(body)
        })
      );
    });
  });

  // ===========================================================================
  // apiPut Tests
  // ===========================================================================

  describe('apiPut', () => {
    it('makes PUT request to correct URL', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiPut('/terms/1', { translation: 'bonjour' });

      expect(mockFetch).toHaveBeenCalledWith(
        expect.stringContaining('/api/v1/terms/1'),
        expect.objectContaining({ method: 'PUT' })
      );
    });

    it('sends JSON body', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiPut('/terms/1', { translation: 'hola' });

      expect(mockFetch).toHaveBeenCalledWith(
        expect.any(String),
        expect.objectContaining({
          body: JSON.stringify({ translation: 'hola' })
        })
      );
    });

    it('returns parsed JSON data on success', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{"updated": true}')
      });

      const result = await apiPut('/terms/1', { status: 3 });

      expect(result.data).toEqual({ updated: true });
    });

    it('returns error on HTTP error response', async () => {
      mockFetch.mockResolvedValue({
        ok: false,
        status: 404,
        statusText: 'Not Found',
        text: () => Promise.resolve('{"message": "Term not found"}')
      });

      const result = await apiPut('/terms/999', {});

      expect(result.error).toBe('Term not found');
    });

    it('returns error on fetch failure', async () => {
      mockFetch.mockRejectedValue(new Error('Timeout'));

      const result = await apiPut('/terms/1', {});

      expect(result.error).toBe('Error: Timeout');
    });
  });

  // ===========================================================================
  // apiDelete Tests
  // ===========================================================================

  describe('apiDelete', () => {
    it('makes DELETE request to correct URL', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiDelete('/terms/1');

      expect(mockFetch).toHaveBeenCalledWith(
        expect.stringContaining('/api/v1/terms/1'),
        expect.objectContaining({ method: 'DELETE' })
      );
    });

    it('includes default headers', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiDelete('/terms/1');

      expect(mockFetch).toHaveBeenCalledWith(
        expect.any(String),
        expect.objectContaining({
          headers: expect.objectContaining({
            Accept: 'application/json'
          })
        })
      );
    });

    it('returns parsed JSON data on success', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{"deleted": true}')
      });

      const result = await apiDelete('/terms/1');

      expect(result.data).toEqual({ deleted: true });
    });

    it('returns error on HTTP error response', async () => {
      mockFetch.mockResolvedValue({
        ok: false,
        status: 403,
        statusText: 'Forbidden',
        text: () => Promise.resolve('{"message": "Not authorized"}')
      });

      const result = await apiDelete('/terms/1');

      expect(result.error).toBe('Not authorized');
    });

    it('returns error on fetch failure', async () => {
      mockFetch.mockRejectedValue(new Error('Server unavailable'));

      const result = await apiDelete('/terms/1');

      expect(result.error).toBe('Error: Server unavailable');
    });

    it('sends JSON body when body parameter is provided', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{"deleted": 3}')
      });

      await apiDelete('/feeds', { feed_ids: [1, 2, 3] });

      expect(mockFetch).toHaveBeenCalledWith(
        expect.stringContaining('/api/v1/feeds'),
        expect.objectContaining({
          method: 'DELETE',
          body: JSON.stringify({ feed_ids: [1, 2, 3] })
        })
      );
    });

    it('does not include body when body parameter is undefined', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiDelete('/terms/1');

      const calledOptions = mockFetch.mock.calls[0][1];
      expect(calledOptions.body).toBeUndefined();
    });

    it('sends body with nested objects', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{"success": true}')
      });

      const body = { article_ids: [10, 20], options: { force: true } };
      await apiDelete('/feeds/articles/1', body);

      expect(mockFetch).toHaveBeenCalledWith(
        expect.any(String),
        expect.objectContaining({
          body: JSON.stringify(body)
        })
      );
    });

    it('returns parsed data when body is provided', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{"success": true, "deleted": 5}')
      });

      const result = await apiDelete('/feeds', { feed_ids: [1, 2, 3, 4, 5] });

      expect(result.data).toEqual({ success: true, deleted: 5 });
    });

    it('returns error on HTTP error when body is provided', async () => {
      mockFetch.mockResolvedValue({
        ok: false,
        status: 400,
        statusText: 'Bad Request',
        text: () => Promise.resolve('{"message": "Invalid feed IDs"}')
      });

      const result = await apiDelete('/feeds', { feed_ids: [] });

      expect(result.error).toBe('Invalid feed IDs');
    });
  });

  // ===========================================================================
  // apiPostForm Tests
  // ===========================================================================

  describe('apiPostForm', () => {
    it('makes POST request to correct URL', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiPostForm('/terms/1/status', { status: '3' });

      expect(mockFetch).toHaveBeenCalledWith(
        expect.stringContaining('/api/v1/terms/1/status'),
        expect.objectContaining({ method: 'POST' })
      );
    });

    it('sends form-urlencoded body', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiPostForm('/test', { key: 'value', num: 123 });

      const calledOptions = mockFetch.mock.calls[0][1];
      expect(calledOptions.body).toContain('key=value');
      expect(calledOptions.body).toContain('num=123');
    });

    it('uses form-urlencoded content type', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiPostForm('/test', {});

      expect(mockFetch).toHaveBeenCalledWith(
        expect.any(String),
        expect.objectContaining({
          headers: expect.objectContaining({
            'Content-Type': 'application/x-www-form-urlencoded'
          })
        })
      );
    });

    it('returns parsed JSON data on success', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{"set": 3}')
      });

      const result = await apiPostForm('/terms/1/status', { status: '3' });

      expect(result.data).toEqual({ set: 3 });
    });

    it('returns error on HTTP error response', async () => {
      mockFetch.mockResolvedValue({
        ok: false,
        status: 400,
        statusText: 'Bad Request',
        text: () => Promise.resolve('{"message": "Invalid status"}')
      });

      const result = await apiPostForm('/terms/1/status', { status: '99' });

      expect(result.error).toBe('Invalid status');
    });

    it('returns error on fetch failure', async () => {
      mockFetch.mockRejectedValue(new Error('Network error'));

      const result = await apiPostForm('/test', {});

      expect(result.error).toBe('Error: Network error');
    });

    it('converts boolean values to strings', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiPostForm('/test', { active: true, disabled: false });

      const calledOptions = mockFetch.mock.calls[0][1];
      expect(calledOptions.body).toContain('active=true');
      expect(calledOptions.body).toContain('disabled=false');
    });

    it('converts number values to strings', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiPostForm('/test', { count: 42, price: 19.99 });

      const calledOptions = mockFetch.mock.calls[0][1];
      expect(calledOptions.body).toContain('count=42');
      expect(calledOptions.body).toContain('price=19.99');
    });

    it('handles empty data object', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiPostForm('/test', {});

      expect(mockFetch).toHaveBeenCalledWith(
        expect.any(String),
        expect.objectContaining({ body: '' })
      );
    });
  });

  // ===========================================================================
  // Edge Cases
  // ===========================================================================

  describe('Edge Cases', () => {
    it('handles special characters in URL', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiGet('/terms/search', { q: 'hello world' });

      const calledUrl = mockFetch.mock.calls[0][0];
      expect(calledUrl).toContain('q=hello+world');
    });

    it('handles Unicode in request body', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiPost('/terms', { text: '你好', translation: 'こんにちは' });

      expect(mockFetch).toHaveBeenCalledWith(
        expect.any(String),
        expect.objectContaining({
          body: JSON.stringify({ text: '你好', translation: 'こんにちは' })
        })
      );
    });

    it('handles very long response', async () => {
      const longData = { items: Array(1000).fill({ id: 1, text: 'test' }) };
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(JSON.stringify(longData))
      });

      const result = await apiGet('/test');

      expect(result.data).toEqual(longData);
    });

    it('handles concurrent requests', async () => {
      mockFetch
        .mockResolvedValueOnce({
          ok: true,
          text: () => Promise.resolve('{"id": 1}')
        })
        .mockResolvedValueOnce({
          ok: true,
          text: () => Promise.resolve('{"id": 2}')
        });

      const [result1, result2] = await Promise.all([
        apiGet('/terms/1'),
        apiGet('/terms/2')
      ]);

      expect(result1.data).toEqual({ id: 1 });
      expect(result2.data).toEqual({ id: 2 });
    });

    it('handles null values in response', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{"value": null}')
      });

      const result = await apiGet('/test');

      expect(result.data).toEqual({ value: null });
    });

    it('handles numeric string parameters', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('{}')
      });

      await apiGet('/test', { id: '123' });

      const calledUrl = mockFetch.mock.calls[0][0];
      expect(calledUrl).toContain('id=123');
    });

    it('handles HTTP 204 No Content', async () => {
      mockFetch.mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('')
      });

      const result = await apiDelete('/terms/1');

      expect(result.data).toEqual({});
      expect(result.error).toBeUndefined();
    });

    it('handles malformed JSON error response', async () => {
      mockFetch.mockResolvedValue({
        ok: false,
        status: 500,
        statusText: 'Internal Server Error',
        text: () => Promise.resolve('not json')
      });

      const result = await apiGet('/test');

      // Should fall back to generic error or handle raw response
      expect(result.error).toBeDefined();
    });
  });
  // ===========================================================================
  // Error message extraction (issue #284)
  // ===========================================================================

  describe('error message extraction', () => {
    it('reads the message from an "error" key', async () => {
      // Response::error() emits {error: "..."}, and every wrapper used to look
      // only at "message" — so a correctly-formed error arrived as the bare
      // status line and the real reason never reached the UI.
      mockFetch.mockResolvedValue({
        ok: false,
        status: 400,
        statusText: 'Bad Request',
        text: () => Promise.resolve('{"error": "Duplicate entry"}')
      });

      const result = await apiPost('/terms/quick', {});

      expect(result.error).toBe('Duplicate entry');
      expect(result.data).toBeUndefined();
    });

    it('reads the message from a handler payload promoted to 400', async () => {
      mockFetch.mockResolvedValue({
        ok: false,
        status: 400,
        statusText: 'Bad Request',
        text: () =>
          Promise.resolve('{"success": false, "error": "No entries found"}')
      });

      const result = await apiPost('/local-dictionaries/import-curated', {});

      expect(result.error).toBe('No entries found');
    });

    it('prefers "message" when "error" is a bare flag', async () => {
      mockFetch.mockResolvedValue({
        ok: false,
        status: 500,
        statusText: 'Internal Server Error',
        text: () => Promise.resolve('{"error": true, "message": "Boom"}')
      });

      const result = await apiGet('/test');

      expect(result.error).toBe('Boom');
    });

    it('falls back to the status line when neither key is a string', async () => {
      mockFetch.mockResolvedValue({
        ok: false,
        status: 403,
        statusText: 'Forbidden',
        text: () => Promise.resolve('{"error": {"nested": "shape"}}')
      });

      const result = await apiGet('/test');

      expect(result.error).toBe('HTTP 403: Forbidden');
    });

    it('applies to every verb', async () => {
      const body = '{"error": "Nope"}';
      for (const call of [
        () => apiGet('/t'),
        () => apiPost('/t', {}),
        () => apiPut('/t', {}),
        () => apiDelete('/t'),
        () => apiPostForm('/t', {})
      ]) {
        mockFetch.mockResolvedValue({
          ok: false,
          status: 400,
          statusText: 'Bad Request',
          text: () => Promise.resolve(body)
        });

        const result = await call();

        expect(result.error).toBe('Nope');
      }
    });
  });
});
