import { API_BASE_URL } from '../config';
import { PhotoSwipeLightboxMock, getLatestInstance as getLatestLightboxInstance, resetLatestInstance as resetLightboxMockInstance } from '../__mocks__/photoswipe-lightbox.mock.js';
import { PhotoSwipeMock } from '../__mocks__/photoswipe.mock.js';

describe('photoswipeHandler', () => {
    let setupPhotoSwipeIfNeeded, openPhotoSwipeAtIndex, isPhotoSwipeActive, closePhotoSwipeIfActive;
    let stateModuleInstance;

    beforeAll(() => {
        jest.doMock('../state', () => {
            const actualMockedState = {
                __esModule: true,
                photoswipeLightbox: null,
                currentImageList: [],
                setPhotoswipeLightbox: jest.fn((instance) => {
                    actualMockedState.photoswipeLightbox = instance;
                }),
            };
            return actualMockedState;
        });

        const photoswipeHandlerModule = require('../photoswipeHandler');
        setupPhotoSwipeIfNeeded = photoswipeHandlerModule.setupPhotoSwipeIfNeeded;
        openPhotoSwipeAtIndex = photoswipeHandlerModule.openPhotoSwipeAtIndex;
        isPhotoSwipeActive = photoswipeHandlerModule.isPhotoSwipeActive;
        closePhotoSwipeIfActive = photoswipeHandlerModule.closePhotoSwipeIfActive;

        stateModuleInstance = require('../state');
    });

    beforeEach(() => {
        PhotoSwipeLightboxMock.mockClear();
        PhotoSwipeMock.mockClear();
        resetLightboxMockInstance();

        const latestInstance = getLatestLightboxInstance();
        if (latestInstance) {
            if(latestInstance.init) latestInstance.init.mockClear();
            if(latestInstance.destroy) latestInstance.destroy.mockClear();
            if(latestInstance.loadAndOpen) latestInstance.loadAndOpen.mockClear();
            if (latestInstance.pswp && latestInstance.pswp.close) {
                latestInstance.pswp.close.mockClear();
            }
        }

        if (stateModuleInstance) {
            stateModuleInstance.photoswipeLightbox = null;
            stateModuleInstance.currentImageList = [];
            if (stateModuleInstance.setPhotoswipeLightbox && stateModuleInstance.setPhotoswipeLightbox.mockClear) {
                 stateModuleInstance.setPhotoswipeLightbox.mockClear();
            }
        }
        document.body.innerHTML = '';
    });

    afterAll(() => {
        jest.resetModules();
    });

    test('setupPhotoSwipeIfNeeded should create and init new lightbox if none exists', () => {
        stateModuleInstance.currentImageList = [{ path: 'img1.jpg', width: 100, height: 80, name: 'Image 1' }];
        setupPhotoSwipeIfNeeded();
        expect(PhotoSwipeLightboxMock).toHaveBeenCalledTimes(1);
        const currentInstance = getLatestLightboxInstance();
        expect(currentInstance).not.toBeNull();
        if (!currentInstance) return;
        expect(currentInstance.init).toHaveBeenCalledTimes(1);
        expect(currentInstance.options.dataSource).toEqual([{ 
            src: `${API_BASE_URL}?action=get_image&path=img1.jpg`, 
            width: 100, height: 80, alt: 'Image 1' 
        }]);
        expect(currentInstance.options.pswpModule).toBe(PhotoSwipeMock);
        expect(stateModuleInstance.setPhotoswipeLightbox).toHaveBeenCalledWith(currentInstance);
        expect(stateModuleInstance.photoswipeLightbox).toBe(currentInstance);
    });

    test('setupPhotoSwipeIfNeeded should destroy existing lightbox before creating new one', () => {
        const oldLightboxMock = { destroy: jest.fn(), init: jest.fn(), loadAndOpen: jest.fn(), options: {}, pswp: null };
        stateModuleInstance.photoswipeLightbox = oldLightboxMock;
        stateModuleInstance.currentImageList = [{ path: 'img2.jpg', width: 0, height: 0, name: 'Image 2' }];
        setupPhotoSwipeIfNeeded();
        expect(oldLightboxMock.destroy).toHaveBeenCalledTimes(1);
        expect(PhotoSwipeLightboxMock).toHaveBeenCalledTimes(1);
        const currentInstance = getLatestLightboxInstance();
        expect(currentInstance).not.toBeNull();
        if (!currentInstance) return;
        expect(currentInstance.init).toHaveBeenCalledTimes(1);
        expect(stateModuleInstance.setPhotoswipeLightbox).toHaveBeenCalledWith(currentInstance);
        expect(stateModuleInstance.photoswipeLightbox).toBe(currentInstance);
        expect(currentInstance.options.dataSource).toEqual([{ 
            src: `${API_BASE_URL}?action=get_image&path=img2.jpg`, 
            width: 0, height: 0, alt: 'Image 2' 
        }]);
    });

    test('openPhotoSwipeAtIndex should setup lightbox if not initialized', () => {
        stateModuleInstance.currentImageList = [{ path: 'img3.jpg', width: 10, height: 10, name: 'Image 3' }];
        stateModuleInstance.photoswipeLightbox = null;
        openPhotoSwipeAtIndex(0);
        expect(PhotoSwipeLightboxMock).toHaveBeenCalledTimes(1);
        const currentInstance = getLatestLightboxInstance();
        expect(currentInstance).not.toBeNull();
        if (!currentInstance) return;
        expect(currentInstance.init).toHaveBeenCalledTimes(1);
        expect(stateModuleInstance.setPhotoswipeLightbox).toHaveBeenCalledWith(currentInstance);
        expect(stateModuleInstance.photoswipeLightbox).toBe(currentInstance);
        expect(currentInstance.loadAndOpen).toHaveBeenCalledTimes(1);
        expect(currentInstance.loadAndOpen).toHaveBeenCalledWith(0);
        expect(currentInstance.options.dataSource).toEqual([{ 
            src: `${API_BASE_URL}?action=get_image&path=img3.jpg`, 
            width: 10, height: 10, alt: 'Image 3' 
        }]);
    });

    test('openPhotoSwipeAtIndex should call loadAndOpen on existing lightbox', () => {
        const activeInstance = {
            destroy: jest.fn(), init: jest.fn(),
            loadAndOpen: jest.fn((index) => { activeInstance.pswp = { isOpen: true, close: jest.fn() }; }),
            options: { pswpModule: PhotoSwipeMock, dataSource: [] }, pswp: null
        };
        stateModuleInstance.photoswipeLightbox = activeInstance;
        stateModuleInstance.currentImageList = [{ path: 'img4.jpg', width: 50, height: 50, name: 'Image 4' }];
        openPhotoSwipeAtIndex(0);
        expect(activeInstance.destroy).not.toHaveBeenCalled();
        expect(PhotoSwipeLightboxMock).not.toHaveBeenCalled();
        expect(activeInstance.loadAndOpen).toHaveBeenCalledTimes(1);
        expect(activeInstance.loadAndOpen).toHaveBeenCalledWith(0);
        expect(activeInstance.options.dataSource).toEqual([{ 
            src: `${API_BASE_URL}?action=get_image&path=img4.jpg`, 
            width: 50, height: 50, alt: 'Image 4' 
        }]);
    });
    
    test('isPhotoSwipeActive should return false if lightbox not initialized', () => {
        stateModuleInstance.photoswipeLightbox = null; 
        expect(isPhotoSwipeActive()).toBe(false); 
    });

    test('isPhotoSwipeActive should return false if pswp instance not present or not open', () => {
        const initLightbox = { pswp: null, init: jest.fn(),options:{},destroy:jest.fn(),loadAndOpen:jest.fn()};
        stateModuleInstance.photoswipeLightbox = initLightbox;
        expect(isPhotoSwipeActive()).toBe(false);
        initLightbox.pswp = { isOpen: false, close: jest.fn() };
        expect(isPhotoSwipeActive()).toBe(false);
    });
  
    test('isPhotoSwipeActive should return true if pswp instance is present and open', () => {
       stateModuleInstance.currentImageList = [{ path: 'img1.jpg', name: 'Image 1', width:100, height:100 }];
       setupPhotoSwipeIfNeeded(); 
       const currentInstance = getLatestLightboxInstance();
       if (currentInstance && currentInstance.loadAndOpen) {
           currentInstance.loadAndOpen(0); 
       } else {
           fail('Lightbox instance or loadAndOpen not found after setup');
       }
       expect(isPhotoSwipeActive()).toBe(true);
    });

   test('closePhotoSwipeIfActive should do nothing if not active', () => {
        stateModuleInstance.photoswipeLightbox = null; 
        expect(closePhotoSwipeIfActive()).toBe(false);
        const closedLightbox = { pswp: { isOpen: false, close: jest.fn() }, init:jest.fn(),options:{},destroy:jest.fn(),loadAndOpen:jest.fn()};
        stateModuleInstance.photoswipeLightbox = closedLightbox;
        expect(closePhotoSwipeIfActive()).toBe(false);
        if (closedLightbox.pswp) {
            expect(closedLightbox.pswp.close).not.toHaveBeenCalled();
        }
   });

   test('closePhotoSwipeIfActive should call close if active', () => {
        stateModuleInstance.currentImageList = [{ path: 'img1.jpg', name: 'Image 1', width:100, height:100 }];
        setupPhotoSwipeIfNeeded();
        const currentInstance = getLatestLightboxInstance();
        let pswpCloseMockFn = null;
        if (currentInstance && currentInstance.loadAndOpen) {
            currentInstance.loadAndOpen(0); 
            if (currentInstance.pswp) {
                 pswpCloseMockFn = currentInstance.pswp.close;
            }
        } else {
            fail('Lightbox instance or loadAndOpen not found after setup');
        }
        expect(closePhotoSwipeIfActive()).toBe(true);
        if (pswpCloseMockFn) {
            expect(pswpCloseMockFn).toHaveBeenCalledTimes(1);
        } else {
            fail('PhotoSwipe pswp.close mock not found or lightbox did not open as expected.');
        }
   });

}); 