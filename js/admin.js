// js/admin.js

document.addEventListener('DOMContentLoaded', () => {
    // --- DOM Elements ---
    const folderListBody = document.getElementById('folder-list-body');
    const adminSearchInput = document.getElementById('adminSearchInput');
    const adminMessageDiv = document.getElementById('admin-message');

    // --- Configuration ---
    const API_URL = 'api.php'; // API endpoint

    // --- Utility Functions ---
    function escapeHTML(str) {
        if (typeof str !== 'string') return str;
        const p = document.createElement('p');
        p.appendChild(document.createTextNode(str));
        return p.innerHTML;
    }

    function showMessage(text, type = 'success') {
        if (!adminMessageDiv) return;
        adminMessageDiv.textContent = text;
        adminMessageDiv.className = `message ${type}`;
        adminMessageDiv.style.display = 'block';
        // Automatically hide after some time
        setTimeout(() => { adminMessageDiv.style.display = 'none'; }, 4000);
    }

    // --- Debounce helper ---
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    };

    // --- API Call Helper ---
    async function adminApiCall(action, options = {}) {
        // Add action to URL query parameters, works for GET/POST if api.php uses $_REQUEST
        let url = `${API_URL}?action=${encodeURIComponent(action)}`;
        try {
            const response = await fetch(url, options);
            const data = await response.json(); // Assume admin API always returns JSON
            if (!response.ok) {
                // Throw error using message from server if available
                throw new Error(data.error || `Lỗi HTTP ${response.status}`);
            }
            return data; // Return successful data {success: true/false, message: '...', folders: [...]}
        } catch (error) {
            console.error(`Admin API Call Error (${action}):`, error);
            showMessage(`Lỗi API: ${error.message}`, 'error');
            return null; // Indicate failure
        }
    }

    // --- Fetch and Render Folders ---
    async function fetchAndRenderFolders(searchTerm = '') {
        if (!folderListBody) return;
        folderListBody.innerHTML = '<tr><td colspan="6">Đang tải dữ liệu...</td></tr>';

        let apiUrl = 'api.php?action=admin_list_folders';
        if (searchTerm) {
            apiUrl += `&search=${encodeURIComponent(searchTerm)}`;
        }

        try {
            const response = await fetch(apiUrl);
            if (!response.ok) {
                throw new Error(`Lỗi HTTP ${response.status}`);
            }
            const result = await response.json();

            if (result.error) {
                throw new Error(result.error);
            }

            renderFolderTable(result.folders);

        } catch (error) {
            console.error("Lỗi tải danh sách thư mục:", error);
            folderListBody.innerHTML = `<tr><td colspan="6" style="color: red;">Lỗi tải dữ liệu: ${error.message}</td></tr>`;
            showMessage(`Lỗi tải danh sách: ${error.message}`, 'error');
        }
    }

    // --- Render Table Rows ---
    function renderFolderTable(folders) {
        if (!folderListBody) return;
        folderListBody.innerHTML = ''; // Clear existing rows or loading message

        if (!folders || folders.length === 0) {
            folderListBody.innerHTML = '<tr><td colspan="6">Không tìm thấy thư mục nào.</td></tr>';
            return;
        }

        folders.forEach(folder => {
            const row = document.createElement('tr');
            const isProtected = folder.protected;
            const statusClass = isProtected ? 'status-protected' : 'status-unprotected';
            const statusText = isProtected ? 'Được bảo vệ' : 'Công khai';
            
            // Generate Share URL (relative path for now, needs base URL)
            // Construct URL based on current location - robust way
            const baseUrl = `${window.location.protocol}//${window.location.host}${window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'))}/`; // Get base path
            const shareUrl = `${baseUrl}#?folder=${encodeURIComponent(folder.name)}`;

            row.innerHTML = `
                <td>${escapeHTML(folder.name)}</td>
                <td class="${statusClass}">${statusText}</td>
                <td>${folder.views || 0}</td>
                <td>${folder.downloads || 0}</td>
                <td><input type="text" value="${escapeHTML(shareUrl)}" readonly title="Click để sao chép" class="share-link-input"></td>
                <td>
                    <form class="action-form" data-folder="${escapeHTML(folder.name)}">
                        <input type="password" name="password" placeholder="Mật khẩu mới..." autocomplete="new-password">
                        <button type="submit" class="button set-button" title="Đặt hoặc cập nhật mật khẩu">Lưu</button>
                        ${isProtected ? '<button type="button" class="button remove-button" title="Xóa mật khẩu">Xóa MK</button>' : ''}
                    </form>
                </td>
            `;

            // Add event listeners for this row
            const form = row.querySelector('.action-form');
            const removeButton = row.querySelector('.remove-button');
            const shareInput = row.querySelector('.share-link-input');

            form.addEventListener('submit', handlePasswordSubmit);
            if (removeButton) {
                removeButton.addEventListener('click', handleRemovePassword);
            }
            if (shareInput) {
                 shareInput.addEventListener('click', handleShareLinkClick);
            }

            folderListBody.appendChild(row);
        });
    }
    
    // --- Handle Share Link Click ---
    function handleShareLinkClick(event) {
        const input = event.target;
        input.select();
        try {
            navigator.clipboard.writeText(input.value).then(() => {
                 showMessage(`Đã sao chép link cho thư mục: ${input.closest('tr').querySelector('td').textContent}`);
            }).catch(err => {
                console.error('Lỗi sao chép link:', err);
                showMessage('Lỗi: Không thể tự động sao chép.', 'error');
            });
        } catch (err) {
            console.error('Lỗi clipboard API:', err);
            showMessage('Lỗi: Trình duyệt không hỗ trợ sao chép tự động.', 'error');
        }
    }

    // --- Handle Password Form Submission (Set/Update) ---
    async function handlePasswordSubmit(event) {
        event.preventDefault();
        const form = event.target;
        const folderName = form.dataset.folder;
        const passwordInput = form.querySelector('input[name="password"]');
        const password = passwordInput.value;
        const submitButton = form.querySelector('button[type="submit"]');

        if (!password) {
            showMessage('Vui lòng nhập mật khẩu mới.', 'error');
            passwordInput.focus();
            return;
        }

        const originalButtonText = submitButton.textContent;
        submitButton.textContent = 'Đang lưu...';
        submitButton.disabled = true;

        const formData = new FormData();
        formData.append('action', 'admin_set_password');
        formData.append('folder', folderName);
        formData.append('password', password);

        try {
            const response = await fetch('api.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (!response.ok || result.error) {
                throw new Error(result.error || `Lỗi HTTP ${response.status}`);
            }

            showMessage(result.message || 'Đặt mật khẩu thành công!', 'success');
            passwordInput.value = ''; // Clear input
            // Reload the list to show updated status
            fetchAndRenderFolders(adminSearchInput.value.trim()); 

        } catch (error) {
            console.error("Lỗi đặt mật khẩu:", error);
            showMessage(`Lỗi: ${error.message}`, 'error');
        }

        submitButton.textContent = originalButtonText;
        submitButton.disabled = false;
    }

    // --- Handle Remove Password Click ---
    async function handleRemovePassword(event) {
        const button = event.target;
        const form = button.closest('.action-form');
        const folderName = form.dataset.folder;

        if (!confirm(`Bạn có chắc muốn xóa mật khẩu cho thư mục "${folderName}"?`)) {
            return;
        }
        
        button.textContent = 'Đang xóa...';
        button.disabled = true;

        const formData = new FormData();
        formData.append('action', 'admin_remove_password');
        formData.append('folder', folderName);

        try {
            const response = await fetch('api.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (!response.ok || result.error) {
                throw new Error(result.error || `Lỗi HTTP ${response.status}`);
            }

            showMessage(result.message || 'Xóa mật khẩu thành công!', 'success');
             // Reload the list to show updated status
             fetchAndRenderFolders(adminSearchInput.value.trim());

        } catch (error) {
            console.error("Lỗi xóa mật khẩu:", error);
            showMessage(`Lỗi: ${error.message}`, 'error');
            // Re-enable button on error
             button.textContent = 'Xóa MK';
             button.disabled = false;
        }
        // Button state is handled by the reload
    }

    // --- Initial Load ---
    fetchAndRenderFolders();

    // --- Search Input Listener --- 
    if (adminSearchInput) {
        const debouncedSearch = debounce(() => {
            fetchAndRenderFolders(adminSearchInput.value.trim());
        }, 300); // 300ms delay
        adminSearchInput.addEventListener('input', debouncedSearch);
    } else {
        console.error("Admin search input not found!");
    }

}); // End DOMContentLoaded