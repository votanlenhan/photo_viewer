// js/app.js

// Import PhotoSwipe using correct unpkg URLs for ES Modules
import PhotoSwipeLightbox from 'https://unpkg.com/photoswipe@5/dist/photoswipe-lightbox.esm.js';
import PhotoSwipe from 'https://unpkg.com/photoswipe@5/dist/photoswipe.esm.js';

// ========================================
// === STATE VARIABLES                 ===
// ========================================
let currentFolder = '';
let currentImageList = []; 
let allTopLevelDirs = [];
let searchAbortController = null; 
let photoswipeLightbox = null; 
let isLoadingMore = false; 
let currentPage = 1;
let totalImages = 0;
const imagesPerPage = 50; 
const initialLoadLimit = 5; // How many images to load super fast
const standardLoadLimit = 50; // How many images per page (including subsequent 'load more')
let zipDownloadTimerId = null; // ADDED: Timer ID for starting zip download
let currentZipJobToken = null; // Store the token for the active ZIP job
let zipPollingIntervalId = null; // Interval ID for polling ZIP status

// DOM Elements for the new sticky ZIP progress bar
let zipProgressBarContainerEl = null;
let zipFolderNameEl = null;
let zipOverallProgressEl = null;
let zipProgressStatsTextEl = null;
// let zipCancelBtnEl = null; // For future cancel functionality

// Session storage key for active ZIP job
const ACTIVE_ZIP_JOB_KEY = 'activeZipJob';

// MODAL HANDLING - generalModalOverlay declared as let
let generalModalOverlay = null; 

// ========================================
// === GLOBAL HELPER FUNCTIONS        ===
// ========================================

function getCurrentFolderInfo() {
    const path = currentFolder; // currentFolder is a global state variable
    const nameElement = document.getElementById('current-directory-name');
    // Ensure path is not empty before trying to split, provide a fallback name.
    const name = nameElement ? nameElement.textContent.replace('Album: ', '').trim() : (path ? path.split('/').pop() : 'Thư mục không xác định');
    return { path, name };
}

// ========================================
// === FUNCTION DECLARATIONS             ===
// ========================================

// --- MODAL HANDLING (Moved UP for earlier definition) ---
function showModalWithMessage(title, htmlContent, isError = false, isInfoOnly = false, showCancelButton = false, cancelCallback = null, okButtonText = 'Đóng') {
    if (!generalModalOverlay) { 
        console.error("CRITICAL: generalModalOverlay not initialized when showModalWithMessage was called. Modal cannot be shown.");
        alert(title + "\n" + htmlContent.replace(/<[^>]*>?/gm, '')); // Basic fallback
        return;
    }

    generalModalOverlay.innerHTML = `
        <div class="modal-box generic-message-box">
            <h3>${title}</h3>
            <div class="modal-content-area">${htmlContent}</div>
            <div class="prompt-actions">
                ${showCancelButton ? `<button id="modalCancelBtn" class="button" style="background:#6c757d;">Hủy</button>` : ''}
                <button id="modalOkBtn" class="button ${isError ? 'error-button-style' : ''}">${okButtonText}</button>
            </div>
        </div>
    `;

    generalModalOverlay.classList.add('modal-visible');
    if (!isInfoOnly) { 
        document.body.classList.add('body-blur');
    }

    const okBtn = document.getElementById('modalOkBtn');
    if (okBtn) {
        okBtn.onclick = () => hideModalWithMessage();
    }

    const cancelBtn = document.getElementById('modalCancelBtn');
    if (cancelBtn && cancelCallback) {
        cancelBtn.onclick = () => {
            hideModalWithMessage();
            if (typeof cancelCallback === 'function') cancelCallback();
        };
    } else if (cancelBtn) {
        cancelBtn.onclick = () => hideModalWithMessage();
    }
    
    document.addEventListener('keydown', escapeGeneralModalListener);
}

function hideModalWithMessage() {
    if (generalModalOverlay) {
        generalModalOverlay.classList.remove('modal-visible');
        generalModalOverlay.innerHTML = ''; 
    }
    document.body.classList.remove('body-blur');
    document.removeEventListener('keydown', escapeGeneralModalListener);
}

const escapeGeneralModalListener = (e) => {
    if (e.key === 'Escape') {
        hideModalWithMessage();
    }
};

// --- ZIP Job Management ---
function setActiveZipJob(jobToken, sourcePath, folderDisplayName) {
    currentZipJobToken = jobToken; // Keep for in-session use
    try {
        sessionStorage.setItem(ACTIVE_ZIP_JOB_KEY, JSON.stringify({ jobToken, sourcePath, folderDisplayName }));
    } catch (e) {
        console.warn("Could not save active ZIP job to sessionStorage", e);
    }
}

function getActiveZipJob() {
    try {
        const jobData = sessionStorage.getItem(ACTIVE_ZIP_JOB_KEY);
        return jobData ? JSON.parse(jobData) : null;
    } catch (e) {
        console.warn("Could not retrieve active ZIP job from sessionStorage", e);
        return null;
    }
}

function clearActiveZipJob() {
    currentZipJobToken = null;
    try {
        sessionStorage.removeItem(ACTIVE_ZIP_JOB_KEY);
    } catch (e) {
        console.warn("Could not clear active ZIP job from sessionStorage", e);
    }
    if (zipPollingIntervalId) {
        clearInterval(zipPollingIntervalId);
        zipPollingIntervalId = null;
    }
}

// --- ZIP Progress Bar UI Functions ---
function displayZipProgressBar(folderDisplayName, statusText = 'Đang khởi tạo...') {
    if (!zipProgressBarContainerEl) return;
    if (zipFolderNameEl) zipFolderNameEl.textContent = folderDisplayName || '';
    if (zipProgressStatsTextEl) zipProgressStatsTextEl.textContent = statusText;
    if (zipOverallProgressEl) zipOverallProgressEl.value = 0;
    zipProgressBarContainerEl.style.display = 'flex'; 
}

function updateZipProgressBar(jobData, folderDisplayNameFromJob) {
    if (!zipProgressBarContainerEl || !jobData) return;

    // Determine the folder name to display
    const activeJob = getActiveZipJob(); // Get potentially stored display name
    const folderName = folderDisplayNameFromJob || activeJob?.folderDisplayName || jobData.source_path?.split('/').pop() || 'Thư mục';
    
    if (zipFolderNameEl) zipFolderNameEl.textContent = folderName;

    let percent = 0;
    let statsText = 'Đang chờ...';

    if (jobData.status === 'processing') {
        if (jobData.total_files > 0) {
            percent = (jobData.processed_files / jobData.total_files) * 100;
        }
        statsText = `${jobData.processed_files}/${jobData.total_files} files (${percent.toFixed(0)}%)`;
    } else if (jobData.status === 'pending') {
        statsText = 'Đang chờ trong hàng đợi...';
    } else if (jobData.status === 'completed') {
        percent = 100;
        statsText = 'Hoàn thành!';
    } else if (jobData.status === 'failed') {
        percent = zipOverallProgressEl ? zipOverallProgressEl.value : 0; // Keep last known progress or 0
        statsText = 'Thất bại!';
        if (zipFolderNameEl) zipFolderNameEl.textContent = `Lỗi: ${folderName}`;
    }

    if (zipOverallProgressEl) zipOverallProgressEl.value = percent;
    if (zipProgressStatsTextEl) zipProgressStatsTextEl.textContent = statsText;

    if (zipProgressBarContainerEl.style.display !== 'flex') {
        zipProgressBarContainerEl.style.display = 'flex'; // Ensure it's visible if not already
    }
}

function hideZipProgressBar() {
    if (zipProgressBarContainerEl) {
        zipProgressBarContainerEl.style.display = 'none'; 
    }
}

// --- Helper fetchData: xử lý JSON, 401/password_required, HTTP lỗi ---
async function fetchData(url, options = {}) {
    try {
        const res = await fetch(url, options);
        if (res.status === 401) {
            const err = await res.json().catch(() => ({}));
            // Ensure folder property exists for password prompt
            return { status: 'password_required', folder: err.folder ?? null }; 
        }
        if (!res.ok) {
            const err = await res.json().catch(() => ({ message: res.statusText }));
            return { status: 'error', message: err.error || err.message };
        }
        // Assuming response is always JSON for simplicity now, adjust if text responses are needed
        const data = await res.json();
        return { status: 'success', data };
    } catch (e) {
        console.error("Fetch API Error:", e);
        return { status: 'error', message: e.message || 'Lỗi kết nối mạng.' };
    }
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

// --- Hiển thị/ẩn views chính ---
function showDirectoryView() {
    document.getElementById('directory-view').style.display = 'block';
    document.getElementById('image-view').style.display = 'none';
    document.getElementById('backButton').style.display = 'none';
    document.title = 'Thư viện Ảnh - Guustudio'; // Reset title
    if (location.hash) {
        history.pushState("", document.title, window.location.pathname + window.location.search);
    } // Clear hash when going to home
}
function showImageView() {
    document.getElementById('directory-view').style.display = 'none';
    document.getElementById('image-view').style.display = 'block';
    document.getElementById('backButton').style.display = 'inline-block';
    // Title update happens in loadSubItems
}

// --- Prompt mật khẩu cho folder protected ---
function showPasswordPrompt(folderName) {
    const overlay = document.getElementById('passwordPromptOverlay');
    overlay.innerHTML = `
            <div class="modal-box password-prompt-box">
        <h3>Nhập mật khẩu</h3>
        <p>Album "<strong>${folderName}</strong>" được bảo vệ. Vui lòng nhập mật khẩu:</p>
        <div id="promptError" class="error-message"></div>
        <input type="password" id="promptInput" placeholder="Mật khẩu..." autocomplete="new-password">
                <div class="prompt-actions">
          <button id="promptOk" class="button">Xác nhận</button>
          <button id="promptCancel" class="button" style="background:#6c757d;">Hủy</button>
                </div>
            </div>`;
    overlay.classList.add('modal-visible');
    document.body.classList.add('body-blur');
    const input = document.getElementById('promptInput');
    const ok    = document.getElementById('promptOk');
    const cancel= document.getElementById('promptCancel');
    const errEl = document.getElementById('promptError');
    input.focus();

    const handlePasswordSubmit = async () => {
        const pass = input.value; // Don't trim passwords
        if (!pass) { errEl.textContent = 'Mật khẩu không được để trống.'; input.focus(); return; }
        errEl.textContent = '';
        ok.disabled = cancel.disabled = true;
        ok.textContent = 'Đang xác thực...';
    
        const form = new FormData();
        form.append('action','authenticate');
        form.append('folder', folderName);
        form.append('password', pass);
        const result = await fetchData('api.php', { method:'POST', body: form });
    
        ok.disabled = cancel.disabled = false;
        ok.textContent = 'Xác nhận';
        if (result.status === 'success' && result.data.success === true) {
            hidePasswordPrompt();
            loadSubItems(folderName); // Reload content after success
        } else {
            // Error message might come from result.error (in case of 401) or result.message (general fetch error)
            errEl.textContent = result.data?.error || result.message || 'Mật khẩu không đúng hoặc có lỗi xảy ra.';
            input.select(); input.focus();
        }
    };

    ok.onclick = handlePasswordSubmit;
    input.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            handlePasswordSubmit();
        }
    });
    cancel.onclick = () => hidePasswordPrompt();
    // Add Escape key listener for password prompt
    document.addEventListener('keydown', escapePasswordPromptListener);
}
function hidePasswordPrompt() {
    const overlay = document.getElementById('passwordPromptOverlay');
    overlay.classList.remove('modal-visible');
    document.body.classList.remove('body-blur');
    overlay.innerHTML = '';
    // Remove the specific keydown listener when prompt is hidden
    document.removeEventListener('keydown', escapePasswordPromptListener);
}

// Listener needs to be a named function to be removable
const escapePasswordPromptListener = (e) => {
    if (e.key === 'Escape') {
        hidePasswordPrompt();
    }
};

// --- Render Top Level Directories --- 
function renderTopLevelDirectories(dirs, isSearchResult = false) {
    const listEl = document.getElementById('directory-list');
    const promptEl = document.getElementById('search-prompt');
    listEl.innerHTML = ''; 

    if (!listEl || !promptEl) {
        console.error("Directory list or search prompt element not found in renderTopLevelDirectories");
        return;
    }

    if (!dirs || dirs.length === 0) {
        if (isSearchResult) {
            promptEl.textContent = 'Không tìm thấy album nào khớp với tìm kiếm của bạn.';
        } else {
            promptEl.textContent = 'Không có album nào để hiển thị.'; // Initial load, no albums at all
        }
        promptEl.style.display = 'block';
        return;
    }

    // Update prompt based on context
    if (isSearchResult) {
        promptEl.textContent = `Đã tìm thấy ${dirs.length} album khớp:`;
        promptEl.style.display = 'block'; // Show result count
    } else {
        promptEl.textContent = 'Hiển thị một số album nổi bật. Sử dụng ô tìm kiếm để xem thêm.';
        promptEl.style.display = 'block'; // Show initial prompt
    }

    dirs.forEach(dir => {
        const li = document.createElement('li');
        const a  = document.createElement('a');
        a.href = `#?folder=${encodeURIComponent(dir.path)}`;
        a.dataset.dir = dir.path;
    
        const img = document.createElement('img');
        img.className = 'folder-thumbnail';
    
        // Construct URL to get_thumbnail API endpoint
        const thumbnailUrl = dir.thumbnail 
            ? `api.php?action=get_thumbnail&path=${encodeURIComponent(dir.thumbnail)}&size=150` 
            : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
        img.src = thumbnailUrl;
        img.alt = dir.name; // Use dir.name for alt text
        img.loading = 'lazy';
        img.onerror = () => { 
            console.error(`[RenderTopLevelThumb] Failed to load thumbnail for '${dir.name}' at src: ${img.src}`);
            img.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'; 
            img.alt = 'Lỗi thumbnail';
        };
    
        const span = document.createElement('span');
        span.textContent = dir.name; // Use dir.name for display text

        // Create a container for icon and text
        const contentWrapper = document.createElement('div');
        contentWrapper.className = 'directory-item-content'; // Add a class for styling

        // Always create the icon span for consistent spacing
        const iconSpan = document.createElement('span');
        iconSpan.className = 'lock-icon'; // Add base class always

        // ADD LOCK/UNLOCK ICON *BEFORE* the text span - ONLY IF protected
        if (dir.protected) {
            if (dir.authorized) {
                iconSpan.classList.add('unlocked');
                iconSpan.title = 'Đã mở khóa';
                iconSpan.innerHTML = '🔓'; // Or use an <i> tag if preferred
            } else {
                iconSpan.classList.add('locked');
                iconSpan.title = 'Yêu cầu mật khẩu';
                iconSpan.innerHTML = '🔒'; // Or use an <i> tag if preferred
            }
        } else {
            // Keep the span empty but present for spacing
            // Ensure it takes up space via CSS (min-width)
        }

        contentWrapper.appendChild(iconSpan); // Add icon span (potentially empty)
        contentWrapper.appendChild(span);     // Add text span after icon span

        a.append(img, contentWrapper); // Append image and the content wrapper

        // --- RE-ADD ONCLICK LOGIC --- 
        // MODIFY onClick based on protected and authorized status
        if (dir.protected && !dir.authorized) {
            // Protected and not authorized -> Show prompt
            a.onclick = e => { e.preventDefault(); showPasswordPrompt(dir.path); };
        } else {
            // Public or already authorized -> Navigate directly
            a.onclick = e => { e.preventDefault(); navigateToFolder(dir.path); }; // Use dir.path for navigation
        }
        // --- END RE-ADDED ONCLICK LOGIC ---

        li.appendChild(a);
        listEl.appendChild(li);
    });
}

// --- Render Image Grid Items --- 
function renderImageItems(imagesDataToRender, container) {
    imagesDataToRender.forEach((imgData) => {
        const div = document.createElement('div');
        div.className = 'image-item';
        const img = document.createElement('img');
        // Find the index in the *full* list based on the image name
        const imageIndex = currentImageList.findIndex(item => item.name === imgData.name);
        // const imageUrl = `images/${currentFolder}/${encodeURIComponent(imgData.name)}`; // Original image path - not used directly for display

        // Construct URL to fetch the thumbnail via API
        // Use imgData.path which is the source-prefixed path of the original image
        const thumbSrc = `api.php?action=get_thumbnail&path=${encodeURIComponent(imgData.path)}&size=750`; // Reverted to 750px per user request

        img.src = thumbSrc; 
        img.alt = imgData.name;
        img.loading = 'lazy';
        img.dataset.pswpIndex = imageIndex >= 0 ? imageIndex : undefined; // Store index
        img.onerror = () => { 
            console.warn(`[RenderGrid] Failed to load image/thumb: ${thumbSrc}`);
            // Optionally try the original image if thumb fails, or just show placeholder
            // img.src = imageUrl; // Uncomment to fallback to original if thumb fails (might be slow)
            img.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'; // Keep placeholder on error
            img.alt = 'Lỗi tải ảnh xem trước'; 
            // Keep div visible, maybe add an error icon/style?
        };

        img.onclick = () => {
            if (imageIndex >= 0) {
                openPhotoSwipe(imageIndex);
            } else {
                console.error("Could not find image index for:", imgData.name);
            }
        };

        div.appendChild(img);
        container.appendChild(div);
    });
}

// --- Initialize or Update PhotoSwipe --- 
function setupPhotoSwipe() {
    if (photoswipeLightbox) {
        photoswipeLightbox.destroy(); 
    }
    photoswipeLightbox = new PhotoSwipeLightbox({
        // dataSource now uses the full currentImageList which contains objects {name, width, height}
        dataSource: currentImageList.map(imgData => ({
            // Use the API endpoint to serve the original image
            src: `api.php?action=get_image&path=${encodeURIComponent(imgData.path)}`,
            // Use dimensions from API data if available (we'll add this to API later)
            width: imgData.width || 0, // Use fetched width, default to 0 if missing for now
            height: imgData.height || 0, // Use fetched height, default to 0 if missing for now
            alt: imgData.name
        })),
        pswpModule: PhotoSwipe,
        // Custom UI Elements
        appendToEl: document.body, // Default, but good to be explicit
    });
    photoswipeLightbox.init();
}

function openPhotoSwipe(index) {
    if (!photoswipeLightbox) {
        console.error("PhotoSwipe not initialized!");
        return;
    }
    // Ensure dataSource is up-to-date before opening
    // Map again from the potentially updated currentImageList
    photoswipeLightbox.options.dataSource = currentImageList.map(imgData => ({
        // Use the API endpoint here as well
        src: `api.php?action=get_image&path=${encodeURIComponent(imgData.path)}`,
        width: imgData.width || 0, // Use fetched width, default to 0 if missing for now
        height: imgData.height || 0, // Use fetched height, default to 0 if missing for now
        alt: imgData.name
    }));
    photoswipeLightbox.loadAndOpen(index);
}

// --- Load Sub Items (Folders/Images) - Modified for Two-Phase Loading ---
async function loadSubItems(folderPath) {
    currentFolder = folderPath;
    location.hash = `#?folder=${encodeURIComponent(folderPath)}`;
    showImageView();
    document.title = `Album: ${folderPath} - Guustudio`;

    // Reset state for new folder
    currentPage = 1; // Always start at page 1 conceptually
    totalImages = 0;
    currentImageList = []; // Holds ALL metadata fetched so far {name, width, height}
    isLoadingMore = false;
    const loadMoreContainer = document.getElementById('load-more-container');
    loadMoreContainer.style.display = 'none'; 
    document.getElementById('loadMoreBtn').onclick = loadMoreImages; // Ensure button is wired

    const titleEl = document.getElementById('current-directory-name');
    const gridEl = document.getElementById('image-grid');
    const zipLink = document.getElementById('download-all-link');
    const shareBtn = document.getElementById('shareButton');

    titleEl.textContent = `Album: ${folderPath.split('/').pop()}`;
    // Initial loading message
    gridEl.innerHTML = '<p class="loading-text">Đang tải ảnh xem trước...</p>'; 

    // --- Phase 1: Fetch initial small batch --- 
    console.log(`Phase 1: Fetching initial ${initialLoadLimit} images`);
    const initialResult = await fetchData(`api.php?action=list_files&dir=${encodeURIComponent(folderPath)}&page=1&limit=${initialLoadLimit}`);

    // Handle immediate errors or password prompt
    if (initialResult.status === 'password_required') {
        showPasswordPrompt(initialResult.folder || folderPath);
        gridEl.innerHTML = '<p class="error-text">Album này yêu cầu mật khẩu.</p>';
        return;
    }
    if (initialResult.status !== 'success') {
        gridEl.innerHTML = `<p class="error-text">Lỗi tải album: ${initialResult.message || 'Không rõ lỗi'}</p>`;
        return;
    }

    // Initial fetch successful, clear loading message
    gridEl.innerHTML = ''; 

    // Destructure the response: map 'files' key to 'initialImagesMetadata'
    // Get total image count from pagination data if available
    const { folders: subfolders, files: initialImagesMetadata, pagination } = initialResult.data;
    const fetchedTotal = pagination ? pagination.total_items : (initialImagesMetadata ? initialImagesMetadata.length : 0);

    totalImages = fetchedTotal || 0;
    let contentRendered = false;
    let imageGroupContainer = null; // To hold the image grid

    // Render subfolders (if any, only from first fetch)
    if (subfolders && subfolders.length) {
        const ul = document.createElement('ul');
        ul.className = 'directory-list-styling subfolder-list';
        subfolders.forEach(sf => {
            const li = document.createElement('li');
            const a = document.createElement('a');
            a.href = `#?folder=${encodeURIComponent(sf.path)}`;
            a.dataset.dir = sf.path;

            const img = document.createElement('img');
            img.className = 'folder-thumbnail';
            // Construct URL to get_thumbnail API endpoint
            const thumbnailUrl = sf.thumbnail 
                ? `api.php?action=get_thumbnail&path=${encodeURIComponent(sf.thumbnail)}&size=150` 
                : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
            img.src = thumbnailUrl;
            img.alt = sf.name; // Use sf.name for alt text
            img.loading = 'lazy';
            img.onerror = () => { 
                console.error(`[RenderSubfolderThumb] Failed to load thumbnail for '${sf.name}' at src: ${img.src}`);
                img.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'; 
                img.alt = 'Lỗi thumbnail'; 
            };

            const span = document.createElement('span');
            span.textContent = sf.name; // Use sf.name for display text
            // ADD LOCK/UNLOCK ICON based on protected and authorized status
            if (sf.protected) {
                if (sf.authorized) {
                    span.innerHTML += ' <span class="lock-icon unlocked" title="Đã mở khóa">🔓</span>';
                } else {
                    span.innerHTML += ' <span class="lock-icon locked" title="Yêu cầu mật khẩu">🔒</span>';
                }
            }

            a.append(img, span);
            // MODIFY onClick based on protected and authorized status
            if (sf.protected && !sf.authorized) {
                // Protected and not authorized -> Show prompt
                a.onclick = e => { e.preventDefault(); showPasswordPrompt(sf.path); };
            } else {
                // Public or already authorized -> Navigate directly
                a.onclick = e => { e.preventDefault(); navigateToFolder(sf.path); }; // Use sf.path for navigation
            }
            li.appendChild(a);
            ul.appendChild(li);
        });
        gridEl.appendChild(ul);
        contentRendered = true;
        if (initialImagesMetadata && initialImagesMetadata.length) {
            const hr = document.createElement('hr');
            hr.className = 'folder-image-divider';
            gridEl.appendChild(hr);
        }
    }

    // Render initial batch of images
    if (initialImagesMetadata && initialImagesMetadata.length) {
        currentImageList = initialImagesMetadata; // Start the full list
        imageGroupContainer = document.createElement('div');
        imageGroupContainer.className = 'image-group';
        renderImageItems(initialImagesMetadata, imageGroupContainer);
        gridEl.appendChild(imageGroupContainer);
        contentRendered = true;
        setupPhotoSwipe(); // Setup photoswipe with initial images
        console.log(`Phase 1: Rendered initial ${initialImagesMetadata.length} images.`);
    } else {
        currentImageList = [];
        // Create container even if empty initially, for phase 2
        imageGroupContainer = document.createElement('div'); 
        imageGroupContainer.className = 'image-group';
        gridEl.appendChild(imageGroupContainer);
    }
    
    // Update ZIP link and Share button (can do this after phase 1)
    zipLink.href = `api.php?action=download_zip&path=${encodeURIComponent(folderPath)}`;
    shareBtn.onclick = () => {
        const shareUrl = `${location.origin}${location.pathname}#?folder=${encodeURIComponent(folderPath)}`;
        navigator.clipboard.writeText(shareUrl).then(() => {
            const originalText = shareBtn.textContent;
            shareBtn.textContent = 'Đã sao chép!';
            shareBtn.disabled = true;
            setTimeout(() => {
                shareBtn.textContent = originalText;
                shareBtn.disabled = false;
            }, 2000);
        }).catch(err => {
            console.error('Không thể sao chép link:', err);
            alert('Lỗi: Không thể tự động sao chép link.');
        });
    };

    // Check if all images were loaded in phase 1 or if album is empty
    if (currentImageList.length >= totalImages) {
        console.log("All images loaded in Phase 1 or album empty.");
        if (!contentRendered) {
            gridEl.innerHTML = '<p class="info-text">Album này trống.</p>';
        }
        loadMoreContainer.style.display = 'none'; // No more to load
        return; // Finished
    }

    // --- Phase 2: Fetch the rest of the *first standard page* in the background --- 
    // We already have images 1 to initialLoadLimit. Fetch 1 to standardLoadLimit.
    // We only need to render images from index initialLoadLimit onwards from this result.
    const remainingNeededForFirstPage = standardLoadLimit - currentImageList.length;
    if (remainingNeededForFirstPage > 0) {
        // Add a subtle loading indicator for the rest
        const loadingRestEl = document.createElement('p');
        loadingRestEl.className = 'loading-text subtle-loading';
        loadingRestEl.textContent = 'Đang tải thêm...';
        gridEl.appendChild(loadingRestEl);
        
        console.log(`Phase 2: Fetching up to ${standardLoadLimit} images (page 1)`);
        const secondResult = await fetchData(`api.php?action=list_files&dir=${encodeURIComponent(folderPath)}&page=1&limit=${standardLoadLimit}`);
        
        // Remove subtle loading indicator regardless of result
        if(loadingRestEl) loadingRestEl.remove();
        
        if (secondResult.status === 'success' && secondResult.data.files) {
            const allFirstPageMetadata = secondResult.data.files;
            // Get the items that were NOT loaded in phase 1
            const newImagesToRender = allFirstPageMetadata.slice(initialLoadLimit);
            
            if (newImagesToRender.length > 0) {
                console.log(`Phase 2: Rendering remaining ${newImagesToRender.length} images for first page.`);
                // Combine full metadata for photoswipe
                currentImageList = allFirstPageMetadata.slice(0, standardLoadLimit); // Ensure currentImageList has up to standardLimit items
                renderImageItems(newImagesToRender, imageGroupContainer); // Render only the new ones
                setupPhotoSwipe(); // Update photoswipe with more items
            } else {
                console.log("Phase 2: No new images found in second fetch (already loaded or API issue?).");
                // Ensure currentImageList still reflects what was loaded in phase 1
                currentImageList = initialImagesMetadata; 
            }
            // Now check if more pages exist
            if (currentImageList.length < totalImages) {
                console.log("Load More button should be visible.");
                loadMoreContainer.style.display = 'block';
            } else {
                console.log("All images loaded after Phase 2. Hiding Load More.");
                loadMoreContainer.style.display = 'none';
            }
        } else {
            console.error("Phase 2: Error fetching remaining images:", secondResult.message);
            // Keep initial images displayed, but show error maybe?
            // Maybe hide load more button as we don't know total anymore?
            loadMoreContainer.style.display = 'none';
            const errorRestEl = document.createElement('p');
            errorRestEl.className = 'error-text';
            errorRestEl.textContent = `Lỗi tải phần còn lại: ${secondResult.message || 'Không rõ'}`;
            gridEl.appendChild(errorRestEl);
        }
    } else {
        // This case shouldn't happen if initialLoadLimit < standardLoadLimit and totalImages > initialLoadLimit
        console.log("Phase 2 skipped: Already loaded enough or more than standard limit initially?");
        if (currentImageList.length < totalImages) {
            loadMoreContainer.style.display = 'block';
        }
    }

    // Handle empty album case if nothing rendered in phase 1 or 2
    if (!contentRendered && currentImageList.length === 0) { 
        gridEl.innerHTML = '<p class="info-text">Album này trống.</p>';
    }
}

// --- Load More Images (Adjust pagination logic) ---
async function loadMoreImages() {
    // Calculate the next page number based on images already loaded and standard limit
    const nextConceptualPage = Math.floor(currentImageList.length / standardLoadLimit) + 1;
    
    if (isLoadingMore || currentImageList.length >= totalImages) {
        console.log("Load More: Already loading or no more images. Current:", currentImageList.length, "Total:", totalImages);
        return; 
    }

    isLoadingMore = true;
    // currentPage = nextConceptualPage; // Update state variable if needed elsewhere, but use nextConceptualPage for fetch
    const loadMoreBtn = document.getElementById('loadMoreBtn');
    const originalBtnText = loadMoreBtn.textContent;
    loadMoreBtn.textContent = 'Đang tải thêm...';
    loadMoreBtn.disabled = true;
    console.log(`Load More: Fetching page ${nextConceptualPage} (limit ${standardLoadLimit})`);

    try {
        const res = await fetchData(`api.php?action=list_files&dir=${encodeURIComponent(currentFolder)}&page=${nextConceptualPage}&limit=${standardLoadLimit}`);
        console.log(`Load More: Result for page ${nextConceptualPage}:`, res);
        // Check for 'files' key in the response data
        if (res.status === 'success' && res.data.files) {
            const newImagesMetadata = res.data.files;
            if (newImagesMetadata.length > 0) {
                currentImageList = currentImageList.concat(newImagesMetadata); 
                const gridContainer = document.querySelector('#image-grid .image-group');
                if (gridContainer) {
                    renderImageItems(newImagesMetadata, gridContainer); 
                }
                setupPhotoSwipe(); 
            } else {
                console.log("Load More: Received 0 images, likely reached the end.");
                // No need to decrement currentPage here
                // ADDED: Hide the button immediately when no new images are received
                document.getElementById('load-more-container').style.display = 'none';
            }
        } else {
            console.error("Error loading more images:", res.message);
            // Don't change currentPage on error
        }
    } catch (error) {
        console.error("Fetch error loading more images:", error);
        // Don't change currentPage on error
    }

    isLoadingMore = false;
    loadMoreBtn.textContent = originalBtnText;
    loadMoreBtn.disabled = false;

    // Hide button if all images are loaded *now*
    if (currentImageList.length >= totalImages) {
        console.log("Load More: All images loaded. Hiding button. Current:", currentImageList.length, "Total:", totalImages);
        document.getElementById('load-more-container').style.display = 'none';
    }
}

// --- Navigate Function (handles hash update) ---
function navigateToFolder(folderPath) {
    // location.hash will trigger loadSubItems via hashchange listener
    location.hash = `#?folder=${encodeURIComponent(folderPath)}`;
}

// --- Back button --- 
document.getElementById('backButton').onclick = () => {
    // Use hash change to navigate back
    history.back(); 
};

// --- Hash Handling ---
function handleUrlHash() {
    console.log("handleUrlHash: Hash changed to:", location.hash);
    const hash = location.hash;
    if (hash.startsWith('#?folder=')) {
        try {
            const encodedFolderName = hash.substring('#?folder='.length);
            const folderRelativePath = decodeURIComponent(encodedFolderName);

            if (folderRelativePath && !folderRelativePath.includes('..')) {
                console.log("handleUrlHash: Valid folder hash found, loading sub items for:", folderRelativePath);
                loadSubItems(folderRelativePath);
                return true; // Handled
            }
        } catch (e) { 
            console.error("Error parsing URL hash:", e); 
            history.replaceState(null, '', ' '); 
        }
    }
    
    // --- Handle returning to HOME view (Invalid or empty hash) --- 
    console.log("handleUrlHash: Invalid/empty hash, showing directory view.");
    showDirectoryView();
    
    const searchInputEl = document.getElementById('searchInput');
    const directoryListEl = document.getElementById('directory-list');
    const searchPromptEl = document.getElementById('search-prompt');
    
    // Clear previous state
    if (searchInputEl) searchInputEl.value = '';
    if (directoryListEl) directoryListEl.innerHTML = ''; // Clear list visually
    if (searchPromptEl) {
        searchPromptEl.textContent = 'Đang tải album...'; // Set loading prompt
        searchPromptEl.style.display = 'block';
    }
    
    // --- ADD THIS LINE --- 
    // Trigger initial directory load when returning to home
    loadTopLevelDirectories();
    // ---------------------
    
    return false; // Indicates the hash wasn't a valid folder hash
}

// --- Load Top Level Directories (Restored & Fixed) ---
async function loadTopLevelDirectories(searchTerm = null) { 
    const listEl = document.getElementById('directory-list');
    const promptEl = document.getElementById('search-prompt');
    const searchInput = document.getElementById('searchInput');
    const clearSearch = document.getElementById('clearSearch');
    const loadingIndicator = document.getElementById('loading-indicator'); // Added loading indicator

    if (!listEl || !promptEl || !searchInput || !clearSearch || !loadingIndicator) {
        console.error("Required elements not found for loadTopLevelDirectories");
        return;
    }

    loadingIndicator.style.display = 'block'; // Show loading indicator
    promptEl.style.display = 'none'; // Hide prompt while loading
    listEl.innerHTML = '<div class="loading-placeholder">Đang tải danh sách album...</div>'; // Initial placeholder

    const isSearching = searchTerm !== null && searchTerm !== '';

    // Update search input and clear button visibility
    searchInput.value = searchTerm || '';
    clearSearch.style.display = isSearching ? 'inline-block' : 'none';

    // Use the updated action 'list_files' for top-level listing
    let url = 'api.php?action=list_files'; // <-- CHANGED from list_dirs
    if (searchTerm) {
        // For list_files, the directory parameter is used for subdirs.
        // To list sources (top level), we don't need a search parameter here.
        // We will filter the result client-side if needed, or implement server-side filtering for sources later.
        // For now, always fetch all sources when searchTerm is present for top level.
        // Let's keep the search logic client-side for simplicity for now.
        // url += '&search=' + encodeURIComponent(searchTerm); // Remove server-side search for list_files root
    }
    
    const result = await fetchData(url);
    
    console.log("Fetch result (isSearching: " + isSearching + "):", result); // Debugging line

    loadingIndicator.style.display = 'none'; // Hide loading indicator
    listEl.innerHTML = ''; // Clear placeholder/previous content

    if (result.status === 'success') {
        // The response format for list_files root is different: { folders: [...], files: [], ... }
        // where folders contain the source info.
        let dirs = result.data.folders || []; // Get the source folders
        
        // Client-side filtering if a search term was provided
        if (isSearching) {
            dirs = dirs.filter(dir => 
                dir.name.toLowerCase().includes(searchTerm.toLowerCase())
            );
        }
        
        // If not searching, and we got more than 10, maybe shuffle and slice?
        // Or just display all sources returned.
        if (!isSearching && dirs.length > 10) {
            // Optional: Shuffle and slice if you want random display for non-search
            // shuffleArray(dirs); // Implement shuffleArray if needed
            // dirs = dirs.slice(0, 10);
        }

        renderTopLevelDirectories(dirs, isSearching);
        // Store the full list if needed for subsequent client-side searches without refetching
        // allTopLevelDirs = result.data.folders || []; 

    } else {
        console.error("Lỗi tải album:", result.message);
        listEl.innerHTML = `<div class="error-placeholder">Lỗi tải danh sách album: ${result.message}</div>`;
        promptEl.textContent = 'Đã xảy ra lỗi. Vui lòng thử lại.';
        promptEl.style.display = 'block';
    }
}

// --- Initialize App Function ---
function initializeApp() {
    console.log("Initializing app...");

    // DOM element references
    zipProgressBarContainerEl = document.getElementById('zip-progress-bar-container');
    zipFolderNameEl = document.getElementById('zip-folder-name-progress');
    zipOverallProgressEl = document.getElementById('zip-overall-progress');
    zipProgressStatsTextEl = document.getElementById('zip-progress-stats-text');
    
    // Assign generalModalOverlay ONCE after DOM is ready
    if (!generalModalOverlay) {
        generalModalOverlay = document.getElementById('passwordPromptOverlay');
    }
    if (!generalModalOverlay) { // Double check in case the ID is wrong or element is missing
        console.error("CRITICAL: passwordPromptOverlay element not found for generalModalOverlay!");
    }

    const searchInput = document.getElementById('searchInput');
    const directoryList = document.getElementById('directory-list');
    const searchPrompt = document.getElementById('search-prompt');

    // --- Event Listeners for #image-view actions (Consolidated) ---
    const imageView = document.getElementById('image-view');
    if (imageView) {
        const downloadAllLinkImageView = imageView.querySelector('#download-all-link');
        if (downloadAllLinkImageView) {
            downloadAllLinkImageView.addEventListener('click', async (event) => {
                event.preventDefault();
                const folderInfo = getCurrentFolderInfo(); 
                if (folderInfo && folderInfo.path) {
                    await handleDownloadZipAction(folderInfo.path, folderInfo.name);
                } else {
                    console.error("No current folder info for ZIP download from image view.");
                    showModalWithMessage("Lỗi tạo ZIP", "<p>Không tìm thấy thông tin thư mục hiện tại để tạo ZIP.</p>", true);
                }
            });
        }

        const shareButtonImageView = imageView.querySelector('#shareButton');
        if (shareButtonImageView) {
            shareButtonImageView.addEventListener('click', (event) => {
                event.preventDefault();
                const folderInfo = getCurrentFolderInfo();
                if (folderInfo && folderInfo.path) {
                    handleShareAction(folderInfo.path);
                } else {
                     console.error("No current folder info for share action from image view.");
                     showModalWithMessage("Lỗi chia sẻ", "<p>Không tìm thấy thông tin thư mục hiện tại để chia sẻ.</p>", true);
                }
            });
        }
    } else {
        console.warn("Image view container not found during initializeApp, listeners for download/share not attached.");
    }
    // --- End #image-view action listeners ---
    
    // Restore ZIP job polling if one was active - WRAPPED IN TRY-CATCH
    try {
        const activeJob = getActiveZipJob();
        if (activeJob && activeJob.jobToken) {
            console.log("Found active ZIP job on load:", activeJob);
            fetch(`api.php?action=get_zip_status&token=${activeJob.jobToken}`)
                .then(res => {
                    if (!res.ok) { 
                        console.error(`HTTP error! status: ${res.status} for job ${activeJob.jobToken}`);
                        clearActiveZipJob();
                        hideZipProgressBar(); 
                        throw new Error(`HTTP error! status: ${res.status}`);
                    }
                    return res.json();
                })
                .then(result => {
                    if (result.success && result.job_info) {
                        const status = result.job_info.status;
                        const displayName = activeJob.folderDisplayName || result.job_info.source_path?.split('/').pop();

                        if (status === 'pending' || status === 'processing') {
                            displayZipProgressBar(displayName, 'Đang xử lý...');
                            updateZipProgressBar(result.job_info, displayName);
                            pollZipStatus(activeJob.jobToken, displayName);
                        } else {
                            clearActiveZipJob(); 
                            if (status === 'completed' && result.job_info.zip_filename) {
                                 showModalWithMessage(
                                    `Tải ZIP sẵn sàng (phiên trước)`,
                                    `<p>File ZIP cho thư mục <strong>${displayName}</strong> đã được tạo ở phiên làm việc trước.</p><a href="api.php?action=download_final_zip&token=${activeJob.jobToken}" class="button download-all" download>Tải về</a>`,
                                    false,
                                    true 
                                );
                            } else if (status === 'failed') {
                                showModalWithMessage(
                                    `Lỗi tạo ZIP (phiên trước)`,
                                    `<p>Gặp lỗi khi tạo ZIP cho <strong>${displayName}</strong> ở phiên trước: ${result.job_info.error_message || 'Lỗi không xác định'}</p>`,
                                    true,
                                    true 
                                );
                            }
                            if (status !== 'completed' && status !== 'failed') {
                                hideZipProgressBar();
                            }
                        }
                    } else {
                         console.warn("Could not retrieve valid job info for active job token on load. Message:", result.message || "No job info provided by API.");
                         clearActiveZipJob(); 
                         hideZipProgressBar();
                    }
                })
                .catch(err => {
                    console.error("Error during fetch for active ZIP job on load:", err);
                    // Do not clear job here if it's a temporary network issue, 
                    // but do hide the bar if we can't confirm its status.
                    hideZipProgressBar(); 
                });
        } else {
            hideZipProgressBar(); 
        }
    } catch (e) {
        console.error("Error during initial ZIP job check wrapper:", e);
        hideZipProgressBar(); // Ensure bar is hidden on error
    }

    // --- Crucial Event Listeners and Initial State Handling --- 
    if (!handleUrlHash()) {
        console.log("initializeApp: handleUrlHash returned false, assuming it handled the initial view.");
    }
    window.addEventListener('hashchange', handleUrlHash);
    document.getElementById('backButton').onclick = () => {
        history.back();
    };

    // --- Search Logic Setup --- 
    if (searchInput) {
        const performSearch = debounce(async () => {
            const term = searchInput.value.trim();
            // const promptEl = document.getElementById('search-prompt'); 
            // const listEl = document.getElementById('directory-list');
            if (term.length > 0 && term.length < 2) {
                if (searchAbortController) { searchAbortController.abort(); }
                if(searchPrompt) searchPrompt.textContent = 'Nhập ít nhất 2 ký tự để tìm kiếm.';
                if(directoryList) directoryList.innerHTML = '';
                return;
            }
            loadTopLevelDirectories(term || null);
        }, 350);

        searchInput.addEventListener('input', performSearch);
        
        const clearSearchBtn = document.getElementById('clearSearch');
        if (clearSearchBtn) {
            clearSearchBtn.addEventListener('click', () => {
                searchInput.value = ''; 
                clearSearchBtn.style.display = 'none'; 
                performSearch(); 
            });
        }
    } else {
        console.warn("Search input element not found during initializeApp.");
    }
    console.log("initializeApp: Crucial listeners and initial hash handling are set up.");
}
// --- End Initialize App Function ---

document.addEventListener('DOMContentLoaded', () => {
    console.log("DOMContentLoaded event fired.");
    initializeApp(); 
    // Listeners previously here for #image-view download/share are now inside initializeApp for better scoping and timing.
}); 

// =======================================
// === ZIP DOWNLOAD FUNCTIONS (NEW)    ===
// =======================================

async function handleDownloadZipAction(folderPath, folderName) {
    if (!folderPath || !folderName) {
        console.error("Folder path or name missing for ZIP download.");
        showModalWithMessage('Lỗi yêu cầu ZIP', '<p>Đường dẫn hoặc tên thư mục bị thiếu.</p>', true);
        return;
    }

    // Clear any previous polling and hide old feedback before starting a new request
    if (zipPollingIntervalId) {
        clearInterval(zipPollingIntervalId);
        zipPollingIntervalId = null;
    }
    // currentZipJobToken will be overwritten by setActiveZipJob or remain null if request fails
    
    displayZipProgressBar(folderName, 'Đang gửi yêu cầu...');

    const formData = new FormData();
    formData.append('path', folderPath);

    const result = await fetchData('api.php?action=request_zip', {
        method: 'POST',
        body: formData
    });

    console.log("[ZIP] Request ZIP API response:", result);

    if (result.status === 'success' && result.data && result.data.job_token) {
        const jobToken = result.data.job_token;
        setActiveZipJob(jobToken, folderPath, folderName);

        // The API might return current status of an existing job or a new job
        if (result.data.status === 'completed') {
            updateZipProgressBar(result.data, folderName); // Show 100%
            clearActiveZipJob(); // Clear before showing modal
            showModalWithMessage(
                'Tải ZIP hoàn thành (có sẵn)',
                `<p>File ZIP cho thư mục <strong>${folderName}</strong> đã được tạo trước đó và sẵn sàng để tải.</p><a href="api.php?action=download_final_zip&token=${jobToken}" class="button download-all" download>Tải về ngay</a>`,
                false
            );
            setTimeout(hideZipProgressBar, 500);
        } else if (result.data.status === 'pending' || result.data.status === 'processing') {
            // Job is active, start polling. updateZipProgressBar will be called by poll.
            pollZipStatus(jobToken, folderName);
        } else { // Should be a new job, initial status typically 'pending' from server
             // updateZipProgressBar(result.data, folderName); // Update with initial data if provided
             pollZipStatus(jobToken, folderName); // Start polling, it will update the bar
        }
    } else {
        hideZipProgressBar();
        const errorMessage = result.message || result.data?.error || 'Không thể gửi yêu cầu tạo ZIP. Vui lòng thử lại.';
        showModalWithMessage('Lỗi yêu cầu ZIP', `<p>${errorMessage}</p>`, true);
        clearActiveZipJob(); // Ensure no stale job info
    }
}

async function pollZipStatus(jobToken, folderDisplayNameForUI) {
    if (!jobToken) {
        console.warn("[POLL] No jobToken provided for polling.");
        hideZipProgressBar();
        clearActiveZipJob();
        return;
    }

    if (zipPollingIntervalId) {
        clearInterval(zipPollingIntervalId);
        zipPollingIntervalId = null;
    }
    
    const activeJobCheck = getActiveZipJob();
    if (!activeJobCheck || activeJobCheck.jobToken !== jobToken) {
        console.log(`[POLL] Polling for job ${jobToken} with display name ${folderDisplayNameForUI}. Active job in session:`, activeJobCheck);
    }

    console.log(`[POLL] Starting polling for job token: ${jobToken}, Display: ${folderDisplayNameForUI}`);

    const fetchAndUpdate = async () => {
        const currentJobState = getActiveZipJob();
        if (!currentJobState || currentJobState.jobToken !== jobToken) {
            console.log(`[POLL] Job ${jobToken} is no longer the active job. Stopping poll.`);
            clearInterval(zipPollingIntervalId);
            zipPollingIntervalId = null;
            hideZipProgressBar(); 
            return;
        }

        const result = await fetchData(`api.php?action=get_zip_status&token=${jobToken}`);
        console.log(`[POLL] Status for ${jobToken} (line 1119):`, result);

        if (result.status === 'success' && result.data) {
            // Ưu tiên job_info nếu có, nếu không thì dùng data trực tiếp
            const jobInfo = result.data.job_info || result.data; 

            // Kiểm tra xem jobInfo có trường status không để xác nhận đây là dữ liệu job hợp lệ
            if (jobInfo && jobInfo.status) {
                updateZipProgressBar(jobInfo, folderDisplayNameForUI);

                if (jobInfo.status === 'completed') {
                    console.log(`[POLL] Job ${jobToken} completed.`);
                    clearActiveZipJob(); 
                    showModalWithMessage(
                        'Tạo ZIP hoàn thành!',
                        `<p>File ZIP cho thư mục <strong>${folderDisplayNameForUI}</strong> đã sẵn sàng.</p>
                         <p>Kích thước: ${(jobInfo.zip_filesize / (1024*1024)).toFixed(2)} MB</p>
                         <a href="api.php?action=download_final_zip&token=${jobToken}" class="button download-all-modal-button" download>Tải về ngay</a>`,
                        false, // isError
                        false, // isInfoOnly
                        false, // showCancelButton
                        null,  // cancelCallback
                        'Đóng' // okButtonText
                    );
                    setTimeout(hideZipProgressBar, 500); 
                } else if (jobInfo.status === 'failed') {
                    console.log(`[POLL] Job ${jobToken} failed. Error: ${jobInfo.error_message}`);
                    clearActiveZipJob();
                    showModalWithMessage(
                        'Tạo ZIP thất bại',
                        `<p>Đã có lỗi xảy ra khi tạo ZIP cho thư mục <strong>${folderDisplayNameForUI}</strong>.</p><p><em>${jobInfo.error_message || 'Không có thông tin lỗi cụ thể.'}</em></p>`,
                        true
                    );
                    setTimeout(hideZipProgressBar, 3000);
                }
                // If pending or processing, polling continues via setInterval
            } else if (result.data.not_found) { 
                console.warn(`[POLL] Job ${jobToken} not found by API (case: result.data.not_found).`);
                clearActiveZipJob();
                hideZipProgressBar();
                showModalWithMessage('Lỗi theo dõi ZIP', `<p>Không tìm thấy thông tin cho yêu cầu tạo ZIP (có thể đã hết hạn hoặc bị hủy).</p>`, true);
            } else {
                // jobInfo không hợp lệ (thiếu status) và cũng không phải not_found
                console.error("[POLL] Valid job data (with status) or job_info not found in successful API response (line 1154-else). Data:", result.data);
            }
        } else if (result.status === 'error') { 
             console.error("[POLL] API returned an error for get_zip_status:", result.message);
             // Consider stopping poll on certain types of errors
             // clearActiveZipJob();
             // hideZipProgressBar();
        } else {
            // Các trường hợp khác không mong muốn (ví dụ result.status không phải 'success' cũng không phải 'error')
            console.error("[POLL] Unexpected response structure polling ZIP status (line 1154-outer-else):", result);
        }
    };

    fetchAndUpdate(); 
    zipPollingIntervalId = setInterval(fetchAndUpdate, 2000); 
}

// --- Share Action ---
function handleShareAction(folderPath) {
    if (!folderPath) return;
    const shareUrl = `${location.origin}${location.pathname}#?folder=${encodeURIComponent(folderPath)}`;
    const shareButton = document.getElementById('shareButton') || document.getElementById('shareActionMenu'); // Get either button
    
    navigator.clipboard.writeText(shareUrl).then(() => {
        if (shareButton) {
            const originalText = shareButton.dataset.originalText || 'Sao chép Link'; // Store original text if not already
            if (!shareButton.dataset.originalText) shareButton.dataset.originalText = originalText;
            shareButton.textContent = 'Đã sao chép!';
            shareButton.disabled = true;
            setTimeout(() => {
                shareButton.textContent = originalText;
                shareButton.disabled = false;
            }, 2000);
        } else {
            alert('Đã sao chép link!'); // Fallback alert
        }
    }).catch(err => {
        console.error('Không thể sao chép link:', err);
        alert('Lỗi: Không thể tự động sao chép link.');
    });

    // Hide menu if open
    const menu = document.getElementById('more-actions-menu');
    if (menu) menu.classList.remove('menu-visible');
}

// --- Password Prompt Specific Functions ---
// ... (showPasswordPrompt, hidePasswordPrompt, escapePasswordPromptListener) ...
  