import { API_BASE_URL } from './config.js';

/**
 * Fetches data from the API.
 * @param {string} action - The API action to call (e.g., 'list_files').
 * @param {object} params - An object of query parameters to append to the URL.
 * @param {object} options - Fetch options (method, headers, body, signal, etc.).
 * @returns {Promise<object>} - The response from the API, structured as { status: 'success'/'password_required'/'error', data/folder/message }.
 */
export async function fetchDataApi(action, params = {}, fetchOptions = {}) {
    let url = `${API_BASE_URL}?action=${action}`;
    if (params) {
        const queryParams = new URLSearchParams(params);
        const queryString = queryParams.toString();
        if (queryString) {
            url += `&${queryString}`;
        }
    }

    try {
        const res = await fetch(url, fetchOptions);
        if (res.status === 401) {
            const err = await res.json().catch(() => ({}));
            return { status: 'password_required', folder: err.folder ?? null }; 
        }
        if (!res.ok) {
            const err = await res.json().catch(() => ({ message: res.statusText }));
            return { status: 'error', message: err.error || err.message };
        }
        const data = await res.json().catch(() => {
            // Handle cases where response is OK but not valid JSON (e.g. get_image, get_thumbnail returning actual image data)
            // This specific fetchDataApi is designed for JSON API endpoints.
            // For direct file downloads/display, use fetch() directly or a different utility.
            return { status: 'error', message: 'Phản hồi không phải là JSON hợp lệ.' };
        });
        // If data itself has an error status from the previous catch, propagate it.
        if (data.status === 'error') return data; 
        
        return { status: 'success', data };
    } catch (e) {
        console.error(`Fetch API Error for action ${action}:`, e);
        // Check if it's an AbortError
        if (e.name === 'AbortError') {
            return { status: 'error', message: 'Yêu cầu đã bị hủy.', isAbortError: true };
        }
        return { status: 'error', message: e.message || 'Lỗi kết nối mạng.' };
    }
}

// Example of more specific API call functions (can be added later if needed)
/*
export async function listFiles(path, page, limit, signal) {
    return fetchDataApi('list_files', { path, page, limit }, { signal });
}

export async function authenticate(folder, password) {
    const formData = new FormData();
    formData.append('folder', folder);
    formData.append('password', password);
    return fetchDataApi('authenticate', {}, { method: 'POST', body: formData });
}
*/ 