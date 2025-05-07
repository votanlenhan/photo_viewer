import { test, expect } from '@playwright/test';

const baseUrl = 'http://localhost';
const adminUsername = 'admin';
const adminPassword = '@Floha123';

// --- Constants for folder names (sử dụng tên thực tế từ images/)
// const SAMPLE_FOLDER_NAME_1 = '9B Phan Chu Trinh'; // Sẽ được thay thế/không dùng trực tiếp trong test này nữa
// const SAMPLE_FOLDER_PATH_1 = `main/${SAMPLE_FOLDER_NAME_1}`;

// Xác định các hằng số cho cấu trúc thư mục bạn đang kiểm thử
const TEN_THU_MUC_CHA = '9B Phan Chu Trinh'; // Thư mục cha
const DUONG_DAN_THU_MUC_CHA = `main/${TEN_THU_MUC_CHA}`;

const TEN_THU_MUC_CON_CO_ANH = 'May 2'; // Thư mục con dự kiến chứa ảnh
const DUONG_DAN_THU_MUC_CON_CO_ANH = `${DUONG_DAN_THU_MUC_CHA}/${TEN_THU_MUC_CON_CO_ANH}`;

test.describe('Admin Login', () => {
  test('should allow admin to login successfully', async ({ page }) => {
    await page.goto(`${baseUrl}/login.php`);
    await page.fill('input[name="username"]', adminUsername);
    await page.fill('input[name="password"]', adminPassword);
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(`${baseUrl}/admin.php`);
    await expect(page.locator('h1')).toContainText('Trang Quản Trị');
  });

  test('should show error on failed login', async ({ page }) => {
    await page.goto(`${baseUrl}/login.php`);
    await page.fill('input[name="username"]', adminUsername);
    await page.fill('input[name="password"]', 'wrongpassword');
    await page.click('button[type="submit"]');
    const errorMessage = page.locator('p.error');
    await expect(errorMessage).toBeVisible();
    await expect(errorMessage).toContainText('Tên đăng nhập hoặc mật khẩu không đúng.');
  });
});

// --- Public Gallery Tests ---
test.describe('Public Gallery', () => {
  test('should load homepage and display top-level folders', async ({ page }) => {
    await page.goto(baseUrl); 
    await expect(page).toHaveTitle(/Thư viện Ảnh - Guustudio/);

    const directoryList = page.locator('ul#directory-list');
    await expect(directoryList.locator('li').first()).toBeVisible({ timeout: 20000 });

    // Logging để debug
    const listHTML = await directoryList.innerHTML();
    console.log('--- DEBUG: directory-list HTML ---');
    console.log(listHTML);
    const itemCount = await directoryList.locator('li a').count();
    console.log(`--- DEBUG: Found ${itemCount} folder links in ul#directory-list ---`);

    const folderItemByDataDir = directoryList.locator(`li a[data-dir="${DUONG_DAN_THU_MUC_CHA}"]`);
    const isVisibleByDataDir = await folderItemByDataDir.isVisible();
    console.log(`--- DEBUG: Folder '${TEN_THU_MUC_CHA}' visible by data-dir ('${DUONG_DAN_THU_MUC_CHA}'): ${isVisibleByDataDir} ---`);

    if (isVisibleByDataDir) {
        await expect(folderItemByDataDir).toBeVisible();
        console.log('--- INFO: Located folder using data-dir attribute. ---');
    } else {
        console.log('--- WARNING: Could not locate folder by data-dir, falling back to text search... ---');
        const folderItemByText = directoryList.locator(`li a:has(span.folder-name:text-is("${TEN_THU_MUC_CHA}"))`);
        await expect(folderItemByText).toBeVisible();
    }
  });

  test('should navigate into a folder and display thumbnails', async ({ page }) => {
    await page.goto(baseUrl);
    const danhSachThuMucCha = page.locator('ul#directory-list');
    await expect(danhSachThuMucCha.locator('li').first()).toBeVisible({ timeout: 20000 });

    let lienKetThuMucCha = danhSachThuMucCha.locator(`li a[data-dir="${DUONG_DAN_THU_MUC_CHA}"]`);
    if (!await lienKetThuMucCha.isVisible({timeout: 5000})) {
        console.log(`--- NAV WARN: Could not find top-level folder by data-dir '${DUONG_DAN_THU_MUC_CHA}', trying text '${TEN_THU_MUC_CHA}'... ---`);
        lienKetThuMucCha = danhSachThuMucCha.locator(`li a:has(span.folder-name:text-is("${TEN_THU_MUC_CHA}"))`);
    }
    await expect(lienKetThuMucCha, `Link for top-level folder '${TEN_THU_MUC_CHA}' not found.`).toBeVisible();
    await lienKetThuMucCha.click();

    const phanHashMongDoiCuaThuMucCha = encodeURIComponent(DUONG_DAN_THU_MUC_CHA);
    await expect(page, `URL should update to top-level folder '${DUONG_DAN_THU_MUC_CHA}'.`).toHaveURL(new RegExp(`${baseUrl}/#\\?folder=${phanHashMongDoiCuaThuMucCha}`), { timeout: 10000 });
    await expect(page.locator('#current-directory-name'), `Header for top-level folder '${TEN_THU_MUC_CHA}' should be correct.`).toHaveText(`Album: ${TEN_THU_MUC_CHA}`, { timeout: 10000 });

    // Navigate into subfolder
    const encodedSubFolderPath = encodeURIComponent(DUONG_DAN_THU_MUC_CON_CO_ANH);
    const subFolderLinkLocator = page.locator(`#image-grid ul.subfolder-list li a[href="#?folder=${encodedSubFolderPath}"]`);
    console.log(`--- INFO: Attempting to find and click sub-folder link with selector: #image-grid ul.subfolder-list li a[href="#?folder=${encodedSubFolderPath}"] ---`);
    
    try {
        await expect(subFolderLinkLocator, `Link for subfolder '${TEN_THU_MUC_CON_CO_ANH}' not visible.`).toBeVisible({ timeout: 20000 });
        await subFolderLinkLocator.click();
    } catch (e) {
        console.error(`Timeout or error finding/clicking subfolder link '${TEN_THU_MUC_CON_CO_ANH}'. Page content at failure:`);
        console.log(await page.content());
        throw e;
    }
    
    // Verify subfolder content
    const phanHashMongDoiCuaThuMucCon = encodeURIComponent(DUONG_DAN_THU_MUC_CON_CO_ANH);
    await expect(page, `URL should update to sub-folder '${DUONG_DAN_THU_MUC_CON_CO_ANH}'.`).toHaveURL(new RegExp(`${baseUrl}/#\\?folder=${phanHashMongDoiCuaThuMucCon}`), { timeout: 10000 });
    await expect(page.locator('#current-directory-name'), `Header for sub-folder '${TEN_THU_MUC_CON_CO_ANH}' should be correct.`).toHaveText(`Album: ${TEN_THU_MUC_CON_CO_ANH}`, { timeout: 10000 });

    const imageGrid = page.locator('#image-grid');
    try {
        await expect(imageGrid, "Image grid should be visible in sub-folder.").toBeVisible({ timeout: 20000 });

        // Explicitly wait for at least one image item with an img to be rendered
        const firstImageContainer = imageGrid.locator('div.image-item').first();
        await expect(firstImageContainer, "At least one 'div.image-item' container should be visible.").toBeVisible({ timeout: 25000 });

        const firstThumbnail = firstImageContainer.locator('img');
        await expect(firstThumbnail, "First image thumbnail in grid should be visible.").toBeVisible({ timeout: 20000 });
    } catch (e) {
        console.error(`Error expecting image grid or thumbnail in subfolder '${TEN_THU_MUC_CON_CO_ANH}'. Page content at failure:`);
        console.log(await page.content());
        throw e;
    }
  });

  test('should open image in PhotoSwipe', async ({ page }) => {
    await page.goto(baseUrl);
    const danhSachThuMucCha_psw = page.locator('ul#directory-list');
    await expect(danhSachThuMucCha_psw.locator('li').first()).toBeVisible({ timeout: 20000 });

    let lienKetThuMucCha_psw = danhSachThuMucCha_psw.locator(`li a[data-dir="${DUONG_DAN_THU_MUC_CHA}"]`);
    if (!await lienKetThuMucCha_psw.isVisible({timeout: 5000})) {
        console.log(`--- PSW WARN: Could not find top-level folder by data-dir '${DUONG_DAN_THU_MUC_CHA}', trying text for PhotoSwipe... ---`);
        lienKetThuMucCha_psw = danhSachThuMucCha_psw.locator(`li a:has(span.folder-name:text-is("${TEN_THU_MUC_CHA}"))`);
    }
    await expect(lienKetThuMucCha_psw, `Link for top-level folder '${TEN_THU_MUC_CHA}' not found for PhotoSwipe.`).toBeVisible();
    await lienKetThuMucCha_psw.click();
    
    const phanHashMongDoiCuaThuMucCha_photoswipe = encodeURIComponent(DUONG_DAN_THU_MUC_CHA);
    await expect(page, `URL should update to top-level folder '${DUONG_DAN_THU_MUC_CHA}' for PhotoSwipe.`).toHaveURL(new RegExp(`${baseUrl}/#\\?folder=${phanHashMongDoiCuaThuMucCha_photoswipe}`), { timeout: 10000 });
    await expect(page.locator('#current-directory-name'), `Header for top-level folder '${TEN_THU_MUC_CHA}' should be correct for PhotoSwipe.`).toHaveText(`Album: ${TEN_THU_MUC_CHA}`, { timeout: 10000 });

    // Navigate into subfolder for PhotoSwipe
    const encodedSubFolderPathForPSW = encodeURIComponent(DUONG_DAN_THU_MUC_CON_CO_ANH);
    const subFolderLinkLocatorForPSW = page.locator(`#image-grid ul.subfolder-list li a[href="#?folder=${encodedSubFolderPathForPSW}"]`);
    console.log(`--- INFO (PSW): Attempting to find and click sub-folder link with selector: #image-grid ul.subfolder-list li a[href="#?folder=${encodedSubFolderPathForPSW}"] ---`);

    try {
        await expect(subFolderLinkLocatorForPSW, `Link for subfolder '${TEN_THU_MUC_CON_CO_ANH}' for PSW not visible.`).toBeVisible({ timeout: 20000 });
        await subFolderLinkLocatorForPSW.click();
    } catch (e) {
        console.error(`Timeout or error finding/clicking subfolder link for PSW: '${TEN_THU_MUC_CON_CO_ANH}'. Page content at failure:`);
        console.log(await page.content());
        throw e;
    }

    // Verify subfolder content for PhotoSwipe
    const phanHashMongDoiCuaThuMucCon_psw = encodeURIComponent(DUONG_DAN_THU_MUC_CON_CO_ANH);
    await expect(page, `URL should update to sub-folder '${DUONG_DAN_THU_MUC_CON_CO_ANH}' for PhotoSwipe navigation.`).toHaveURL(new RegExp(`${baseUrl}/#\\?folder=${phanHashMongDoiCuaThuMucCon_psw}`), { timeout: 10000 });
    await expect(page.locator('#current-directory-name'), `Header for sub-folder '${TEN_THU_MUC_CON_CO_ANH}' should be correct for PhotoSwipe.`).toHaveText(`Album: ${TEN_THU_MUC_CON_CO_ANH}`,{ timeout: 10000 });
    
    const imageGrid_psw = page.locator('#image-grid');
    let firstImageItem; // Renamed for clarity, was firstImageContainer_psw
    let firstImageTrigger;
    try {
        await expect(imageGrid_psw, "Image grid should be visible in sub-folder for PhotoSwipe.").toBeVisible({ timeout: 20000 });

        // Explicitly wait for at least one image item to be rendered in the grid
        firstImageItem = imageGrid_psw.locator('div.image-item').first();
        await expect(firstImageItem, "At least one 'div.image-item' should be visible in the grid.").toBeVisible({ timeout: 25000 });

        // Now that an image item is visible, find the trigger within it
        firstImageTrigger = firstImageItem.locator('a.photoswipe-trigger'); 
        await expect(firstImageTrigger, "First PhotoSwipe trigger in the first image item should be visible.").toBeVisible({timeout: 20000});

    } catch (e) {
        console.error(`Error expecting image grid, image item, or PhotoSwipe trigger in subfolder '${TEN_THU_MUC_CON_CO_ANH}' for PSW. Page content at failure:`);
        console.log(await page.content());
        throw e;
    }
    
    await firstImageTrigger.click();

    try {
        await expect(page.locator('.pswp')).toBeVisible({ timeout: 10000 });
        await expect(page.locator('.pswp__img').first()).toBeVisible({ timeout: 5000 }); 
        await expect(page.locator('.pswp__img').first()).toHaveJSProperty('complete', true, { timeout: 10000 });
    } catch (e) {
        console.error(`Error expecting PhotoSwipe UI elements. Page content at failure:`);
        console.log(await page.content());
        throw e;
    }
  });
});

// --- Admin Panel Tests (sau khi đăng nhập) ---
test.describe('Admin Panel Functionality', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(`${baseUrl}/login.php`);
    await page.fill('input[name="username"]', adminUsername);
    await page.fill('input[name="password"]', adminPassword);
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(`${baseUrl}/admin.php`);
  });

  test('should display list of folders in admin panel', async ({ page }) => {
    const folderTableBody = page.locator('tbody#folder-list-body');
    await expect(folderTableBody.locator('tr').first()).toBeVisible({ timeout: 10000 });
    const folderRowCell = folderTableBody.locator(`td[data-label="Tên thư mục"]:has-text("${TEN_THU_MUC_CHA}")`);
    await expect(folderRowCell).toBeVisible();
  });

  test('should allow setting a password for a folder', async ({ page }) => {
    console.log('TODO: Implement test for setting folder password.');
  });

  test('should allow removing a password from a folder', async ({ page }) => {
    console.log('TODO: Implement test for removing folder password.');
  });
});

// Thêm các test.describe khác cho các khu vực khác của ứng dụng tại đây
// Ví dụ:
// test.describe('Gallery View', () => {
//   // tests for gallery functionality
// }); 