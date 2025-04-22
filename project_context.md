# Project Context: Simple PHP Photo Gallery

## 1. Mục tiêu Dự án

Xây dựng một ứng dụng web thư viện ảnh đơn giản bằng PHP, hỗ trợ nhiều nguồn ảnh, bảo vệ thư mục bằng mật khẩu, xem thống kê và quản lý qua trang admin.

## 2. Công nghệ chính

*   **Backend:** PHP (>= 7.4)
*   **Database:** SQLite (lưu mật khẩu thư mục và thống kê)
*   **Frontend:** JavaScript thuần (ES Modules), CSS
*   **Thư viện:** PhotoSwipe 5 (xem ảnh)
*   **Server:** Web server hỗ trợ PHP (ví dụ: XAMPP, Apache, Nginx)
*   **PHP Extensions:** pdo_sqlite, gd, zip, mbstring, fileinfo

## 3. Thành phần & Tệp quan trọng

*   **User Frontend:**
    *   `index.html`: Giao diện chính.
    *   `js/app.js`: Xử lý logic frontend (gọi API, hiển thị, tìm kiếm, PhotoSwipe, prompt mật khẩu).
*   **Admin:**
    *   `login.php`: Trang đăng nhập admin (kiểm tra `ADMIN_USERNAME`, `ADMIN_PASSWORD_HASH`).
    *   `admin.php`: Giao diện trang quản trị.
    *   `js/admin.js`: Xử lý logic admin (gọi API, hiển thị bảng, đặt/xóa mật khẩu, sao chép link).
*   **Backend API:**
    *   `api.php`: Xử lý logic chính, bao gồm các action:
        *   `list_files`: Action **quan trọng**, xử lý cả việc liệt kê thư mục gốc (từ tất cả nguồn) và nội dung thư mục con (thư mục con + ảnh).
        *   `get_thumbnail`: Lấy/tạo ảnh thumbnail (cho ảnh hoặc thư mục).
        *   `get_image`: Lấy ảnh gốc.
        *   `download_zip`: Tải toàn bộ thư mục dưới dạng ZIP.
        *   `download_file`: Tải một tệp ảnh riêng lẻ.
        *   `authenticate`: Xác thực mật khẩu thư mục do người dùng nhập (được gọi bởi `js/app.js`).
        *   `admin_list_folders`: Lấy danh sách thư mục cho trang admin.
        *   `admin_set_password`: Đặt/cập nhật mật khẩu thư mục.
        *   `admin_remove_password`: Xóa mật khẩu thư mục.
    *   **Hàm helper quan trọng trong `api.php`:**
        *   `validate_source_and_path()`: Xác thực đường dẫn thư mục có tiền tố nguồn, chống path traversal.
        *   `validate_source_and_file_path()`: Xác thực đường dẫn tệp có tiền tố nguồn.
        *   `check_folder_access()`: Kiểm tra quyền truy cập thư mục (dựa trên DB/Session).
        *   `create_thumbnail()`: Tạo ảnh thumbnail bằng GD.
*   **Cấu hình & Dữ liệu:**
    *   `db_connect.php`: **Rất quan trọng.**
        *   Kết nối PDO đến SQLite.
        *   Định nghĩa hằng số `IMAGE_SOURCES`: Mảng chứa các nguồn ảnh (key là định danh, value chứa 'path' là đường dẫn **tuyệt đối**).
        *   Định nghĩa `CACHE_THUMB_ROOT`, `ALLOWED_EXTENSIONS`, `THUMBNAIL_SIZES`.
        *   Tự động tạo bảng `folder_passwords` và `folder_stats` nếu chưa có.
    *   `database.sqlite`: Tệp cơ sở dữ liệu. Key trong các bảng (`folder_name`) là đường dẫn **có tiền tố nguồn**.
    *   `cache/thumbnails/`: Thư mục chứa ảnh thumbnail đã cache.
    *   `images/`: Thư mục nguồn ảnh mặc định (key 'main').
    *   `logs/`: Chứa các tệp log.
*   **Cron/Scheduled Tasks:**
    *   `cron_cache_manager.php`: Xóa thumbnail cache cũ.
    *   `cron_log_cleaner.php`: Xoay vòng và xóa file log cũ.
    *   `run_cache_cleanup.bat`: File batch để chạy các script cron trên Windows (Task Scheduler).

## 4. Luồng hoạt động & Khái niệm chính

*   **Đa nguồn ảnh:** Cấu hình trong `db_connect.php`, cho phép lấy ảnh từ nhiều thư mục gốc khác nhau.
*   **Đường dẫn có tiền tố nguồn:** Định dạng `source_key/relative/path/to/item` (ví dụ: `main/album1`, `extra_drive/photos/2024/img.jpg`). Được sử dụng làm định danh trong API, DB, và điều hướng URL frontend (`#?folder=...`).
*   **Xác thực đường dẫn:** `api.php` sử dụng `validate_source_and_path` / `validate_source_and_file_path` để đảm bảo mọi truy cập tệp/thư mục đều hợp lệ và nằm trong nguồn được phép.
*   **Bảo vệ thư mục:** Mật khẩu được hash và lưu trong `folder_passwords` (key là đường dẫn có tiền tố). `check_folder_access` kiểm tra session hoặc DB. `js/app.js` hiển thị prompt và gọi action `authenticate` để xác thực.
*   **Thumbnail:** Được tạo "on-the-fly" bởi action `get_thumbnail` nếu chưa có trong cache. Cache được lưu trong `cache/thumbnails/<size>/<md5_hash_of_source_prefixed_path>.jpg`.
*   **Quản trị:** Yêu cầu đăng nhập (`login.php`). Trang admin (`admin.php` + `js/admin.js`) cho phép quản lý mật khẩu thư mục và xem thống kê lượt xem/tải.

## 5. Trạng thái hiện tại & Ghi chú

*   **Dọn dẹp code:** Đã xóa các action API cũ/sai (`list_sub_items`, `verify_password`) và hàm helper cũ (`sanitize_subdir`). `js/app.js` đã được cập nhật để gọi action `authenticate` chính xác.
*   **Vấn đề bảo mật:** Hash mật khẩu admin (`ADMIN_PASSWORD_HASH`) đang bị **hardcode** trong `login.php`. Đây là rủi ro bảo mật **cao**, cần được di chuyển ra file cấu hình riêng hoặc biến môi trường và **KHÔNG** commit vào Git.
*   **Môi trường:** User đang phát triển trên máy local (Windows/XAMPP), có thể có sự khác biệt về đường dẫn `IMAGE_SOURCES` so với môi trường production. File `db_connect.php` được thiết kế để xử lý việc này (bỏ qua nguồn không hợp lệ khi chạy).
*   **Cần kiểm thử:** Code có vẻ hoạt động dựa trên phân tích, nhưng cần kiểm thử thực tế các chức năng (duyệt thư mục, xem ảnh, đặt/xóa mật khẩu, tải về, admin...). 