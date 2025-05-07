// js/__mocks__/photoswipe-lightbox.mock.js
// File này chỉ cần tồn tại và export một cái gì đó để moduleNameMapper hoạt động.
// Logic mock thực sự nằm trong file test (ví dụ: mockPhotoSwipeLightboxImpl).

let mockLightboxInstanceForFile = null; 

const mockPhotoSwipeLightboxImpl = jest.fn().mockImplementation((options) => {
  mockLightboxInstanceForFile = {
    options: { ...options },
    init: jest.fn(),
    destroy: jest.fn(),
    loadAndOpen: jest.fn((index) => {
      if (mockLightboxInstanceForFile) {
        mockLightboxInstanceForFile.pswp = {
          isOpen: true,
          close: jest.fn(() => {
            if (mockLightboxInstanceForFile && mockLightboxInstanceForFile.pswp) {
              mockLightboxInstanceForFile.pswp.isOpen = false;
            }
          }),
        };
      }
    }),
    pswp: null,
  };
  return mockLightboxInstanceForFile;
});

export default mockPhotoSwipeLightboxImpl;
export { mockPhotoSwipeLightboxImpl as PhotoSwipeLightboxMock };
export const getLatestInstance = () => mockLightboxInstanceForFile;
// Hàm reset (tùy chọn, nếu cần thiết cho việc clear giữa các test)
export const resetLatestInstance = () => {
    mockLightboxInstanceForFile = null;
}; 