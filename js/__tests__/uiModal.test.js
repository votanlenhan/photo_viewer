import {
  initModalSystem,
  showModalWithMessage,
  hideModalWithMessage,
  escapeGeneralModalListener,
  showPasswordPrompt,
  hidePasswordPrompt
} from '../uiModal';
import { fetchDataApi } from '../apiService';

// --- Mocking Dependencies ---
jest.mock('../apiService', () => ({
  fetchDataApi: jest.fn(),
}));

let mockGeneralModalOverlayElement; 
jest.mock('../state', () => ({
  __esModule: true,
  get generalModalOverlay() { 
    return mockGeneralModalOverlayElement;
  }
}));

describe('uiModal', () => {
  let mockAppLoadSubItems;

  beforeEach(() => {
    fetchDataApi.mockClear();
    mockGeneralModalOverlayElement = document.createElement('div');
    mockGeneralModalOverlayElement.id = 'generalModalOverlay'; 
    document.body.appendChild(mockGeneralModalOverlayElement);
    mockAppLoadSubItems = jest.fn();
    initModalSystem(mockAppLoadSubItems); 
    document.body.className = '';
    document.removeEventListener('keydown', escapeGeneralModalListener);
  });

  afterEach(() => {
    if (mockGeneralModalOverlayElement && mockGeneralModalOverlayElement.parentNode) {
      mockGeneralModalOverlayElement.parentNode.removeChild(mockGeneralModalOverlayElement);
    }
    mockGeneralModalOverlayElement = null;
    document.removeEventListener('keydown', escapeGeneralModalListener);
  });

  describe('General Message Modal', () => {
    test('showModalWithMessage should display modal with title and content', () => {
      showModalWithMessage('Test Title', '<p>Test Content</p>');
      expect(mockGeneralModalOverlayElement.classList.contains('modal-visible')).toBe(true);
      expect(document.body.classList.contains('body-blur')).toBe(true);
      const titleEl = mockGeneralModalOverlayElement.querySelector('h3');
      const contentEl = mockGeneralModalOverlayElement.querySelector('.modal-content-area');
      expect(titleEl.textContent).toBe('Test Title');
      expect(contentEl.innerHTML).toBe('<p>Test Content</p>');
      const okBtn = document.getElementById('modalOkBtn');
      expect(okBtn).not.toBeNull();
      expect(okBtn.textContent).toBe('Đóng');
    });

    test('showModalWithMessage should handle isError and okButtonText', () => {
      showModalWithMessage('Error Title', 'Error Content', true, false, false, null, 'Try Again');
      const okBtn = document.getElementById('modalOkBtn');
      expect(okBtn.classList.contains('error-button-style')).toBe(true);
      expect(okBtn.textContent).toBe('Try Again');
    });

    test('showModalWithMessage with isInfoOnly should not blur body', () => {
        showModalWithMessage('Info Title', 'Info Content', false, true);
        expect(mockGeneralModalOverlayElement.classList.contains('modal-visible')).toBe(true);
        expect(document.body.classList.contains('body-blur')).toBe(false);
    });
    
    test('showModalWithMessage should display cancel button and call callback', () => {
        const mockCancelCallback = jest.fn();
        showModalWithMessage('Confirm', 'Are you sure?', false, false, true, mockCancelCallback, 'Confirm OK');
        const cancelBtn = document.getElementById('modalCancelBtn');
        expect(cancelBtn).not.toBeNull();
        expect(cancelBtn.textContent).toBe('Hủy');
        cancelBtn.click();
        expect(mockGeneralModalOverlayElement.classList.contains('modal-visible')).toBe(false);
        expect(mockCancelCallback).toHaveBeenCalledTimes(1);
    });

    test('hideModalWithMessage should hide modal and remove blur', () => {
      showModalWithMessage('Test Title', 'Test Content'); 
      hideModalWithMessage();
      expect(mockGeneralModalOverlayElement.classList.contains('modal-visible')).toBe(false);
      expect(mockGeneralModalOverlayElement.innerHTML).toBe(''); 
      expect(document.body.classList.contains('body-blur')).toBe(false);
    });

    test('escapeGeneralModalListener should hide modal on Escape key', () => {
      showModalWithMessage('Test Title', 'Test Content');
      const escapeEvent = new KeyboardEvent('keydown', { key: 'Escape' });
      document.dispatchEvent(escapeEvent);
      expect(mockGeneralModalOverlayElement.classList.contains('modal-visible')).toBe(false);
    });
  });

  describe('Password Prompt Modal', () => {
    const FOLDER_NAME = 'Secret Album';

    test('showPasswordPrompt should display prompt with folder name', () => {
      showPasswordPrompt(FOLDER_NAME);
      expect(mockGeneralModalOverlayElement.classList.contains('modal-visible')).toBe(true);
      expect(document.body.classList.contains('body-blur')).toBe(true);
      const titleEl = mockGeneralModalOverlayElement.querySelector('h3');
      expect(titleEl.textContent).toBe('Nhập mật khẩu');
      const folderNameEl = mockGeneralModalOverlayElement.querySelector('p strong');
      expect(folderNameEl.textContent).toBe(FOLDER_NAME);
      expect(document.getElementById('passwordPromptInput')).not.toBeNull();
      expect(document.getElementById('passwordPromptOk')).not.toBeNull();
      expect(document.getElementById('passwordPromptCancel')).not.toBeNull();
    });

    test('hidePasswordPrompt should hide prompt and remove blur', () => {
      showPasswordPrompt(FOLDER_NAME); 
      hidePasswordPrompt();
      expect(mockGeneralModalOverlayElement.classList.contains('modal-visible')).toBe(false);
      expect(mockGeneralModalOverlayElement.innerHTML).toBe('');
      expect(document.body.classList.contains('body-blur')).toBe(false);
    });

    test('Escape key should hide password prompt', () => {
        showPasswordPrompt(FOLDER_NAME);
        const escapeEvent = new KeyboardEvent('keydown', { key: 'Escape' });
        document.dispatchEvent(escapeEvent); 
        expect(mockGeneralModalOverlayElement.classList.contains('modal-visible')).toBe(false);
    });
    
    test('Cancel button should hide password prompt', () => {
        showPasswordPrompt(FOLDER_NAME);
        const cancelBtn = document.getElementById('passwordPromptCancel');
        cancelBtn.click();
        expect(mockGeneralModalOverlayElement.classList.contains('modal-visible')).toBe(false);
    });

    describe('handlePasswordSubmit (within showPasswordPrompt)', () => {
      let passwordInput, okBtn, errorEl;
      
      const setupPasswordPromptAndGetElements = () => {
          showPasswordPrompt(FOLDER_NAME);
          passwordInput = document.getElementById('passwordPromptInput');
          okBtn = document.getElementById('passwordPromptOk');
          errorEl = document.getElementById('passwordPromptError');
      };

      test('should show error if password is empty', async () => {
        setupPasswordPromptAndGetElements();
        passwordInput.value = '';
        await okBtn.click(); 
        expect(errorEl.textContent).toBe('Vui lòng nhập mật khẩu.');
        expect(fetchDataApi).not.toHaveBeenCalled();
      });

      test('should call fetchDataApi with correct params and handle success', async () => {
        fetchDataApi.mockResolvedValueOnce({
          status: 'success',
          data: { success: true }
        });
        setupPasswordPromptAndGetElements();
        passwordInput.value = 'testpass';
        await okBtn.click();
        expect(fetchDataApi).toHaveBeenCalledTimes(1);
        expect(fetchDataApi).toHaveBeenCalledWith(
          'authenticate',
          {},
          expect.objectContaining({
            method: 'POST',
            body: expect.any(FormData)
          })
        );
        const formData = fetchDataApi.mock.calls[0][2].body;
        expect(formData.get('folder')).toBe(FOLDER_NAME);
        expect(formData.get('password')).toBe('testpass');
        expect(mockGeneralModalOverlayElement.classList.contains('modal-visible')).toBe(false); 
        expect(mockAppLoadSubItems).toHaveBeenCalledTimes(1);
        expect(mockAppLoadSubItems).toHaveBeenCalledWith(FOLDER_NAME);
        expect(errorEl.textContent).toBe('');
      });
      
      test('Enter key in password input should submit password', async () => {
        fetchDataApi.mockResolvedValueOnce({
          status: 'success',
          data: { success: true }
        });
        setupPasswordPromptAndGetElements();
        passwordInput.value = 'testpassViaEnter';
        const enterEvent = new KeyboardEvent('keypress', { key: 'Enter' });
        passwordInput.dispatchEvent(enterEvent);
        await new Promise(resolve => setTimeout(resolve, 0)); 
        expect(fetchDataApi).toHaveBeenCalledTimes(1);
        const formData = fetchDataApi.mock.calls[0][2].body;
        expect(formData.get('password')).toBe('testpassViaEnter');
        expect(mockAppLoadSubItems).toHaveBeenCalledWith(FOLDER_NAME);
      });

      test('should handle failed authentication (incorrect password)', async () => {
        fetchDataApi.mockResolvedValueOnce({
          status: 'error', 
          message: 'Mật khẩu sai'
        });
        setupPasswordPromptAndGetElements();
        passwordInput.value = 'wrongpass';
        await okBtn.click();
        expect(fetchDataApi).toHaveBeenCalledTimes(1);
        expect(mockGeneralModalOverlayElement.classList.contains('modal-visible')).toBe(true); 
        expect(mockAppLoadSubItems).not.toHaveBeenCalled();
        expect(errorEl.textContent).toBe('Mật khẩu sai');
      });

      test('should handle API error during authentication', async () => {
        fetchDataApi.mockResolvedValueOnce({
          status: 'error',
          message: 'Lỗi mạng'
        });
        setupPasswordPromptAndGetElements();
        passwordInput.value = 'anypass';
        await okBtn.click();
        expect(fetchDataApi).toHaveBeenCalledTimes(1);
        expect(mockGeneralModalOverlayElement.classList.contains('modal-visible')).toBe(true);
        expect(mockAppLoadSubItems).not.toHaveBeenCalled();
        expect(errorEl.textContent).toBe('Lỗi mạng');
      });
    });
  });
}); 