import {
    initializeZipManager,
    setActiveZipJob,
    getActiveZipJob,
    clearActiveZipJob,
    displayZipProgressBar,
    updateZipProgressBar,
    hideZipProgressBar,
    handleDownloadZipAction,
    pollZipStatus
} from '../zipManager';
import { fetchDataApi } from '../apiService';
import { showModalWithMessage } from '../uiModal';
import { closePhotoSwipeIfActive } from '../photoswipeHandler';
import { ACTIVE_ZIP_JOB_KEY, API_BASE_URL } from '../config';

// --- Mocking Dependencies ---
jest.mock('../apiService', () => ({
    fetchDataApi: jest.fn(),
}));

jest.mock('../uiModal', () => ({
    showModalWithMessage: jest.fn(),
}));

jest.mock('../photoswipeHandler', () => ({
    closePhotoSwipeIfActive: jest.fn(),
}));

// Mock pollZipStatus specifically for tests that don't want actual polling
const mockPollZipStatus = jest.fn();
jest.mock('../zipManager', () => {
    const originalModule = jest.requireActual('../zipManager');
    return {
        ...originalModule,
        pollZipStatus: mockPollZipStatus, // Use the mock function here
    };
});

// Now import the functions from zipManager, including the one we want to test
// and the mocked one.
const zipManager = require('../zipManager');
const { handleDownloadZipAction, setActiveZipJob, getActiveZipJob, clearActiveZipJob, displayZipProgressBar, updateZipProgressBar, hideZipProgressBar, initializeZipManager } = zipManager;

// Sửa mock state để sử dụng stateData nội bộ
jest.mock('../state', () => {
    const stateData = {
        currentZipJobToken: null,
        zipPollingIntervalId: null,
        zipProgressBarContainerEl: null,
        zipFolderNameEl: null,
        zipOverallProgressEl: null,
        zipProgressStatsTextEl: null,
    };
    return {
        __esModule: true,
        __internalState: stateData, // Export để beforeEach có thể reset
        get currentZipJobToken() { return stateData.currentZipJobToken; },
        setCurrentZipJobToken: jest.fn(token => { stateData.currentZipJobToken = token; }),
        get zipPollingIntervalId() { return stateData.zipPollingIntervalId; },
        setZipPollingIntervalId: jest.fn(id => { stateData.zipPollingIntervalId = id; }),
        get zipProgressBarContainerEl() { return stateData.zipProgressBarContainerEl; },
        get zipFolderNameEl() { return stateData.zipFolderNameEl; },
        get zipOverallProgressEl() { return stateData.zipOverallProgressEl; },
        get zipProgressStatsTextEl() { return stateData.zipProgressStatsTextEl; },
    };
});

// Biến để lưu trữ tham chiếu đến module state đã mock
let stateModuleInstance;

beforeEach(() => {
    fetchDataApi.mockClear();
    showModalWithMessage.mockClear();
    closePhotoSwipeIfActive.mockClear();
    mockPollZipStatus.mockClear(); // Clear the specific mock for pollZipStatus

    // Lấy tham chiếu đến module mock
    stateModuleInstance = require('../state');

    // Reset state nội bộ của module mock
    if (stateModuleInstance && stateModuleInstance.__internalState) {
        stateModuleInstance.__internalState.currentZipJobToken = null;
        stateModuleInstance.__internalState.zipPollingIntervalId = null;
        
        // Tạo và thiết lập DOM elements, gán vào state nội bộ
        stateModuleInstance.__internalState.zipProgressBarContainerEl = document.createElement('div');
        stateModuleInstance.__internalState.zipFolderNameEl = document.createElement('span');
        stateModuleInstance.__internalState.zipOverallProgressEl = document.createElement('progress');
        stateModuleInstance.__internalState.zipOverallProgressEl.max = 100; 
        stateModuleInstance.__internalState.zipOverallProgressEl.value = 0; 
        stateModuleInstance.__internalState.zipProgressStatsTextEl = document.createElement('span');

        const container = stateModuleInstance.__internalState.zipProgressBarContainerEl;
        if (container) {
            container.appendChild(stateModuleInstance.__internalState.zipFolderNameEl);
            container.appendChild(stateModuleInstance.__internalState.zipOverallProgressEl);
            container.appendChild(stateModuleInstance.__internalState.zipProgressStatsTextEl);
            // Xóa container cũ khỏi body nếu có trước khi thêm mới
            const existingContainer = document.body.querySelector('#mockZipProgressContainer'); // Giả sử có ID
            if(existingContainer) document.body.removeChild(existingContainer);
            container.id = 'mockZipProgressContainer'; // Thêm ID để dễ tìm/xóa
            document.body.appendChild(container);
            container.style.display = 'none';
        }
        
        // Clear các setter mock
        if(stateModuleInstance.setCurrentZipJobToken && stateModuleInstance.setCurrentZipJobToken.mockClear) {
             stateModuleInstance.setCurrentZipJobToken.mockClear();
        }
        if(stateModuleInstance.setZipPollingIntervalId && stateModuleInstance.setZipPollingIntervalId.mockClear) {
             stateModuleInstance.setZipPollingIntervalId.mockClear();
        }
    } else {
        // Fallback hoặc báo lỗi nếu stateModuleInstance không đúng như mong đợi
        console.error("State module mock not found or not initialized correctly in beforeEach.");
    }

    jest.spyOn(window.sessionStorage.__proto__, 'setItem').mockClear();
    jest.spyOn(window.sessionStorage.__proto__, 'getItem').mockClear();
    jest.spyOn(window.sessionStorage.__proto__, 'removeItem').mockClear();
    sessionStorage.clear(); 
    jest.useFakeTimers();
    // SPY ON clearInterval
    jest.spyOn(global, 'clearInterval').mockClear(); 
});

afterEach(() => {
    // Dọn dẹp container khỏi body
    const container = document.body.querySelector('#mockZipProgressContainer');
    if (container) document.body.removeChild(container);
    
    jest.restoreAllMocks(); 
    jest.clearAllTimers(); 
});

describe('zipManager', () => {
    const JOB_TOKEN = 'test-zip-token-123';
    const SOURCE_PATH = 'main/my-album';
    const FOLDER_DISPLAY_NAME = 'My Album Display';

    describe('Job Management (sessionStorage & state)', () => {
        test('setActiveZipJob should store job details in sessionStorage and state', () => {
            setActiveZipJob(JOB_TOKEN, SOURCE_PATH, FOLDER_DISPLAY_NAME);
            expect(sessionStorage.setItem).toHaveBeenCalledWith(
                ACTIVE_ZIP_JOB_KEY,
                JSON.stringify({ jobToken: JOB_TOKEN, sourcePath: SOURCE_PATH, folderDisplayName: FOLDER_DISPLAY_NAME })
            );
            expect(stateModuleInstance.setCurrentZipJobToken).toHaveBeenCalledWith(JOB_TOKEN);
            expect(stateModuleInstance.currentZipJobToken).toBe(JOB_TOKEN);
        });

        test('getActiveZipJob should retrieve job details from sessionStorage', () => {
            const jobData = { jobToken: JOB_TOKEN, sourcePath: SOURCE_PATH, folderDisplayName: FOLDER_DISPLAY_NAME };
            jest.spyOn(window.sessionStorage.__proto__, 'getItem').mockReturnValueOnce(JSON.stringify(jobData));
            const retrievedJob = getActiveZipJob();
            expect(sessionStorage.getItem).toHaveBeenCalledWith(ACTIVE_ZIP_JOB_KEY);
            expect(retrievedJob).toEqual(jobData);
        });

        test('getActiveZipJob should return null if no job in sessionStorage', () => {
            jest.spyOn(window.sessionStorage.__proto__, 'getItem').mockReturnValueOnce(null);
            const retrievedJob = getActiveZipJob();
            expect(retrievedJob).toBeNull();
        });

        test('clearActiveZipJob should remove job from sessionStorage and clear state', () => {
            // Setup state ban đầu trực tiếp vào stateData của mock
            stateModuleInstance.__internalState.currentZipJobToken = JOB_TOKEN;
            stateModuleInstance.__internalState.zipPollingIntervalId = 12345; 
            sessionStorage.setItem(ACTIVE_ZIP_JOB_KEY, JSON.stringify({ jobToken: JOB_TOKEN, sourcePath: SOURCE_PATH, folderDisplayName: FOLDER_DISPLAY_NAME }));
            
            clearActiveZipJob();

            expect(sessionStorage.removeItem).toHaveBeenCalledWith(ACTIVE_ZIP_JOB_KEY);
            expect(stateModuleInstance.setCurrentZipJobToken).toHaveBeenCalledWith(null);
            expect(stateModuleInstance.currentZipJobToken).toBeNull(); // Getter sẽ đọc stateData mới
            expect(global.clearInterval).toHaveBeenCalledWith(12345);
            expect(stateModuleInstance.setZipPollingIntervalId).toHaveBeenCalledWith(null);
            expect(stateModuleInstance.zipPollingIntervalId).toBeNull(); // Getter sẽ đọc stateData mới
        });
    });

    describe('Progress Bar UI', () => {
        test('displayZipProgressBar should show progress bar with folder name and status', () => {
            displayZipProgressBar(FOLDER_DISPLAY_NAME, 'Initial Status');
            expect(stateModuleInstance.zipProgressBarContainerEl.style.display).toBe('flex');
            expect(stateModuleInstance.zipFolderNameEl.textContent).toBe(FOLDER_DISPLAY_NAME);
            expect(stateModuleInstance.zipProgressStatsTextEl.textContent).toBe('Initial Status');
            expect(stateModuleInstance.zipOverallProgressEl.value).toBe(0);
        });

        test('hideZipProgressBar should hide progress bar', () => {
            stateModuleInstance.__internalState.zipProgressBarContainerEl.style.display = 'flex'; 
            hideZipProgressBar();
            expect(stateModuleInstance.zipProgressBarContainerEl.style.display).toBe('none');
        });

        test('updateZipProgressBar should correctly update UI for processing status', () => {
            const jobData = { status: 'processing', total_files: 10, processed_files: 3, source_path: SOURCE_PATH };
            setActiveZipJob('some-token', SOURCE_PATH, FOLDER_DISPLAY_NAME); 
            updateZipProgressBar(jobData, null); 
            
            expect(stateModuleInstance.zipFolderNameEl.textContent).toBe(FOLDER_DISPLAY_NAME);
            expect(stateModuleInstance.zipOverallProgressEl.value).toBe(30); 
            expect(stateModuleInstance.zipProgressStatsTextEl.textContent).toBe('3/10 files (30%)');
            expect(stateModuleInstance.zipProgressBarContainerEl.style.display).toBe('flex');
        });

        test('updateZipProgressBar should correctly update UI for completed status', () => {
            const jobData = { status: 'completed', source_path: SOURCE_PATH };
            setActiveZipJob('some-token', SOURCE_PATH, FOLDER_DISPLAY_NAME);
            updateZipProgressBar(jobData, 'UI Overwrite Name'); 

            expect(stateModuleInstance.zipFolderNameEl.textContent).toBe('UI Overwrite Name');
            expect(stateModuleInstance.zipOverallProgressEl.value).toBe(100);
            expect(stateModuleInstance.zipProgressStatsTextEl.textContent).toBe('Hoàn thành!');
        });

        test('updateZipProgressBar should correctly update UI for failed status', () => {
            const jobData = { status: 'failed', source_path: SOURCE_PATH };
            setActiveZipJob('some-token', SOURCE_PATH, FOLDER_DISPLAY_NAME);
            stateModuleInstance.__internalState.zipOverallProgressEl.value = 50; 
            updateZipProgressBar(jobData, FOLDER_DISPLAY_NAME);

            expect(stateModuleInstance.zipFolderNameEl.textContent).toBe(`Lỗi: ${FOLDER_DISPLAY_NAME}`);
            expect(stateModuleInstance.zipOverallProgressEl.value).toBe(50); 
            expect(stateModuleInstance.zipProgressStatsTextEl.textContent).toBe('Thất bại!');
        });
    });

    describe('handleDownloadZipAction', () => {
        const FOLDER_PATH = 'test/folder';
        const FOLDER_NAME = 'Test Folder';

        test('should initiate zip request and start polling if job is pending/processing', async () => {
            const mockJobToken = 'new-zip-token-pending';
            fetchDataApi.mockResolvedValueOnce({
                status: 'success',
                data: { job_token: mockJobToken, status: 'pending' }
            });

            // handleDownloadZipAction is now imported with pollZipStatus already mocked
            await handleDownloadZipAction(FOLDER_PATH, FOLDER_NAME);

            expect(displayZipProgressBar).toHaveBeenCalledWith(FOLDER_NAME, 'Đang gửi yêu cầu...');
            expect(fetchDataApi).toHaveBeenCalledWith('request_zip', {}, {
                method: 'POST',
                body: expect.any(FormData) // FormData body check
            });
            
            const formData = fetchDataApi.mock.calls[0][2].body;
            expect(formData.get('path')).toBe(FOLDER_PATH);

            expect(setActiveZipJob).toHaveBeenCalledWith(mockJobToken, FOLDER_PATH, FOLDER_NAME);
            expect(mockPollZipStatus).toHaveBeenCalledWith(mockJobToken, FOLDER_NAME); // Check the mock
            expect(showModalWithMessage).not.toHaveBeenCalled();
            expect(hideZipProgressBar).not.toHaveBeenCalled();
        });

        // Test for already completed job
        test('should show download modal if job is already completed on initial request', async () => {
            const mockJobToken = 'new-zip-token-completed';
            fetchDataApi.mockResolvedValueOnce({
                status: 'success',
                data: { job_token: mockJobToken, status: 'completed', zip_filesize: 123456 }
            });

            await handleDownloadZipAction(FOLDER_PATH, FOLDER_NAME);

            expect(displayZipProgressBar).toHaveBeenCalledWith(FOLDER_NAME, 'Đang gửi yêu cầu...');
            expect(fetchDataApi).toHaveBeenCalledWith('request_zip', {}, expect.any(Object));
            expect(setActiveZipJob).toHaveBeenCalledWith(mockJobToken, FOLDER_PATH, FOLDER_NAME);
            
            expect(stateModuleInstance.zipOverallProgressEl.value).toBe(100);
            expect(stateModuleInstance.zipProgressStatsTextEl.textContent).toBe('Hoàn thành!');

            expect(clearActiveZipJob).toHaveBeenCalled();
            expect(showModalWithMessage).toHaveBeenCalledWith(
                'Tải ZIP hoàn thành (có sẵn)',
                expect.stringContaining(`<strong>${FOLDER_NAME}</strong>`),
                false
            );
            expect(showModalWithMessage.mock.calls[0][1]).toContain(`href="${API_BASE_URL}?action=download_final_zip&token=${mockJobToken}"`);

            expect(setTimeout).toHaveBeenCalledWith(expect.any(Function), 500);
            jest.runAllTimers(); 
            expect(hideZipProgressBar).toHaveBeenCalled();
            expect(mockPollZipStatus).not.toHaveBeenCalled(); // Ensure polling was not started
        });
        
        // Test for API error
        test('should show error modal if API request fails', async () => {
            fetchDataApi.mockResolvedValueOnce({
                status: 'error',
                message: 'Failed to create ZIP job'
            });

            await handleDownloadZipAction(FOLDER_PATH, FOLDER_NAME);

            expect(displayZipProgressBar).toHaveBeenCalledWith(FOLDER_NAME, 'Đang gửi yêu cầu...');
            expect(hideZipProgressBar).toHaveBeenCalledTimes(1);
            expect(showModalWithMessage).toHaveBeenCalledWith(
                'Lỗi yêu cầu ZIP',
                '<p>Failed to create ZIP job</p>',
                true
            );
            expect(clearActiveZipJob).toHaveBeenCalled();
            expect(setActiveZipJob).not.toHaveBeenCalled();
            expect(mockPollZipStatus).not.toHaveBeenCalled();
        });
        
        // Test for missing parameters
        test('should show error modal if folderPath or folderName is missing', async () => {
            await handleDownloadZipAction(null, FOLDER_NAME);
            expect(showModalWithMessage).toHaveBeenCalledWith(
                'Lỗi yêu cầu ZIP',
                '<p>Đường dẫn hoặc tên thư mục bị thiếu.</p>',
                true
            );
            expect(fetchDataApi).not.toHaveBeenCalled();

            showModalWithMessage.mockClear();
            fetchDataApi.mockClear(); // Clear fetchDataApi mock too

            await handleDownloadZipAction(FOLDER_PATH, null);
            expect(showModalWithMessage).toHaveBeenCalledWith(
                'Lỗi yêu cầu ZIP',
                '<p>Đường dẫn hoặc tên thư mục bị thiếu.</p>',
                true
            );
            expect(fetchDataApi).not.toHaveBeenCalled();
            expect(mockPollZipStatus).not.toHaveBeenCalled(); // Ensure poll not called here either
        });
        
        // Test that existing polling interval is cleared
        test('should clear existing polling interval before starting a new request', async () => {
            stateModuleInstance.__internalState.zipPollingIntervalId = 999;
            
            fetchDataApi.mockResolvedValueOnce({
                status: 'success',
                data: { job_token: 'new-token', status: 'pending' }
            });

            await handleDownloadZipAction(FOLDER_PATH, FOLDER_NAME);

            expect(global.clearInterval).toHaveBeenCalledWith(999);
            expect(stateModuleInstance.setZipPollingIntervalId).toHaveBeenCalledWith(null);
            expect(mockPollZipStatus).toHaveBeenCalledWith('new-token', FOLDER_NAME); // Ensure it still polls after clearing
        });
    });

    // Need to unmock to test the actual pollZipStatus implementation
    describe('pollZipStatus', () => {
        let actualPollZipStatus;
        const JOB_TOKEN = 'poll-test-token';
        const FOLDER_DISPLAY_NAME = 'Polling Test Folder';

        beforeAll(() => {
            jest.unmock('../zipManager'); // Ensure we get the real pollZipStatus
            actualPollZipStatus = require('../zipManager').pollZipStatus;
        });

        afterAll(() => {
            // Re-mock for other tests if necessary, or ensure tests are isolated.
            // For now, assuming this is the last describe block or other describe blocks re-mock/re-require.
            // The top-level mock of pollZipStatus will effectively be restored for subsequent test files
            // due to Jest's module caching and mock hoisting, but within this file, for tests after this block,
            // it would remain unmocked unless explicitly re-mocked.
        });

        beforeEach(() => {
            // Ensure the state reflects no current polling task initially for most tests
            stateModuleInstance.__internalState.zipPollingIntervalId = null;
            stateModuleInstance.__internalState.currentZipJobToken = null; 
            // Set a default active job for polling tests, can be overridden
            setActiveZipJob(JOB_TOKEN, 'some/path', FOLDER_DISPLAY_NAME);
        });

        test('should poll for processing status and update UI', async () => {
            stateModuleInstance.__internalState.currentZipJobToken = JOB_TOKEN; // Explicitly set for this test
            fetchDataApi.mockResolvedValueOnce({ // Initial call
                status: 'success',
                data: { job_info: { status: 'processing', total_files: 10, processed_files: 3 } }
            });

            await actualPollZipStatus(JOB_TOKEN, FOLDER_DISPLAY_NAME);

            expect(fetchDataApi).toHaveBeenCalledWith('get_zip_status', { token: JOB_TOKEN });
            expect(updateZipProgressBar).toHaveBeenCalledWith(
                { status: 'processing', total_files: 10, processed_files: 3 }, 
                FOLDER_DISPLAY_NAME
            );
            expect(setInterval).toHaveBeenCalledWith(expect.any(Function), 2000);
            expect(stateModuleInstance.setZipPollingIntervalId).toHaveBeenCalledWith(expect.any(Number));

            // Simulate next poll
            fetchDataApi.mockResolvedValueOnce({ // Second call
                status: 'success',
                data: { job_info: { status: 'processing', total_files: 10, processed_files: 5 } }
            });
            jest.advanceTimersByTime(2000);
            await Promise.resolve(); // Allow promises in interval callback to resolve

            expect(fetchDataApi).toHaveBeenCalledTimes(2);
            expect(updateZipProgressBar).toHaveBeenCalledWith(
                { status: 'processing', total_files: 10, processed_files: 5 }, 
                FOLDER_DISPLAY_NAME
            );
            expect(showModalWithMessage).not.toHaveBeenCalled();
            expect(clearActiveZipJob).not.toHaveBeenCalled();
        });

        test('should transition to completed status and show success modal', async () => {
            stateModuleInstance.__internalState.currentZipJobToken = JOB_TOKEN;
            fetchDataApi.mockResolvedValueOnce({ // Initial call: processing
                status: 'success',
                data: { job_info: { status: 'processing', total_files: 10, processed_files: 8 } }
            }).mockResolvedValueOnce({ // Second call: completed
                status: 'success',
                data: { job_info: { status: 'completed', zip_filesize: 500000, source_path: 'test/path' } }
            });

            await actualPollZipStatus(JOB_TOKEN, FOLDER_DISPLAY_NAME);
            expect(updateZipProgressBar).toHaveBeenCalledTimes(1); // For processing

            jest.advanceTimersByTime(2000);
            await Promise.resolve(); // Allow promises in interval callback to resolve

            expect(fetchDataApi).toHaveBeenCalledTimes(2);
            expect(updateZipProgressBar).toHaveBeenCalledTimes(2); // For completed
            expect(updateZipProgressBar).toHaveBeenLastCalledWith(
                expect.objectContaining({ status: 'completed' }), 
                FOLDER_DISPLAY_NAME
            );
            expect(closePhotoSwipeIfActive).toHaveBeenCalled();
            expect(clearActiveZipJob).toHaveBeenCalled();
            expect(showModalWithMessage).toHaveBeenCalledWith(
                'Tạo ZIP hoàn thành!',
                expect.stringContaining(`<strong>${FOLDER_DISPLAY_NAME}</strong>`), 
                false,
                true,
                false,
                null,
                'Đóng'
            );
            expect(showModalWithMessage.mock.calls[0][1]).toContain(`href="${API_BASE_URL}?action=download_final_zip&token=${JOB_TOKEN}"`);
            expect(setTimeout).toHaveBeenCalledWith(hideZipProgressBar, 500);
            // Check clearInterval was called (indirectly by clearActiveZipJob)
            expect(global.clearInterval).toHaveBeenCalledWith(stateModuleInstance.zipPollingIntervalId); 
        });

        test('should transition to failed status and show error modal', async () => {
            stateModuleInstance.__internalState.currentZipJobToken = JOB_TOKEN;
            fetchDataApi.mockResolvedValueOnce({ // Initial call: processing
                status: 'success',
                data: { job_info: { status: 'processing', total_files: 5, processed_files: 1 } }
            }).mockResolvedValueOnce({ // Second call: failed
                status: 'success',
                data: { job_info: { status: 'failed', error_message: 'Zip creation exploded' } }
            });

            await actualPollZipStatus(JOB_TOKEN, FOLDER_DISPLAY_NAME);
            jest.advanceTimersByTime(2000);
            await Promise.resolve();

            expect(updateZipProgressBar).toHaveBeenLastCalledWith(
                expect.objectContaining({ status: 'failed' }),
                FOLDER_DISPLAY_NAME
            );
            expect(clearActiveZipJob).toHaveBeenCalled();
            expect(showModalWithMessage).toHaveBeenCalledWith(
                'Tạo ZIP thất bại',
                expect.stringContaining('Zip creation exploded'),
                true
            );
            expect(setTimeout).toHaveBeenCalledWith(hideZipProgressBar, 3000);
        });

        test('should handle job not_found status from API', async () => {
            stateModuleInstance.__internalState.currentZipJobToken = JOB_TOKEN;
            fetchDataApi.mockResolvedValueOnce({
                status: 'success',
                data: { not_found: true }
            });

            await actualPollZipStatus(JOB_TOKEN, FOLDER_DISPLAY_NAME);
            await Promise.resolve(); // Allow microtasks to complete

            expect(clearActiveZipJob).toHaveBeenCalled();
            expect(hideZipProgressBar).toHaveBeenCalled();
            expect(showModalWithMessage).toHaveBeenCalledWith(
                'Lỗi theo dõi ZIP',
                expect.stringContaining('Không tìm thấy thông tin cho yêu cầu tạo ZIP'),
                true
            );
            expect(global.clearInterval).not.toHaveBeenCalled(); // Interval not set if first fetch fails like this
        });
        
        test('should continue polling if get_zip_status API returns an error', async () => {
            stateModuleInstance.__internalState.currentZipJobToken = JOB_TOKEN;
            fetchDataApi.mockResolvedValueOnce({ status: 'error', message: 'API is temporarily down' });
            
            await actualPollZipStatus(JOB_TOKEN, FOLDER_DISPLAY_NAME);
            await Promise.resolve(); // For initial fetch

            expect(fetchDataApi).toHaveBeenCalledTimes(1);
            expect(updateZipProgressBar).not.toHaveBeenCalled(); // No valid job_info to update with
            expect(setInterval).toHaveBeenCalledTimes(1); // Interval should still be set
            
            // Simulate next poll attempt
            fetchDataApi.mockResolvedValueOnce({ status: 'error', message: 'API still down' });
            jest.advanceTimersByTime(2000);
            await Promise.resolve(); // For second fetch within interval
            
            expect(fetchDataApi).toHaveBeenCalledTimes(2);
            expect(showModalWithMessage).not.toHaveBeenCalled(); // No final status
            expect(clearActiveZipJob).not.toHaveBeenCalled(); // Job not completed or failed definitively
        });

        test('should stop polling if active job token changes', async () => {
            const oldJobToken = 'old-job-token';
            stateModuleInstance.__internalState.currentZipJobToken = oldJobToken; 
            
            fetchDataApi.mockResolvedValue({ // Keep returning processing for old token
                status: 'success',
                data: { job_info: { status: 'processing', total_files: 10, processed_files: 1 } }
            });

            await actualPollZipStatus(oldJobToken, FOLDER_DISPLAY_NAME);
            const oldIntervalId = stateModuleInstance.zipPollingIntervalId;
            expect(fetchDataApi).toHaveBeenCalledWith('get_zip_status', { token: oldJobToken });
            fetchDataApi.mockClear();

            // Advance timer for the first poll interval
            jest.advanceTimersByTime(2000);
            await Promise.resolve();
            expect(fetchDataApi).toHaveBeenCalledWith('get_zip_status', { token: oldJobToken });
            fetchDataApi.mockClear();

            // Change active job token in state
            stateModuleInstance.__internalState.currentZipJobToken = 'new-job-token-different';

            // Advance timer again
            jest.advanceTimersByTime(2000);
            await Promise.resolve();

            expect(fetchDataApi).not.toHaveBeenCalled(); // Should not have polled for oldJobToken again
            expect(global.clearInterval).toHaveBeenCalledWith(oldIntervalId);
            expect(stateModuleInstance.setZipPollingIntervalId).toHaveBeenLastCalledWith(null); // Due to mismatch
        });

        test('should clean up and not start polling if no jobToken provided', async () => {
            await actualPollZipStatus(null, FOLDER_DISPLAY_NAME);
            
            expect(hideZipProgressBar).toHaveBeenCalled();
            expect(clearActiveZipJob).toHaveBeenCalled();
            expect(fetchDataApi).not.toHaveBeenCalled();
            expect(setInterval).not.toHaveBeenCalled();
        });

        test('should clear an existing interval before starting a new one', async () => {
            stateModuleInstance.__internalState.currentZipJobToken = JOB_TOKEN;
            const preExistingIntervalId = 98765;
            stateModuleInstance.__internalState.zipPollingIntervalId = preExistingIntervalId;
            
            fetchDataApi.mockResolvedValueOnce({
                status: 'success',
                data: { job_info: { status: 'pending' } }
            });

            await actualPollZipStatus(JOB_TOKEN, FOLDER_DISPLAY_NAME);

            expect(global.clearInterval).toHaveBeenCalledWith(preExistingIntervalId);
            expect(stateModuleInstance.setZipPollingIntervalId).toHaveBeenCalledWith(null); // Cleared old
            expect(setInterval).toHaveBeenCalledWith(expect.any(Function), 2000); // New one set
            expect(stateModuleInstance.setZipPollingIntervalId).toHaveBeenLastCalledWith(expect.any(Number)); // New ID stored
        });

    });

}); 