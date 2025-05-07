import {
    currentZipJobToken, setCurrentZipJobToken,
    zipPollingIntervalId, setZipPollingIntervalId,
    zipProgressBarContainerEl, zipFolderNameEl, zipOverallProgressEl, zipProgressStatsTextEl
} from './state.js';
import { ACTIVE_ZIP_JOB_KEY, API_BASE_URL } from './config.js';
import { fetchDataApi } from './apiService.js';
import { showModalWithMessage } from './uiModal.js';
import { closePhotoSwipeIfActive } from './photoswipeHandler.js';

// DOM elements are now primarily read from state.js after being set by app.js
// No need for specific DOM element arguments in initializeZipManager if using state for them.
export function initializeZipManager() {
    // This function is called from app.js during initializeApp.
    // It now primarily serves as a confirmation that the module is loaded,
    // as DOM elements for the progress bar are set in app.js and stored in state.js.
    console.log("[zipManager.js] ZIP Manager Initialized by app.js.");
    // Any other specific initialization for zipManager itself can go here.
}

export function setActiveZipJob(jobToken, sourcePath, folderDisplayName) {
    setCurrentZipJobToken(jobToken);
    try {
        sessionStorage.setItem(ACTIVE_ZIP_JOB_KEY, JSON.stringify({ jobToken, sourcePath, folderDisplayName }));
    } catch (e) {
        console.warn("Could not save active ZIP job to sessionStorage", e);
    }
}

export function getActiveZipJob() {
    try {
        const jobData = sessionStorage.getItem(ACTIVE_ZIP_JOB_KEY);
        return jobData ? JSON.parse(jobData) : null;
    } catch (e) {
        console.warn("Could not retrieve active ZIP job from sessionStorage", e);
        return null;
    }
}

export function clearActiveZipJob() {
    setCurrentZipJobToken(null);
    try {
        sessionStorage.removeItem(ACTIVE_ZIP_JOB_KEY);
    } catch (e) {
        console.warn("Could not clear active ZIP job from sessionStorage", e);
    }
    if (zipPollingIntervalId) {
        clearInterval(zipPollingIntervalId);
        setZipPollingIntervalId(null);
    }
}

export function displayZipProgressBar(folderDisplayName, statusText = 'Đang khởi tạo...') {
    console.log('[zipManager.js] displayZipProgressBar called. Folder:', folderDisplayName, 'Status:', statusText);
    console.log('[zipManager.js] displayZipProgressBar: zipProgressBarContainerEl:', zipProgressBarContainerEl);
    if (!zipProgressBarContainerEl) {
        console.error('[zipManager.js] zipProgressBarContainerEl is null in displayZipProgressBar!');
        return;
    }
    if (zipFolderNameEl) zipFolderNameEl.textContent = folderDisplayName || '';
    if (zipProgressStatsTextEl) zipProgressStatsTextEl.textContent = statusText;
    if (zipOverallProgressEl) zipOverallProgressEl.value = 0;
    zipProgressBarContainerEl.style.display = 'flex'; 
}

export function updateZipProgressBar(jobData, folderDisplayNameFromJob) {
    console.log('[zipManager.js] updateZipProgressBar called. Job Data:', jobData, 'UI Name:', folderDisplayNameFromJob);
    console.log('[zipManager.js] updateZipProgressBar: Elements:', {
        zipProgressBarContainerEl,
        zipFolderNameEl,
        zipOverallProgressEl,
        zipProgressStatsTextEl
    });
    if (!zipProgressBarContainerEl || !jobData) {
        console.error('[zipManager.js] zipProgressBarContainerEl or jobData is null/undefined in updateZipProgressBar!');
        return;
    }

    const activeJob = getActiveZipJob();
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
        percent = zipOverallProgressEl ? zipOverallProgressEl.value : 0;
        statsText = 'Thất bại!';
        if (zipFolderNameEl) zipFolderNameEl.textContent = `Lỗi: ${folderName}`;
    }

    if (zipOverallProgressEl) zipOverallProgressEl.value = percent;
    if (zipProgressStatsTextEl) zipProgressStatsTextEl.textContent = statsText;

    if (zipProgressBarContainerEl.style.display !== 'flex') {
        zipProgressBarContainerEl.style.display = 'flex';
    }
}

export function hideZipProgressBar() {
    if (zipProgressBarContainerEl) {
        zipProgressBarContainerEl.style.display = 'none'; 
    }
}

export async function handleDownloadZipAction(folderPath, folderName) {
    if (!folderPath || !folderName) {
        showModalWithMessage('Lỗi yêu cầu ZIP', '<p>Đường dẫn hoặc tên thư mục bị thiếu.</p>', true);
        return;
    }
    if (zipPollingIntervalId) {
        clearInterval(zipPollingIntervalId);
        setZipPollingIntervalId(null);
    }
    
    displayZipProgressBar(folderName, 'Đang gửi yêu cầu...');

    const formData = new FormData();
    formData.append('path', folderPath);

    const result = await fetchDataApi('request_zip', {}, {
        method: 'POST',
        body: formData
    });

    if (result.status === 'success' && result.data && result.data.job_token) {
        const jobToken = result.data.job_token;
        setActiveZipJob(jobToken, folderPath, folderName);

        if (result.data.status === 'completed') {
            updateZipProgressBar(result.data, folderName);
            clearActiveZipJob();
            showModalWithMessage(
                'Tải ZIP hoàn thành (có sẵn)',
                `<p>File ZIP cho thư mục <strong>${folderName}</strong> đã được tạo trước đó và sẵn sàng để tải.</p><a href="${API_BASE_URL}?action=download_final_zip&token=${jobToken}" class="button download-all" download>Tải về ngay</a>`,
                false
            );
            setTimeout(hideZipProgressBar, 500);
        } else if (result.data.status === 'pending' || result.data.status === 'processing') {
            pollZipStatus(jobToken, folderName);
        } else { 
             pollZipStatus(jobToken, folderName);
        }
    } else {
        hideZipProgressBar();
        const errorMessage = result.message || result.data?.error || 'Không thể gửi yêu cầu tạo ZIP. Vui lòng thử lại.';
        showModalWithMessage('Lỗi yêu cầu ZIP', `<p>${errorMessage}</p>`, true);
        clearActiveZipJob();
    }
}

export async function pollZipStatus(jobToken, folderDisplayNameForUI) {
    console.log(`[zipManager.js] pollZipStatus called for token: ${jobToken}, UI Name: ${folderDisplayNameForUI}`);
    if (!jobToken) {
        console.warn("[zipManager.js] No jobToken provided for polling in pollZipStatus.");
        hideZipProgressBar();
        clearActiveZipJob();
        return;
    }

    if (zipPollingIntervalId) {
        clearInterval(zipPollingIntervalId);
        setZipPollingIntervalId(null);
    }
    
    const fetchAndUpdate = async () => {
        console.log(`[zipManager.js] pollZipStatus - fetchAndUpdate for token: ${jobToken}`);
        if (currentZipJobToken !== jobToken) {
            console.warn(`[zipManager.js] Polling for job ${jobToken} stopped as it's no longer the active job (current is ${currentZipJobToken}).`);
            clearInterval(zipPollingIntervalId);
            setZipPollingIntervalId(null);
            return;
        }
        const response = await fetchDataApi('get_zip_status', { token: jobToken });

        if (response.status === 'success' && response.data) {
            const jobInfo = response.data.job_info || response.data; 
            if (jobInfo && jobInfo.status) {
                updateZipProgressBar(jobInfo, folderDisplayNameForUI);
                if (jobInfo.status === 'completed') {
                    console.log(`[POLL] Job ${jobToken} completed.`);
                    closePhotoSwipeIfActive();
                    clearActiveZipJob(); 
                    showModalWithMessage(
                        'Tạo ZIP hoàn thành!',
                        `<p>File ZIP cho thư mục <strong>${folderDisplayNameForUI}</strong> đã sẵn sàng.</p>
                         <p>Kích thước: ${(jobInfo.zip_filesize / (1024*1024)).toFixed(2)} MB</p>
                         <p><a href="${API_BASE_URL}?action=download_final_zip&token=${jobToken}" class="button download-all-modal-button" download>Tải về ngay</a></p>`,
                        false,      // isError
                        true,       // isInfoOnly (để không blur body nếu logic đó còn dùng, và có thể dùng để style khác)
                        false,      // showCancelButton -> Sẽ không có nút "Hủy" riêng
                        null,       // cancelCallback
                        'Đóng'      // okButtonText -> Nút OK sẽ là "Đóng"
                    );
                    setTimeout(hideZipProgressBar, 500); 
                } else if (jobInfo.status === 'failed') {
                    clearActiveZipJob();
                    showModalWithMessage(
                        'Tạo ZIP thất bại',
                        `<p>Đã có lỗi xảy ra khi tạo ZIP cho thư mục <strong>${folderDisplayNameForUI}</strong>.</p><p><em>${jobInfo.error_message || 'Không có thông tin lỗi cụ thể.'}</em></p>`,
                        true
                    );
                    setTimeout(hideZipProgressBar, 3000);
                }
            } else if (response.data.not_found) { 
                clearActiveZipJob();
                hideZipProgressBar();
                showModalWithMessage('Lỗi theo dõi ZIP', `<p>Không tìm thấy thông tin cho yêu cầu tạo ZIP (có thể đã hết hạn hoặc bị hủy).</p>`, true);
            } else {
                console.error("[POLL] Valid job data / job_info not found in response.data:", response.data);
            }
        } else if (response.status === 'error') { 
             console.error("[POLL] API error for get_zip_status:", response.message);
        } else {
            console.error("[POLL] Unexpected response structure polling ZIP status:", response);
        }
    };
    fetchAndUpdate(); 
    setZipPollingIntervalId(setInterval(fetchAndUpdate, 2000));
} 