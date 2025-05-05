document.addEventListener('DOMContentLoaded', () => {
    // --- DOM Elements ---
    const folderListBody = document.getElementById('folder-list-body');
    const adminSearchInput = document.getElementById('adminSearchInput');
    const adminMessageDiv = document.getElementById('admin-message');
    const adminFeedbackDiv = document.getElementById('admin-feedback');
    const adminLoadingDiv = document.getElementById('admin-loading');

    // --- Configuration ---
    const API_URL = 'api.php'; // API endpoint haha

    // --- Utility Functions ---
    function escapeHTML(str) {
        if (typeof str !== 'string') return str;
        const p = document.createElement('p');
        p.appendChild(document.createTextNode(str));
        return p.innerHTML;
    }

    function showLoading(message = 'Đang tải...') {
        if (adminLoadingDiv) {
            adminLoadingDiv.textContent = message;
            adminLoadingDiv.style.display = 'block';
        }
        if (adminFeedbackDiv) {
            adminFeedbackDiv.style.display = 'none';
        }
    }

    function hideLoading() {
        if (adminLoadingDiv) {
            adminLoadingDiv.style.display = 'none';
        }
    }

    function showFeedback(message, type = 'success') {
        if (adminFeedbackDiv) {
            adminFeedbackDiv.textContent = message;
            adminFeedbackDiv.className = `feedback-message feedback-${type}`;
            adminFeedbackDiv.style.display = 'block';
        }
        hideLoading();
        setTimeout(() => {
            if (adminFeedbackDiv) adminFeedbackDiv.style.display = 'none';
        }, 5000);
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
    async function fetchData(url, options = {}) {
        try {
            const res = await fetch(url, options);
            // Check for specific admin-related errors first if needed, e.g., 403 Forbidden
            if (!res.ok) {
                const errData = await res.json().catch(() => ({ error: res.statusText }));
                // Prioritize error message from JSON payload if available
                throw new Error(errData.error || `Lỗi HTTP ${res.status}`);
            }
            // Assume successful responses are JSON for admin actions
            const data = await res.json(); 
            return { status: 'success', data }; // Mimic structure used in handleCacheFolder
        } catch (e) {
            console.error("Fetch API Error (admin):", e);
            // Return an error structure consistent with what the calling code expects
            return { status: 'error', message: e.message || 'Lỗi kết nối mạng.' }; 
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
            // +++ LOG DỮ LIỆU FOLDER NHẬN ĐƯỢC +++
            console.log('Rendering row for:', folder);
            // +++ KẾT THÚC LOG +++

            const isProtected = folder.protected;
            const statusClass = isProtected ? 'status-protected' : 'status-unprotected';
            const statusText = isProtected ? 'Được bảo vệ' : 'Công khai';
            
            // Generate Share URL using the source-prefixed path (folder.path)
            const baseUrl = `${window.location.protocol}//${window.location.host}${window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'))}/`; // Get base path
            const shareUrl = `${baseUrl}#?folder=${encodeURIComponent(folder.path)}`; // Use folder.path

            row.innerHTML = `
                <td>${escapeHTML(folder.name)}</td>
                <td class="${statusClass}">${statusText}</td>
                <td>${folder.views || 0}</td>
                <td>${folder.downloads || 0}</td>
                <td><input type="text" value="${escapeHTML(shareUrl)}" readonly title="Click để sao chép" class="share-link-input"></td>
                <td>
                    <form class="action-form" data-folder="${escapeHTML(folder.path)}">
                        <input type="password" name="password" placeholder="Mật khẩu mới..." autocomplete="new-password">
                        <button type="submit" class="button set-button" title="Đặt hoặc cập nhật mật khẩu">Lưu</button>
                        ${isProtected ? '<button type="button" class="button remove-button" title="Xóa mật khẩu">Xóa MK</button>' : ''}
                    </form>
                </td>
                <td>
                    <button class="button button-small cache-folder-btn" title="Tạo cache thumbnail cho thư mục này">Tạo Cache</button>
                </td>
            `;

            // Add event listeners for this row
            const form = row.querySelector('.action-form');
            const removeButton = row.querySelector('.remove-button');
            const shareInput = row.querySelector('.share-link-input');
            const cacheButton = row.querySelector('.cache-folder-btn');

            form.addEventListener('submit', handlePasswordSubmit);
            if (removeButton) {
                removeButton.addEventListener('click', handleRemovePassword);
            }
            if (shareInput) {
                 shareInput.addEventListener('click', handleShareLinkClick);
            }
            
            // +++ KIỂM TRA TRẠNG THÁI CACHE BAN ĐẦU VÀ TẠO NÚT +++
            if (cacheButton) {
                 const folderPath = form.dataset.folder; 
                 let buttonText = '';
                 let buttonTitle = '';
                 let initiallyDisabled = false;
                 
                 if (folder.last_cached_fully_at) { 
                    buttonText = 'Đã cache (Kiểm tra lại)';
                    buttonTitle = 'Cache đã tạo lúc: ' + new Date(folder.last_cached_fully_at * 1000).toLocaleString() + '. Click để kiểm tra/cập nhật lại.';
                    // Nút vẫn enable để cho phép kiểm tra lại
                 } else {
                    buttonText = 'Kiểm Tra Cache';
                    buttonTitle = 'Kiểm tra và tạo cache thumbnail nếu cần.';
                 }
                 
                 cacheButton.textContent = buttonText;
                 cacheButton.title = buttonTitle;
                 cacheButton.disabled = initiallyDisabled; // Thường là false
                 
                 // Luôn gán sự kiện onclick
                 cacheButton.onclick = () => {
                     console.log(`Cache button clicked for: ${folderPath}. Initial state: ${buttonText}`); 
                     handleCacheFolder(cacheButton, folderPath);
                 };
            }
            // +++ KẾT THÚC TẠO NÚT +++

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

    // --- Event Listeners ---
    /* TẠM THỜI BỎ EVENT DELEGATION
    folderListBody.addEventListener('click', (event) => {
        const target = event.target;
        console.log('Folder list clicked. Target:', target); // LOG 1: Kiểm tra sự kiện click

        if (target.classList.contains('cache-folder-btn')) {
            console.log('Cache button clicked.'); // LOG 2: Xác nhận đúng nút được click
            const row = target.closest('tr');
            const folderPath = row?.dataset.folderPath;
            if (folderPath) {
                handleCacheFolder(target, folderPath);
            }
        }
    });
    */

    // --- Action Handlers ---
    async function handleCacheFolder(button, folderPath) {
        console.log(`handleCacheFolder called for path: ${folderPath}`); // LOG 3

        // Điều chỉnh confirm message tùy trạng thái ban đầu?
        const isCheckingAgain = button.textContent.startsWith('Đã cache');
        const confirmMessage = isCheckingAgain 
            ? `Bạn có chắc muốn kiểm tra/cập nhật lại cache cho thư mục "${folderPath}"?`
            : `Bạn có chắc muốn tạo/cập nhật cache thumbnail cho thư mục "${folderPath}"? Quá trình này có thể mất vài phút.`;

        if (!confirm(confirmMessage)) {
            console.log('Cache process cancelled by user.');
            return;
        }
        
        const loadingText = isCheckingAgain ? 'Đang kiểm tra lại...' : 'Đang tạo cache...';
        showLoading(loadingText);
        const originalButtonText = isCheckingAgain ? 'Đã cache (Kiểm tra lại)' : 'Kiểm Tra Cache'; // Lưu lại text đúng
        const originalButtonTitle = button.title; // Lưu title gốc
        button.disabled = true;
        button.textContent = loadingText;

        try {
            const formData = new FormData();
            formData.append('action', 'admin_cache_folder');
            formData.append('path', folderPath);

            const response = await fetchData('api.php', { method: 'POST', body: formData });
            console.log('API response received:', response); // LOG 4

            let finalButtonText = 'Kiểm Tra Cache'; // Default state, assume check needed or failed
            let finalButtonTitle = 'Kiểm tra và tạo cache thumbnail nếu cần.'; // Default title
            let feedbackType = 'error'; // Default feedback type
            let feedbackMessage = 'Có lỗi xảy ra khi xử lý cache.'; // Default feedback message

            if (response.status === 'success' && response.data?.success === true) {
                const data = response.data;
                console.log(`Checking conditions: errors=${data.errors}, created=${data.thumbnails_created}`); // LOG 5

                if (data.errors === 0) {
                    // Cache process successful (no errors)
                    finalButtonText = 'Đã cache (Kiểm tra lại)';
                    feedbackType = 'success';
                    // Use server timestamp if available, otherwise indicate it's just cached
                    const newTimestamp = data.updated_timestamp; // Rely on server providing this
                    if (newTimestamp) {
                         finalButtonTitle = 'Cache đã tạo/xác nhận lúc: ' + new Date(newTimestamp * 1000).toLocaleString() + '. Click để kiểm tra/cập nhật lại.';
                    } else {
                         finalButtonTitle = 'Cache đã được tạo/cập nhật. Click để kiểm tra/cập nhật lại.';
                         console.warn("Server did not return 'updated_timestamp' on successful cache.");
                    }

                    // Tailor success message based on creation count
                    if (data.thumbnails_created > 0) {
                        feedbackMessage = `Cache cho '${folderPath}' hoàn tất: Đã tạo ${data.thumbnails_created} thumbnail mới.`;
                    } else {
                        feedbackMessage = `Cache cho '${folderPath}' đã được xác nhận là mới nhất.`;
                    }
                     // Append skipped/error count for more detail
                     feedbackMessage += ` (${data.thumbnails_skipped} bỏ qua).`;

                } else {
                    // Errors occurred during thumbnail creation
                    feedbackType = 'error';
                     // Keep button text as 'Kiểm Tra Cache' to encourage retry
                     feedbackMessage = `Lỗi tạo cache cho '${folderPath}': Có ${data.errors} lỗi xảy ra khi tạo thumbnail. Chi tiết xem log server. (${data.thumbnails_created} tạo, ${data.thumbnails_skipped} bỏ qua).`;
                     finalButtonText = 'Lỗi Cache (Thử lại)'; // More indicative text
                     finalButtonTitle = 'Đã xảy ra lỗi khi tạo cache. Click để thử lại.';
                }

            } else {
                // API call failed or reported success: false
                const errorMsg = response.data?.error || response.message || 'Lỗi không xác định từ API.';
                feedbackMessage = `Lỗi xử lý cache cho '${folderPath}': ${errorMsg}`;
                feedbackType = 'error';
                finalButtonText = 'Lỗi API (Thử lại)'; // Indicate API level failure
                finalButtonTitle = 'Lỗi giao tiếp với máy chủ. Click để thử lại.';
            }

            showFeedback(feedbackMessage, feedbackType);
            // Update button based on the final determined state
            button.textContent = finalButtonText;
            button.title = finalButtonTitle;

        } catch (error) {
            // This catch block handles errors *outside* the fetchData promise (e.g., programming errors here)
            // fetchData itself handles API/network errors and returns a structured error response.
            console.error("Critical Error during handleCacheFolder logic:", error);
            // Use showFeedback for consistency, indicating a critical internal error
            showFeedback(`Lỗi client nghiêm trọng khi xử lý cache cho '${folderPath}': ${error.message}`, "error");
             // Revert to a known state in case of unexpected errors in this block
             button.textContent = originalButtonText;
             button.title = originalButtonTitle;
        } finally {
            // Always re-enable the button after processing
            hideLoading();
            button.disabled = false;
            console.log('Finally block executed. Button enabled.');
        }
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