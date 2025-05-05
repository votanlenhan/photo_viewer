document.addEventListener('DOMContentLoaded', () => {
    // --- DOM Elements ---
    const folderListBody = document.getElementById('folder-list-body');
    const adminSearchInput = document.getElementById('adminSearchInput');
    const adminMessageDiv = document.getElementById('admin-message');
    const adminFeedbackDiv = document.getElementById('admin-feedback');
    const adminLoadingDiv = document.getElementById('admin-loading');

    // --- Configuration ---
    const API_URL = 'api.php'; // API endpoint haha

    // --- Global state for polling --- 
    const activePollers = {}; // Store interval IDs: { "folder/path": intervalId }
    const POLLING_INTERVAL_MS = 10000; // Increased: Check every 10 seconds

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

    // --- Function to Update Button State --- 
    function updateCacheButtonState(button, folderPath, jobStatus, lastCachedAt) {
        let buttonText = '';
        let buttonTitle = '';
        let isDisabled = false;
        let icon = ''; // Optional icon

        if (jobStatus === 'processing') {
            buttonText = 'Đang xử lý...';
            buttonTitle = 'Cache ảnh lớn đang được xử lý trong nền.';
            isDisabled = true;
            icon = '⚙️';
        } else if (jobStatus === 'pending') {
            buttonText = 'Đang chờ xử lý';
            buttonTitle = 'Yêu cầu cache ảnh lớn đang chờ xử lý trong nền.';
            isDisabled = true;
            icon = '🕒';
        } else { // Job is null (completed, failed, or never run)
            if (lastCachedAt) {
                buttonText = 'Đã cache ảnh lớn';
                buttonTitle = 'Cache ảnh lớn đã tạo/kiểm tra lúc: ' + new Date(lastCachedAt * 1000).toLocaleString() + '. Click để yêu cầu tạo/kiểm tra lại trong nền.';
                isDisabled = false;
                icon = '✅';
            } else {
                buttonText = 'Tạo Cache Ảnh Lớn';
                buttonTitle = 'Yêu cầu tạo cache thumbnail kích thước lớn cho thư mục này trong nền.';
                isDisabled = false;
                icon = '➕'; // Or maybe a warning if previous attempt failed?
            }
        }
        
        button.innerHTML = `${icon} ${buttonText}`.trim(); // Add icon
        button.title = buttonTitle;
        button.disabled = isDisabled;
    }
    
    // --- Function to Poll Cache Status --- 
    async function pollCacheStatus(button, folderPath) {
        console.log(`[Polling ${folderPath}] Checking status...`);
        try {
            const apiUrl = `api.php?action=get_folder_cache_status&path=${encodeURIComponent(folderPath)}`;
            const response = await fetch(apiUrl);
            const result = await response.json();

            if (!response.ok || result.success !== true) {
                 console.warn(`[Polling ${folderPath}] Failed to get status:`, result.error || `HTTP ${response.status}`);
                 // Optional: Stop polling on error? Or just keep trying?
                 // stopPolling(folderPath); 
                 return; // Keep previous button state on error
            }
            
            const { job_status, last_cached_at } = result;
            console.log(`[Polling ${folderPath}] Status: job=${job_status}, cached_at=${last_cached_at}`);

            // Update button based on fetched status
            updateCacheButtonState(button, folderPath, job_status, last_cached_at);

            // Stop polling if the job is no longer pending or processing
            if (job_status !== 'pending' && job_status !== 'processing') {
                stopPolling(folderPath);
            }

        } catch (error) {
             console.error(`[Polling ${folderPath}] Error:`, error);
             // Optional: Stop polling on network error?
             // stopPolling(folderPath);
        }
    }

    // --- Function to Start Polling --- 
    function startPolling(button, folderPath) {
        // Clear existing poller for this path, if any
        stopPolling(folderPath);
        
        console.log(`[Polling ${folderPath}] Starting poller.`);
        // Initial immediate check
        pollCacheStatus(button, folderPath); 
        
        // Start interval
        activePollers[folderPath] = setInterval(() => {
            pollCacheStatus(button, folderPath);
        }, POLLING_INTERVAL_MS);
    }

    // --- Function to Stop Polling --- 
    function stopPolling(folderPath) {
        if (activePollers[folderPath]) {
            console.log(`[Polling ${folderPath}] Stopping poller.`);
            clearInterval(activePollers[folderPath]);
            delete activePollers[folderPath];
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
            
            // *** INITIAL BUTTON STATE *** 
            updateCacheButtonState(cacheButton, folder.path, folder.current_cache_job_status, folder.last_cached_fully_at);
            
            // *** CHECK IF WE NEED TO START POLLING (e.g., on page load if job was already running) ***
            if (folder.current_cache_job_status === 'pending' || folder.current_cache_job_status === 'processing') {
                 // If poller isn't already running for this path, start it
                 if (!activePollers[folder.path]) {
                     startPolling(cacheButton, folder.path);
                 }
            } else {
                 // Ensure any old poller for this path is stopped if job is now complete/null
                 stopPolling(folder.path);
            }
            
            // *** ASSIGN ONCLICK HANDLER ***
            cacheButton.onclick = () => {
                // Use innerText which includes the icon now
                console.log(`Cache button clicked for: ${folder.path}. Initial state: ${cacheButton.innerText}`); 
                handleCacheFolder(cacheButton, folder.path);
            };

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
        console.log(`handleCacheFolder called for path: ${folderPath}`);

        // Check if already polling (button should be disabled, but as extra check)
        if (activePollers[folderPath]) {
            console.warn(`[Cache Request ${folderPath}] Ignoring click, already polling/processing.`);
            return;
        }
        
        if (!confirm(`Bạn có chắc muốn yêu cầu tạo/cập nhật cache cho thư mục "${folderPath}"? Quá trình này sẽ chạy trong nền.`)) {
            console.log('Cache request cancelled by user.');
            return;
        }
        
        const originalButtonHTML = button.innerHTML; // Store original HTML (with icon)
        const originalButtonTitle = button.title;
        
        button.disabled = true;
        button.innerHTML = `⏳ Đang yêu cầu...`; // Temp requesting state

        try {
            const formData = new FormData();
            formData.append('action', 'admin_cache_folder');
            formData.append('path', folderPath);

            const response = await fetch('api.php', { method: 'POST', body: formData });
            const result = await response.json();
            console.log('API response received:', result);

            if (!response.ok || result.success !== true) {
                throw new Error(result.error || result.message || `Lỗi HTTP ${response.status}`);
            }

            // SUCCESS: Job queued or already running
            showFeedback(result.message || 'Yêu cầu đã được xử lý.', result.status === 'queued' ? 'success' : 'warning');
            
            // *** START POLLING *** 
            // API already told us the current status ('queued' or 'already_queued')
            // We can update the button state immediately and start polling
            const initialJobStatus = (result.status === 'queued') ? 'pending' : (activePollers[folderPath] ? activePollers[folderPath].status : 'pending'); // Assume pending if already queued
            updateCacheButtonState(button, folderPath, initialJobStatus, null); // Update state, lastCachedAt unknown yet
            startPolling(button, folderPath); // Start the polling process

        } catch (error) {
            // FAILURE: API call failed
            hideLoading(); 
            console.error("Error requesting cache job:", error);
            showFeedback(`Lỗi gửi yêu cầu cache: ${error.message}`, "error");
            
            // Restore original button state on failure
             button.innerHTML = originalButtonHTML;
             button.title = originalButtonTitle;
             button.disabled = false; // Re-enable
             stopPolling(folderPath); // Ensure no poller is running after failure
        }
    }

    // --- Search Input Listener --- 
    let refreshIntervalId = null; // Biến lưu ID của interval
    const REFRESH_INTERVAL_MS = 15000; // 15 giây

    function startAutoRefresh() {
        // Xóa interval cũ nếu có
        if (refreshIntervalId) {
            clearInterval(refreshIntervalId);
        }
        // Bắt đầu interval mới
        refreshIntervalId = setInterval(() => {
            // Chỉ refresh nếu người dùng không đang gõ tìm kiếm
            // (debounce sẽ xử lý refresh khi ngừng gõ)
             if (document.activeElement !== adminSearchInput) {
                console.log('Auto-refreshing folder list...');
                fetchAndRenderFolders(adminSearchInput.value.trim());
             }
        }, REFRESH_INTERVAL_MS);
         console.log(`Auto-refresh started with interval ID: ${refreshIntervalId}`);
    }

    function stopAutoRefresh() {
         if (refreshIntervalId) {
            console.log(`Stopping auto-refresh interval ID: ${refreshIntervalId}`);
            clearInterval(refreshIntervalId);
            refreshIntervalId = null;
        }
    }

    if (adminSearchInput) {
        const debouncedSearch = debounce(() => {
            console.log('Debounced search triggering fetch...');
            stopAutoRefresh(); // Dừng refresh khi bắt đầu tìm kiếm
            fetchAndRenderFolders(adminSearchInput.value.trim()).finally(() => {
                 // Khởi động lại refresh sau khi tìm kiếm hoàn tất (hoặc sau debounce timeout)
                 // Đảm bảo không start lại nếu đang gõ liên tục
                 startAutoRefresh(); 
            });
        }, 500); // Tăng debounce lên 500ms

        adminSearchInput.addEventListener('input', () => {
             stopAutoRefresh(); // Dừng refresh ngay khi bắt đầu gõ
             debouncedSearch(); // Kích hoạt debounce
        });
        
         // Xử lý trường hợp xóa sạch ô tìm kiếm
         adminSearchInput.addEventListener('search', () => {
              if(adminSearchInput.value === '') {
                   stopAutoRefresh();
                   fetchAndRenderFolders('').finally(startAutoRefresh);
              }
         });

    } else {
        console.error("Admin search input not found!");
    }

    // --- Initial Load and Start Refresh ---
    fetchAndRenderFolders()
    .finally(() => {
         startAutoRefresh(); // Bắt đầu tự động refresh sau khi tải lần đầu
    });

}); // End DOMContentLoaded