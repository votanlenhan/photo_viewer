import { test, expect } from '@playwright/test';

const baseUrl = 'http://localhost';
const adminUsername = 'admin';
const adminPassword = '@Floha123';

// --- Constants for folder names (sử dụng tên thực tế từ images/)
const SAMPLE_FOLDER_NAME_1 = '9B Phan Chu Trinh';
const SAMPLE_FOLDER_PATH_1 = `main/${SAMPLE_FOLDER_NAME_1}`; // Giả sử nguồn là 'main'

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
    await expect(page).toHaveTitle(/Thư viện Ảnh - Guustudio/); // Sửa lại cho đúng tên app title

    const directoryList = page.locator('ul#directory-list');
    // Chờ cho danh sách có ít nhất một mục LI được render
    await expect(directoryList.locator('li').first()).toBeVisible({ timeout: 20000 }); // Tăng timeout

    // Logging để debug
    const listHTML = await directoryList.innerHTML();
    console.log('--- DEBUG: directory-list HTML ---');
    console.log(listHTML);
    const itemCount = await directoryList.locator('li a').count();
    console.log(`--- DEBUG: Found ${itemCount} folder links in ul#directory-list ---`);

    // Thử tìm bằng data-dir trước, nếu có thể
    // Lưu ý: SAMPLE_FOLDER_PATH_1 cần khớp với giá trị trong data-dir (ví dụ: "main/9B Phan Chu Trinh")
    const folderItemByDataDir = directoryList.locator(`li a[data-dir="${SAMPLE_FOLDER_PATH_1}"]`);
    const isVisibleByDataDir = await folderItemByDataDir.isVisible();
    console.log(`--- DEBUG: Folder '${SAMPLE_FOLDER_NAME_1}' visible by data-dir ('${SAMPLE_FOLDER_PATH_1}'): ${isVisibleByDataDir} ---`);

    if (isVisibleByDataDir) { // Nếu tìm thấy bằng data-dir, dùng nó
        await expect(folderItemByDataDir).toBeVisible();
        console.log('--- INFO: Located folder using data-dir attribute. ---');
    } else { // Nếu không, thử lại bằng text (có thể dễ lỗi hơn)
        console.log('--- WARNING: Could not locate folder by data-dir, falling back to text search... ---');
        const folderItemByText = directoryList.locator(`li a:has(span.folder-name:text-is("${SAMPLE_FOLDER_NAME_1}"))`);
        await expect(folderItemByText).toBeVisible();
    }
  });

  test('should navigate into a folder and display thumbnails', async ({ page }) => {
    await page.goto(baseUrl);
    const directoryList = page.locator('ul#directory-list');
    await expect(directoryList.locator('li').first()).toBeVisible({ timeout: 20000 });
    let folderLink = directoryList.locator(`li a[data-dir="${SAMPLE_FOLDER_PATH_1}"]`);
    if (!await folderLink.isVisible()) {
        console.log('--- NAV WARN: Could not find folder by data-dir, trying text for navigation... ---');
        folderLink = directoryList.locator(`li a:has(span.folder-name:text-is("${SAMPLE_FOLDER_NAME_1}"))`);
    }
    await expect(folderLink).toBeVisible();
    await folderLink.click();

    const expectedHashPart = encodeURIComponent(SAMPLE_FOLDER_PATH_1); 
    await expect(page).toHaveURL(new RegExp(`${baseUrl}/#\\?folder=${expectedHashPart}`), { timeout: 10000 });
    await expect(page.locator('#current-directory-name')).toHaveText(`Album: ${SAMPLE_FOLDER_NAME_1}`, { timeout: 10000 });

    const imageGrid = page.locator('#image-grid');
    await expect(imageGrid.locator('div.image-item img').first()).toBeVisible({ timeout: 30000 });
  });

  test('should open image in PhotoSwipe', async ({ page }) => {
    await page.goto(baseUrl);
    const directoryList = page.locator('ul#directory-list');
    await expect(directoryList.locator('li').first()).toBeVisible({ timeout: 20000 });
    let folderLink = directoryList.locator(`li a[data-dir="${SAMPLE_FOLDER_PATH_1}"]`);
    if (!await folderLink.isVisible()) {
        console.log('--- PSW WARN: Could not find folder by data-dir, trying text for PhotoSwipe... ---');
        folderLink = directoryList.locator(`li a:has(span.folder-name:text-is("${SAMPLE_FOLDER_NAME_1}"))`);
    }
    await expect(folderLink).toBeVisible();
    await folderLink.click();
    
    const imageGrid = page.locator('#image-grid');
    await expect(imageGrid.locator('div.image-item img').first()).toBeVisible({ timeout: 30000 });
    const firstThumbnail = imageGrid.locator('div.image-item img').first();
    await firstThumbnail.click();

    await expect(page.locator('.pswp')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('.pswp__img').first()).toBeVisible(); 
    await expect(page.locator('.pswp__img').first()).toHaveJSProperty('complete', true);
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
    const folderRowCell = folderTableBody.locator(`td[data-label="Tên thư mục"]:has-text("${SAMPLE_FOLDER_NAME_1}")`);
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