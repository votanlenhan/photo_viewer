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
let zipResetFeedbackTimerId = null; // ADDED: Timer ID for auto-hiding zip feedback

// ========================================
// === FUNCTION DECLARATIONS             ===
// ========================================

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
            <div class="password-prompt-box">
        <h3>Nhập mật khẩu</h3>
        <p>Album "<strong>${folderName}</strong>" được bảo vệ. Vui lòng nhập mật khẩu:</p>
        <div id="promptError" class="error-message"></div>
        <input type="password" id="promptInput" placeholder="Mật khẩu..." autocomplete="new-password">
                <div class="prompt-actions">
          <button id="promptOk" class="button">Xác nhận</button>
          <button id="promptCancel" class="button" style="background:#6c757d;">Hủy</button>
                </div>
            </div>`;
    overlay.style.display = 'flex';
    document.body.classList.add('body-blur'); // ADDED: Add blur class
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
    const escapeListener = (e) => {
        if (e.key === 'Escape') {
            hidePasswordPrompt();
            document.removeEventListener('keydown', escapeListener); // Clean up listener
        }
    };
    document.addEventListener('keydown', escapeListener);

  }
  function hidePasswordPrompt() {
    const overlay = document.getElementById('passwordPromptOverlay');
    overlay.style.display = 'none';
    document.body.classList.remove('body-blur'); // ADDED: Remove blur class
    overlay.innerHTML = '';
  }
  
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
        // ADD LOCK/UNLOCK ICON based on protected and authorized status
        if (dir.protected) {
            if (dir.authorized) {
                span.innerHTML += ' <span class="lock-icon unlocked" title="Đã mở khóa">🔓</span>';
            } else {
                span.innerHTML += ' <span class="lock-icon locked" title="Yêu cầu mật khẩu">🔒</span>';
            }
        }
    
            a.append(img, span);
        // MODIFY onClick based on protected and authorized status
        if (dir.protected && !dir.authorized) {
            // Protected and not authorized -> Show prompt
            a.onclick = e => { e.preventDefault(); showPasswordPrompt(dir.path); };
        } else {
             // Public or already authorized -> Navigate directly
             a.onclick = e => { e.preventDefault(); navigateToFolder(dir.path); }; // Use dir.path for navigation
        }
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
    zipLink.href = `api.php?action=download_zip&dir=${encodeURIComponent(folderPath)}`;
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
    } else {
         document.getElementById('load-more-container').style.display = 'block'; // Ensure it's visible if more exist
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

  document.addEventListener('DOMContentLoaded', () => {
    console.log("DOMContentLoaded event fired.");

    // Get elements needed for search and initial view setup
    const searchInput = document.getElementById('searchInput');
    const directoryList = document.getElementById('directory-list');
    const searchPrompt = document.getElementById('search-prompt');
    console.log("Search elements:", { searchInput, directoryList, searchPrompt });

    // --- Initialize App Function ---
    function initializeApp() {
        console.log("initializeApp: Starting.");
        // Initial setup: check hash first
        if (!handleUrlHash()) {
            console.log("initializeApp: No valid hash found.");
            // If no valid hash, show the initial directory view state
            showDirectoryView();
            if (searchPrompt) {
                searchPrompt.textContent = 'Đang tải album...';
                searchPrompt.style.display = 'block';
                console.log("initializeApp: Set prompt to loading.");
            }
            if (directoryList) {
                 directoryList.innerHTML = '';
                 console.log("initializeApp: Cleared directory list.");
            }
            console.log("initializeApp: Attempting to call loadTopLevelDirectories...");
            loadTopLevelDirectories(); // Initial load
            console.log("initializeApp: Called loadTopLevelDirectories (async). Proceeding...");
        }

        // Listen for hash changes to navigate
    window.addEventListener('hashchange', handleUrlHash);

        // Back button listener
        document.getElementById('backButton').onclick = () => {
            history.back();
        };

        // --- Search Logic Setup (Inside initializeApp) ---
        const performSearch = debounce(async () => {
            const term = searchInput.value.trim();
            const promptEl = document.getElementById('search-prompt'); // Define promptEl here
            const listEl = document.getElementById('directory-list'); // Define listEl here
            // Call loadTopLevelDirectories for searching
            // Handle term length < 2 locally before calling API
            if (term.length > 0 && term.length < 2) {
                 if (searchAbortController) { searchAbortController.abort(); }
                 if(promptEl) promptEl.textContent = 'Nhập ít nhất 2 ký tự để tìm kiếm.';
                 if(listEl) listEl.innerHTML = '';
                 // allTopLevelDirs = []; // Clear results if needed
                 return;
            }
            // Call with term (null if empty, triggering initial load behavior)
             loadTopLevelDirectories(term || null);
        }, 350);

        if (searchInput) {
            searchInput.addEventListener('input', performSearch);
            // Clear search button logic
            const clearSearchBtn = document.getElementById('clearSearch');
            if (clearSearchBtn) {
                clearSearchBtn.addEventListener('click', () => {
                    searchInput.value = ''; // Clear the input
                    clearSearchBtn.style.display = 'none'; // Hide the button
                    performSearch(); // Trigger search with empty term (reload initial list)
                });
            }
            console.log("initializeApp: Search listener attached.");
        } else {
            console.error("Search input element not found! Cannot attach listener.");
        }
        // --- End Search Logic Setup ---

        console.log("initializeApp: Finished.");
    }
    // --- End Initialize App Function ---

    // Start the app
    initializeApp(); // Call initialization first

    // --- MODIFIED: Event Listener Setup for Header Actions (Share/Download/More) ---
    const imageView = document.getElementById('image-view');
    const moreActionsButton = document.getElementById('more-actions-button');
    const moreActionsMenu = document.getElementById('more-actions-menu');
    const shareActionMenuButton = document.getElementById('shareActionMenu');
    const downloadActionMenuButton = document.getElementById('downloadActionMenu');
    // Zip overlay and cancel button (already declared in previous step, ensure they are accessible here)
    const overlay = document.getElementById('zip-progress-overlay'); 
    const cancelButton = document.getElementById('cancel-zip-button'); 

    // Function to get current folder info (used by multiple handlers)
    function getCurrentFolderInfo() {
        const path = currentFolder; // Assume global 'currentFolder' holds the path
        const nameElement = document.getElementById('current-directory-name');
        const name = nameElement ? nameElement.textContent.replace('Album: ', '').trim() : path.split('/').pop(); // Get name from header or path
        return { path, name };
    }

    // Delegated listener for original desktop buttons
    if (imageView) {
        imageView.addEventListener('click', (event) => {
            const target = event.target;
            const folderInfo = getCurrentFolderInfo();

            if (target && target.id === 'shareButton') {
                event.preventDefault();
                handleShareAction(folderInfo.path);
            }
             // Note: Download link is an <a> tag, not a button
            else if (target && target.id === 'download-all-link') {
                event.preventDefault(); 
                handleDownloadZipAction(folderInfo.path, folderInfo.name);
            }
        });
    } else {
        console.error("Image view container not found for attaching action listeners.");
    }

    // Listener for "More" button (mobile)
    if (moreActionsButton) {
        moreActionsButton.addEventListener('click', (event) => {
            event.stopPropagation(); // Prevent click from immediately closing menu via document listener
            if (moreActionsMenu) {
                moreActionsMenu.classList.toggle('menu-visible');
            }
        });
    } else {
        console.error("More actions button not found.");
    }

    // Listeners for menu items (mobile)
    if (shareActionMenuButton) {
        shareActionMenuButton.addEventListener('click', () => {
             const folderInfo = getCurrentFolderInfo();
             handleShareAction(folderInfo.path);
             // handleShareAction already hides menu
        });
    }
    if (downloadActionMenuButton) {
         downloadActionMenuButton.addEventListener('click', () => {
             const folderInfo = getCurrentFolderInfo();
             handleDownloadZipAction(folderInfo.path, folderInfo.name);
              // handleDownloadZipAction already hides menu
         });
    }

    // Listener to close menu when clicking outside
    document.addEventListener('click', (event) => {
        if (moreActionsMenu && moreActionsMenu.classList.contains('menu-visible')) {
            // Check if the click was outside the menu AND outside the button that opens it
            if (!moreActionsMenu.contains(event.target) && !moreActionsButton.contains(event.target)) {
                moreActionsMenu.classList.remove('menu-visible');
            }
        }
    });

    // --- Existing Zip Cancel Listeners (Keep them) ---
    if (cancelButton) {
        cancelButton.addEventListener('click', () => {
            console.log("ZIP download preparation cancelled by user.");
            hideZipFeedback();
        });
    } else {
         console.error("Cancel ZIP button not found.");
    }
    if (overlay) {
        document.addEventListener('keydown', (e) => {
            if (overlay.style.display !== 'none' && e.key === 'Escape') {
                console.log("ZIP download preparation cancelled via Escape key.");
                hideZipFeedback();
                 // Also hide the actions menu if Escape is pressed while zip overlay is shown
                 if (moreActionsMenu) moreActionsMenu.classList.remove('menu-visible');
            }
            // Also close actions menu on Escape even if zip overlay isn't shown
            else if (moreActionsMenu && moreActionsMenu.classList.contains('menu-visible') && e.key === 'Escape'){
                 moreActionsMenu.classList.remove('menu-visible');
            }
        });
    }
    // --- End Listener Setup ---

}); // End DOMContentLoaded

// =======================================
// === ZIP DOWNLOAD FEEDBACK FUNCTIONS ===
// =======================================

function getZipDownloadUrl(folderPath) {
    return `api.php?action=download_zip&dir=${encodeURIComponent(folderPath)}`;
}

function showZipFeedback(folderName) {
    const overlay = document.getElementById('zip-progress-overlay');
    const messageEl = document.getElementById('zip-progress-message');
    if (!overlay || !messageEl) return;

    messageEl.textContent = `Đang chuẩn bị file ZIP cho thư mục "${folderName}", vui lòng chờ...`;
    overlay.style.display = 'flex'; // Use flex to center content
    document.body.style.overflow = 'hidden'; // Disable body scroll
    document.body.classList.add('body-blur'); // ADDED: Add blur class

    // Auto-hide feedback after a while (e.g., 45 seconds)
    clearTimeout(zipResetFeedbackTimerId); // Clear previous timer if any
    zipResetFeedbackTimerId = setTimeout(hideZipFeedback, 45000);
}

function hideZipFeedback() {
    const overlay = document.getElementById('zip-progress-overlay');
    if (!overlay) return;

    clearTimeout(zipDownloadTimerId); // Stop the download from starting if it hasn't yet
    clearTimeout(zipResetFeedbackTimerId); // Stop the auto-hide timer

    overlay.style.display = 'none';
    document.body.style.overflow = 'auto'; // Re-enable body scroll
    document.body.classList.remove('body-blur'); // ADDED: Remove blur class

    // Optional: Re-enable the download button if you disabled it
    // const downloadButton = document.getElementById('download-all-link');
    // if (downloadButton) downloadButton.disabled = false;
}

// =======================================
// === ACTION HANDLER FUNCTIONS        ===
// =======================================

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

function handleDownloadZipAction(folderPath, folderName) {
    if (!folderPath) {
        alert('Lỗi: Không thể xác định thư mục hiện tại để tải về.');
        return;
    }

    showZipFeedback(folderName);

    // Schedule the actual download start after a short delay
    clearTimeout(zipDownloadTimerId); // Clear previous timer
    zipDownloadTimerId = setTimeout(() => {
        console.log("Starting ZIP download for:", folderPath);
        window.location.href = getZipDownloadUrl(folderPath);
        // Feedback stays visible until cancelled or timeout
    }, 150); // 150ms delay

    // Hide menu if open
    const menu = document.getElementById('more-actions-menu');
    if (menu) menu.classList.remove('menu-visible');
}
  