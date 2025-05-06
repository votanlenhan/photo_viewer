<?php
session_start(); // Luôn bắt đầu session

// --- KIỂM TRA ĐĂNG NHẬP ---
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); // Chưa đăng nhập, chuyển về trang login
    exit;
}

// --- XỬ LÝ ĐĂNG XUẤT ---
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();   // Xóa tất cả biến session
    session_destroy(); // Hủy session hoàn toàn
    header('Location: login.php'); // Chuyển về trang login
    exit;
}

// Lấy tên admin từ session để hiển thị (nếu có)
$admin_username = isset($_SESSION['admin_username']) ? htmlspecialchars($_SESSION['admin_username']) : 'Admin';

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Quản lý Thư mục Ảnh</title>
    <link rel="icon" type="image/png" href="theme/favicon.png"> <!-- Favicon -->
    <link rel="stylesheet" href="css/style.css"> 
</head>
<body>
    <div class="container admin-view">
        <div class="header">
            <h1>Trang Quản Trị</h1>
            <a href="admin.php?action=logout" class="button logout-link">Đăng xuất (<?php echo $admin_username; ?>)</a>
        </div>
        <p>Quản lý mật khẩu, xem lượt truy cập và lấy link chia sẻ cho các thư mục ảnh gốc.</p>

        <!-- Cache Count Display REMOVED -->
        <!-- 
        <div class="cache-stats-display">
            Tổng số ảnh cache đã tạo: <strong id="total-cache-count">...</strong>
        </div> 
        -->

        <!-- Moved Feedback Div Here -->
        <div id="admin-feedback" class="feedback-message" style="display: none;"></div> 

        <!-- Search Bar for Admin - Styled like homepage -->
        <div class="search-container admin-search">
             <input type="search" id="adminSearchInput" placeholder="Tìm thư mục..." aria-label="Tìm kiếm thư mục">
        </div>
        <!-- Optional prompt for admin search -->
        <p id="admin-search-prompt" class="search-prompt admin-prompt" style="display: none; font-size: 0.85em; margin-top: 8px;">
            Nhập tên thư mục để lọc danh sách.
        </p>

        <div id="admin-message" class="message" style="display: none;"></div>

        <table>
            <thead>
                <tr>
                    <th>Tên thư mục</th>
                    <th>Trạng thái</th>
                    <th>Lượt xem</th>
                    <th>Lượt tải ZIP</th>
                    <th>Link chia sẻ (Click để chọn)</th>
                    <th>Hành động Mật khẩu</th>
                    <th>Trạng thái Cache</th>
                    <th>Hành động Cache</th>
                </tr>
            </thead>
            <tbody id="folder-list-body">
                <tr><td colspan="8">Đang tải danh sách thư mục...</td></tr>
            </tbody>
        </table>

        <div id="admin-loading" class="loading-indicator" style="display: none;">Đang tải...</div>

    </div>

    <script type="module" src="js/admin.js"></script>
</body>
</html>