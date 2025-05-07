import PhotoSwipeLightbox from 'https://unpkg.com/photoswipe@5/dist/photoswipe-lightbox.esm.js';
import PhotoSwipe from 'https://unpkg.com/photoswipe@5/dist/photoswipe.esm.js';
import { photoswipeLightbox, setPhotoswipeLightbox, currentImageList } from './state.js';
import { API_BASE_URL } from './config.js';

export function initializePhotoSwipeHandler() {
    // Placeholder if any specific initialization is needed for the handler itself
    // For now, setupPhotoSwipe will be called by other modules when image data is ready.
}

export function setupPhotoSwipeIfNeeded() {
    if (photoswipeLightbox) {
        photoswipeLightbox.destroy();
    }
    const newLightbox = new PhotoSwipeLightbox({
        dataSource: currentImageList.map(imgData => ({
            src: `${API_BASE_URL}?action=get_image&path=${encodeURIComponent(imgData.path)}`,
            width: imgData.width || 0, 
            height: imgData.height || 0, 
            alt: imgData.name
        })),
        pswpModule: PhotoSwipe,
        appendToEl: document.body,
    });
    newLightbox.init();
    setPhotoswipeLightbox(newLightbox);
}

export function openPhotoSwipeAtIndex(index) {
    if (!photoswipeLightbox) {
        console.warn("PhotoSwipe not initialized, attempting to set it up.");
        setupPhotoSwipeIfNeeded(); // Attempt to set up if not already
        if(!photoswipeLightbox) { // Check again
            console.error("PhotoSwipe could not be initialized!");
            return;
        }
    }
    // Ensure dataSource is up-to-date before opening
    photoswipeLightbox.options.dataSource = currentImageList.map(imgData => ({
        src: `${API_BASE_URL}?action=get_image&path=${encodeURIComponent(imgData.path)}`,
        width: imgData.width || 0, 
        height: imgData.height || 0, 
        alt: imgData.name
    }));
    photoswipeLightbox.loadAndOpen(index);
}

export function isPhotoSwipeActive() {
    // photoswipeLightbox is the PhotoSwipeLightbox instance from state
    // photoswipeLightbox.pswp is the actual PhotoSwipe gallery instance (if initialized and open)
    if (!photoswipeLightbox || !photoswipeLightbox.pswp) {
        return false;
    }
    return !!photoswipeLightbox.pswp.isOpen;
}

export function closePhotoSwipeIfActive() {
    if (isPhotoSwipeActive()) {
        photoswipeLightbox.pswp.close();
        console.log('[photoswipeHandler] PhotoSwipe closed by system (e.g., ZIP complete).');
        return true;
    }
    return false;
} 