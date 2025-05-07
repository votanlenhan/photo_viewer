import { generalModalOverlay } from './state.js';
import { fetchDataApi } from './apiService.js';

// Forward declaration for loadSubItems to break circular dependency if uiModal calls app.js function
// This is a common pattern. App.js will pass the actual loadSubItems function during initialization.
let appLoadSubItems = () => { console.error('loadSubItems function not initialized in uiModal'); };
export function initModalSystem(loadSubItemsFunc) {
    appLoadSubItems = loadSubItemsFunc;
}

// --- General Message Modal ---
export function showModalWithMessage(title, htmlContent, isError = false, isInfoOnly = false, showCancelButton = false, cancelCallback = null, okButtonText = 'Đóng') {
    if (!generalModalOverlay) { 
        console.error("CRITICAL: generalModalOverlay not initialized when showModalWithMessage was called. Modal cannot be shown.");
        alert(title + "\n" + htmlContent.replace(/<[^>]*>?/gm, '')); 
        return;
    }

    generalModalOverlay.innerHTML = `
        <div class="modal-box generic-message-box">
            <h3>${title}</h3>
            <div class="modal-content-area">${htmlContent}</div>
            <div class="prompt-actions">
                ${showCancelButton ? `<button id=\"modalCancelBtn\" class=\"button\" style=\"background:#6c757d;\">Hủy</button>` : ''}
                <button id="modalOkBtn" class="button ${isError ? 'error-button-style' : ''}">${okButtonText}</button>
            </div>
        </div>
    `;

    generalModalOverlay.classList.add('modal-visible');
    if (!isInfoOnly) { 
        document.body.classList.add('body-blur');
    }

    const okBtn = document.getElementById('modalOkBtn');
    if (okBtn) {
        okBtn.onclick = () => hideModalWithMessage();
    }

    const cancelBtn = document.getElementById('modalCancelBtn');
    if (cancelBtn && cancelCallback) {
        cancelBtn.onclick = () => {
            hideModalWithMessage();
            if (typeof cancelCallback === 'function') cancelCallback();
        };
    } else if (cancelBtn) {
        cancelBtn.onclick = () => hideModalWithMessage();
    }
    
    document.addEventListener('keydown', escapeGeneralModalListener);
}

export function hideModalWithMessage() {
    if (generalModalOverlay) {
        generalModalOverlay.classList.remove('modal-visible');
        generalModalOverlay.innerHTML = ''; 
    }
    document.body.classList.remove('body-blur');
    document.removeEventListener('keydown', escapeGeneralModalListener);
}

export const escapeGeneralModalListener = (e) => {
    if (e.key === 'Escape') {
        hideModalWithMessage();
    }
};


// --- Password Prompt Modal ---
// Listener needs to be a named function to be removable later by specific ID if overlayId is dynamic
// For now, assuming a single password prompt system, so one listener name is fine.
const mainPasswordPromptListener = (e) => {
    if (e.key === 'Escape') {
        hidePasswordPrompt(); // Assumes one global password prompt for now
    }
};

export function showPasswordPrompt(folderName) {
    if (!generalModalOverlay) { 
        console.error("CRITICAL: generalModalOverlay not found for password prompt.");
        return;
    }
    generalModalOverlay.innerHTML = `
        <div class="modal-box password-prompt-box" id="passwordPromptInstance">
            <h3>Nhập mật khẩu</h3>
            <p>Album "<strong>${folderName}</strong>" được bảo vệ. Vui lòng nhập mật khẩu:</p>
            <div id="passwordPromptError" class="error-message"></div>
            <input type="password" id="passwordPromptInput" placeholder="Mật khẩu..." autocomplete="new-password">
            <div class="prompt-actions">
                <button id="passwordPromptOk" class="button">Xác nhận</button>
                <button id="passwordPromptCancel" class="button" style="background:#6c757d;">Hủy</button>
            </div>
        </div>`;
    generalModalOverlay.classList.add('modal-visible');
    document.body.classList.add('body-blur');
    
    const input = document.getElementById('passwordPromptInput');
    const okBtn = document.getElementById('passwordPromptOk');
    const cancelBtn = document.getElementById('passwordPromptCancel');
    const errorEl = document.getElementById('passwordPromptError');
    
    if (!input || !okBtn || !cancelBtn || !errorEl) {
        console.error("Password prompt elements not found after render.");
        return;
    }
    input.focus();

    const handlePasswordSubmit = async () => {
        const password = input.value;
        if (!password) {
            errorEl.textContent = 'Vui lòng nhập mật khẩu.';
            input.focus();
            return;
        }
        errorEl.textContent = '';
        okBtn.disabled = true;
        cancelBtn.disabled = true;
        okBtn.textContent = 'Đang xác thực...';

        const formData = new FormData();
        formData.append('folder', folderName);
        formData.append('password', password);

        const responseData = await fetchDataApi('authenticate', {}, {
            method: 'POST',
            body: formData,
        });

        okBtn.disabled = false;
        cancelBtn.disabled = false;
        okBtn.textContent = 'Xác nhận';

        if (responseData.status === 'success' && responseData.data.success) {
            hidePasswordPrompt();
            appLoadSubItems(folderName); // Call the function passed from app.js
        } else {
            errorEl.textContent = responseData.message || 'Mật khẩu không đúng hoặc có lỗi xảy ra.';
            input.select();
            input.focus();
        }
    };

    okBtn.onclick = handlePasswordSubmit;
    input.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            handlePasswordSubmit();
        }
    });
    cancelBtn.onclick = () => hidePasswordPrompt();
    document.addEventListener('keydown', mainPasswordPromptListener);
}

export function hidePasswordPrompt() {
    if (generalModalOverlay) {
        generalModalOverlay.classList.remove('modal-visible');
        generalModalOverlay.innerHTML = ''; 
    }
    document.body.classList.remove('body-blur');
    document.removeEventListener('keydown', mainPasswordPromptListener);
} 