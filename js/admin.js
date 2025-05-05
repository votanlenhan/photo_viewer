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
                 
                 // Đổi tên biến để rõ ràng hơn, đây là cache cho ảnh lớn
                 const lastLargeCacheTime = folder.last_cached_fully_at;
                 
                 if (lastLargeCacheTime) { 
                    buttonText = 'Đã cache ảnh lớn';
                    buttonTitle = 'Cache ảnh lớn đã tạo/kiểm tra lúc: ' + new Date(lastLargeCacheTime * 1000).toLocaleString() + '. Click để yêu cầu tạo/kiểm tra lại trong nền.';
                    // Nút vẫn enable để cho phép kiểm tra lại
                 } else {
                    buttonText = 'Tạo Cache Ảnh Lớn';
                    buttonTitle = 'Yêu cầu tạo cache thumbnail kích thước lớn (để xem ảnh nhanh hơn) cho thư mục này trong nền.';
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

        // Xác nhận trước khi gửi yêu cầu
        if (!confirm(`Bạn có chắc muốn yêu cầu tạo/cập nhật cache cho thư mục "${folderPath}"? Quá trình này sẽ chạy trong nền.`)) {
            console.log('Cache request cancelled by user.');
            return;
        }
        
        // Lưu trạng thái nút gốc để có thể khôi phục nếu API call ban đầu thất bại
        const originalButtonText = button.textContent;
        const originalButtonTitle = button.title;
        
        // Cập nhật giao diện ngay lập tức để phản hồi
        button.disabled = true;
        button.textContent = 'Đang yêu cầu...';
        // Không dùng showLoading toàn cục nữa vì API trả về nhanh
        // showLoading('Đang gửi yêu cầu cache...'); 

        try {
            const formData = new FormData();
            formData.append('action', 'admin_cache_folder');
            formData.append('path', folderPath);

            // Sử dụng fetch trực tiếp vì fetchData có thể không phù hợp với cấu trúc response mới hoàn toàn
            const response = await fetch('api.php', { method: 'POST', body: formData });
            const result = await response.json();
            console.log('API response received:', result); // LOG 4 (result is the direct JSON data)

            // Ẩn loading nếu có
             hideLoading(); 

            if (!response.ok || result.success !== true) {
                // Lỗi từ API (bao gồm cả lỗi validation, server error khi enqueue)
                throw new Error(result.error || result.message || `Lỗi HTTP ${response.status}`);
            }

            // Xử lý phản hồi thành công từ API (đã đưa vào hàng đợi hoặc đã có sẵn)
            let feedbackType = 'info'; // Mặc định là info cho trạng thái chờ
            let finalButtonText = 'Đang chờ xử lý';
            let finalButtonTitle = 'Yêu cầu tạo cache đang chờ xử lý trong nền.';

            if (result.status === 'already_queued') {
                 feedbackType = 'warning';
                 finalButtonText = 'Đang xử lý/chờ'; // Trạng thái nút nếu đã có job
                 finalButtonTitle = 'Yêu cầu cache cho thư mục này đã có trong hàng đợi hoặc đang xử lý.';
            } else if (result.status === 'queued') {
                 feedbackType = 'success'; // Thành công đưa vào hàng đợi
                 // Giữ nguyên finalButtonText và finalButtonTitle là 'Đang chờ xử lý'
            }

            // Hiển thị thông báo từ API
            showFeedback(result.message || 'Yêu cầu đã được gửi.', feedbackType);

            // Cập nhật nút với trạng thái mới (thường là 'Đang chờ xử lý')
            // Nút vẫn bị disable để tránh gửi yêu cầu liên tục
            button.textContent = finalButtonText;
            button.title = finalButtonTitle;
            button.disabled = true; // Giữ nút disable
            console.log(`Button state set to: ${finalButtonText}`);

            // LƯU Ý QUAN TRỌNG:
            // Trạng thái nút sẽ chỉ cập nhật thành "Đã cache" khi:
            // 1. Worker chạy nền hoàn thành công việc.
            // 2. Dữ liệu được load lại (fetchAndRenderFolders) và API `admin_list_folders`
            //    trả về giá trị `last_cached_fully_at` mới nhất cho thư mục này.
            // Do đó, không cần cập nhật nút về trạng thái "Đã cache" ngay tại đây.
            // Cân nhắc: Có thể reload lại danh sách sau một khoảng thời gian ngắn 
            // để cập nhật trạng thái nút nếu worker xử lý nhanh?
            // setTimeout(() => fetchAndRenderFolders(adminSearchInput.value.trim()), 5000); // Ví dụ: reload sau 5s


        } catch (error) {
            // Lỗi xảy ra khi gọi API ban đầu (fetch thất bại, JSON parse lỗi, hoặc API trả về lỗi)
            hideLoading(); 
            console.error("Error requesting cache job:", error);
            showFeedback(`Lỗi gửi yêu cầu cache: ${error.message}`, "error");
            
            // Khôi phục trạng thái nút về ban đầu nếu yêu cầu API thất bại
             button.textContent = originalButtonText;
             button.title = originalButtonTitle;
             button.disabled = false; // Cho phép thử lại
        }
        // Không có finally block vì button state được quản lý trong try/catch
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
    fetchAndRenderFolders().finally(() => {
         startAutoRefresh(); // Bắt đầu tự động refresh sau khi tải lần đầu
    });

}); // End DOMContentLoaded