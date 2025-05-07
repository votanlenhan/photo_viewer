import { currentImageList } from './state.js';
import { API_BASE_URL } from './config.js';

// DOM Elements
let imageViewEl, currentDirectoryNameEl, imageGridEl, loadMoreContainerEl, loadMoreBtnEl;

// Callbacks
let appOpenPhotoSwipe = (index) => console.error('openPhotoSwipe not initialized in uiImageView');
let appLoadMoreImages = () => console.error('loadMoreImages not initialized in uiImageView');

export function initializeImageView(callbacks) {
    console.log('[uiImageView] initializeImageView called.');
    imageViewEl = document.getElementById('image-view');
    currentDirectoryNameEl = document.getElementById('current-directory-name');
    imageGridEl = document.getElementById('image-grid');
    loadMoreContainerEl = document.getElementById('load-more-container');
    loadMoreBtnEl = document.getElementById('loadMoreBtn');

    if (!imageViewEl || !currentDirectoryNameEl || !imageGridEl || !loadMoreContainerEl || !loadMoreBtnEl) {
        console.error("One or more image view elements are missing!");
        return;
    }

    if (callbacks) {
        if (callbacks.openPhotoSwipe) appOpenPhotoSwipe = callbacks.openPhotoSwipe;
        if (callbacks.loadMoreImages) appLoadMoreImages = callbacks.loadMoreImages;
    }

    loadMoreBtnEl.addEventListener('click', () => appLoadMoreImages());
}

export function showImageViewOnly() {
    console.log('[uiImageView] showImageViewOnly called.');
    if (imageViewEl) {
        console.log('[uiImageView] imageViewEl found, setting display to block.');
        imageViewEl.style.display = 'block';
    } else {
        console.error('[uiImageView] imageViewEl NOT found in showImageViewOnly.');
    }
}

export function hideImageViewOnly() {
    if (imageViewEl) imageViewEl.style.display = 'none';
}

export function updateImageViewHeader(directoryName) {
    if (currentDirectoryNameEl) {
        currentDirectoryNameEl.textContent = `Album: ${directoryName}`;
    }
}

export function clearImageGrid() {
    if (imageGridEl) imageGridEl.innerHTML = '';
}

export function renderImageItems(imagesDataToRender, append = false) {
    console.log('[uiImageView] renderImageItems called. Appending:', append, 'Data:', imagesDataToRender);
    if (!imageGridEl) {
        console.error('[uiImageView] imageGridEl NOT found in renderImageItems.');
        return;
    }
    
    const containerToUse = imageGridEl.querySelector('.image-group') || imageGridEl; 
    console.log('[uiImageView] Container for images:', containerToUse);

    if (!imagesDataToRender || imagesDataToRender.length === 0) {
        console.log('[uiImageView] No imagesDataToRender provided or empty.');
        // Optionally, clear the container or show a message if not appending
        if (!append) containerToUse.innerHTML = '<p class="info-text">Không có ảnh trong album này.</p>';
        return;
    }

    imagesDataToRender.forEach((imgData) => {
        const div = document.createElement('div');
        div.className = 'image-item';

        const anchor = document.createElement('a');
        anchor.className = 'photoswipe-trigger'; // Class for PhotoSwipe to detect

        const fullImagePath = `${API_BASE_URL}?action=get_image&path=${encodeURIComponent(imgData.path)}`;
        anchor.href = fullImagePath; // href for the anchor
        anchor.dataset.pswpSrc = fullImagePath; // Source for PhotoSwipe

        const imageIndex = currentImageList.findIndex(item => item.path === imgData.path);
        if (imageIndex !== -1) {
            anchor.dataset.pswpIndex = imageIndex;
        }
        
        // Add back the click handler to the anchor
        anchor.onclick = (e) => {
            e.preventDefault(); // Prevent default anchor action
            if (imageIndex !== -1) {
                appOpenPhotoSwipe(imageIndex);
            } else {
                const fallbackIndex = currentImageList.findIndex(item => item.name === imgData.name);
                if (fallbackIndex !== -1) {
                    appOpenPhotoSwipe(fallbackIndex);
                } else {
                    console.error("Could not find image index for:", imgData.name, imgData.path);
                }
            }
        };

        const img = document.createElement('img');
        const thumbSrc = `${API_BASE_URL}?action=get_thumbnail&path=${encodeURIComponent(imgData.path)}&size=750`;

        img.src = thumbSrc; 
        img.alt = imgData.name;
        img.loading = 'lazy';

        img.onerror = () => { 
            img.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
            img.alt = 'Lỗi tải ảnh xem trước'; 
        };

        anchor.appendChild(img);
        div.appendChild(anchor);
        containerToUse.appendChild(div);
    });
}

export function createImageGroupIfNeeded(){
    console.log('[uiImageView] createImageGroupIfNeeded called.');
    if (!imageGridEl) {
        console.error('[uiImageView] imageGridEl NOT found in createImageGroupIfNeeded.');
        return;
    }
    if (!imageGridEl.querySelector('.image-group')){
        console.log('[uiImageView] No .image-group found, creating one.');
        const imageGroupContainer = document.createElement('div');
        imageGroupContainer.className = 'image-group';
        imageGridEl.appendChild(imageGroupContainer);
    }
}

export function toggleLoadMoreButton(show) {
    if (loadMoreContainerEl) {
        loadMoreContainerEl.style.display = show ? 'block' : 'none';
    }
} 