// js/__mocks__/photoswipe.mock.js
// File này chỉ cần tồn tại và export một cái gì đó để moduleNameMapper hoạt động.
// Logic mock thực sự nằm trong file test (ví dụ: mockPhotoSwipeImpl).
const mockPhotoSwipeImpl = jest.fn();

export default mockPhotoSwipeImpl;
export { mockPhotoSwipeImpl as PhotoSwipeMock }; 