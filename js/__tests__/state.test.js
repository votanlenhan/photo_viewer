import * as state from '../state'; // Import tất cả export dưới dạng object

describe('Application State', () => {

  // Test một vài setter và getter gián tiếp qua biến đã import
  test('setCurrentFolder should update currentFolder', () => {
    const newValue = 'main/new_folder';
    state.setCurrentFolder(newValue);
    expect(state.currentFolder).toBe(newValue);
  });

  test('setCurrentImageList should update currentImageList', () => {
    const newList = [{ name: 'img1.jpg', path: 'main/img1.jpg' }];
    state.setCurrentImageList(newList);
    expect(state.currentImageList).toEqual(newList);
    // expect(state.currentImageList).not.toBe(newList); // Direct assignment in setter means it WILL be the same reference
  });

  test('setAllTopLevelDirs should update allTopLevelDirs', () => {
      const newDirs = [{ name: 'dir1', path: 'main/dir1'}];
      state.setAllTopLevelDirs(newDirs);
      expect(state.allTopLevelDirs).toEqual(newDirs);
  });

  test('setIsLoadingMore should update isLoadingMore', () => {
    state.setIsLoadingMore(true);
    expect(state.isLoadingMore).toBe(true);
    state.setIsLoadingMore(false);
    expect(state.isLoadingMore).toBe(false);
  });

  test('setCurrentPage should update currentPage', () => {
      state.setCurrentPage(5);
      expect(state.currentPage).toBe(5);
  });
  
  test('setTotalImages should update totalImages', () => {
      state.setTotalImages(150);
      expect(state.totalImages).toBe(150);
  });

  test('setCurrentZipJobToken should update currentZipJobToken', () => {
      const token = 'zip-token-123';
      state.setCurrentZipJobToken(token);
      expect(state.currentZipJobToken).toBe(token);
       state.setCurrentZipJobToken(null);
      expect(state.currentZipJobToken).toBeNull();
  });

  // Test các setter cho DOM elements (chỉ kiểm tra việc gán giá trị)
  test('setZipProgressBarContainerEl should update reference', () => {
      const mockElement = { id: 'mock-progress-bar' }; // Đối tượng giả lập DOM element
      state.setZipProgressBarContainerEl(mockElement);
      expect(state.zipProgressBarContainerEl).toBe(mockElement);
      state.setZipProgressBarContainerEl(null);
      expect(state.zipProgressBarContainerEl).toBeNull();
  });
  
   test('setGeneralModalOverlay should update reference', () => {
      const mockElement = { id: 'mock-modal' }; 
      state.setGeneralModalOverlay(mockElement); 
      expect(state.generalModalOverlay).toBe(mockElement);
      state.setGeneralModalOverlay(null);
      expect(state.generalModalOverlay).toBeNull();
  });

  // Có thể thêm test cho các setter khác nếu cần
}); 