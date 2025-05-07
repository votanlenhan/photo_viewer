import { fetchDataApi } from '../apiService';
import { API_BASE_URL } from '../config'; // Import để kiểm tra URL

// Mock global fetch function
global.fetch = jest.fn();

describe('apiService', () => {

  beforeEach(() => {
    // Reset mock trước mỗi test
    fetch.mockClear();
  });

  test('fetchDataApi should call fetch with correct URL (no params)', async () => {
    fetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({ some: 'data' }),
    });

    await fetchDataApi('test_action');

    expect(fetch).toHaveBeenCalledTimes(1);
    expect(fetch).toHaveBeenCalledWith(`${API_BASE_URL}?action=test_action`, {});
  });

  test('fetchDataApi should call fetch with correct URL and params', async () => {
    fetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({ some: 'data' }),
    });

    const params = { folder: 'main/folder1', page: 2 };
    await fetchDataApi('list_files', params);

    expect(fetch).toHaveBeenCalledTimes(1);
    const expectedUrl = `${API_BASE_URL}?action=list_files&folder=main%2Ffolder1&page=2`;
    expect(fetch).toHaveBeenCalledWith(expectedUrl, {});
  });

  test('fetchDataApi should handle successful response', async () => {
    const mockData = { files: [1, 2, 3], total: 10 };
    fetch.mockResolvedValueOnce({
      ok: true,
      json: async () => mockData,
    });

    const result = await fetchDataApi('list_files');

    expect(result).toEqual({ status: 'success', data: mockData });
  });

  test('fetchDataApi should handle 401 password required', async () => {
    const mockError = { folder: 'main/locked' };
    fetch.mockResolvedValueOnce({
      ok: false, // status is 401, so ok is false
      status: 401,
      json: async () => mockError,
    });

    const result = await fetchDataApi('list_files', { folder: 'main/locked'});

    expect(result).toEqual({ status: 'password_required', folder: 'main/locked' });
  });

  test('fetchDataApi should handle other non-ok response with JSON error', async () => {
     const mockError = { error: 'Invalid parameters' };
    fetch.mockResolvedValueOnce({
      ok: false,
      status: 400,
      json: async () => mockError,
    });

    const result = await fetchDataApi('invalid_action');

    expect(result).toEqual({ status: 'error', message: 'Invalid parameters' });
  });

  test('fetchDataApi should handle non-ok response without JSON error', async () => {
    fetch.mockResolvedValueOnce({
      ok: false,
      status: 500,
      statusText: 'Internal Server Error',
      json: async () => { throw new Error('Not JSON') }, // Giả lập lỗi parse JSON
    });

    const result = await fetchDataApi('server_error_action');

    expect(result).toEqual({ status: 'error', message: 'Internal Server Error' });
  });
  
   test('fetchDataApi should handle network error', async () => {
    const networkError = new Error('Network failed');
    fetch.mockRejectedValueOnce(networkError);

    const result = await fetchDataApi('any_action');

    expect(result).toEqual({ status: 'error', message: 'Network failed' });
  });

  test('fetchDataApi should handle AbortError', async () => {
    const abortError = new DOMException('The user aborted a request.', 'AbortError');
    fetch.mockRejectedValueOnce(abortError);

    const result = await fetchDataApi('abortable_action');

    expect(result).toEqual({ status: 'error', message: 'Yêu cầu đã bị hủy.', isAbortError: true });
  });

   test('fetchDataApi should pass fetch options', async () => {
    fetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({}),
    });
    const options = { method: 'POST', headers: { 'Content-Type': 'application/json'}, body: JSON.stringify({ key: 'value' }) };
    await fetchDataApi('post_action', {}, options);

    expect(fetch).toHaveBeenCalledWith(`${API_BASE_URL}?action=post_action`, options);
  });

}); 