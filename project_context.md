# Project Context: Simple PHP Photo Gallery

## 1. Mục tiêu Dự án

Xây dựng một ứng dụng web thư viện ảnh đơn giản bằng PHP, hỗ trợ nhiều nguồn ảnh, bảo vệ thư mục bằng mật khẩu, xem thống kê và quản lý qua trang admin.

*   **Ưu tiên thiết kế Mobile-First:** Giao diện người dùng sẽ được ưu tiên thiết kế và tối ưu hóa cho trải nghiệm tốt nhất trên các thiết bị di động trước, sau đó mới mở rộng cho màn hình lớn hơn.

## 2. Công nghệ chính

*   **Backend:** PHP (>= 7.4)
*   **Database:** SQLite (lưu mật khẩu thư mục và thống kê)
*   **Frontend:** JavaScript thuần (ES Modules), CSS
*   **Thư viện:** PhotoSwipe 5 (xem ảnh)
*   **Server:** Web server hỗ trợ PHP (ví dụ: XAMPP, Apache, Nginx)
*   **PHP Extensions:** pdo_sqlite, gd, zip, mbstring, fileinfo

## 3. Thành phần & Tệp quan trọng

*   **User Frontend:**
    *   `index.php`: Giao diện chính (đã đổi từ .html để đọc config).
    *   `js/app.js`: Xử lý logic frontend.
    *   `css/style.css`: Định dạng giao diện (bao gồm cả các class `.modal-overlay`, `.modal-box` cho các lớp phủ).
*   **Admin:**
    *   `login.php`: Trang đăng nhập admin (đọc username/hash từ `config.php`).
    *   `admin.php`: Giao diện trang quản trị.
    *   `js/admin.js`: Xử lý logic admin.
*   **Backend API:**
    *   `api.php`: Xử lý logic chính (sử dụng các cài đặt từ `config.php` như giới hạn ZIP, phân trang).
    *   **Hàm helper quan trọng trong `api.php`:**
        *   `validate_source_and_path()`: Xác thực đường dẫn thư mục có tiền tố nguồn, chống path traversal.
        *   `validate_source_and_file_path()`: Xác thực đường dẫn tệp có tiền tố nguồn.
        *   `check_folder_access()`: Kiểm tra quyền truy cập thư mục (dựa trên DB/Session).
        *   `create_thumbnail()`: Tạo ảnh thumbnail bằng GD.
*   **Cấu hình & Dữ liệu:**
    *   `config.php`: **File cấu hình trung tâm.** Chứa đường dẫn DB, thông tin admin, nguồn ảnh, cài đặt cache, giới hạn API, cài đặt log, tiêu đề ứng dụng.
    *   `db_connect.php`: **Quan trọng.**
        *   `require` file `config.php`.
        *   Kết nối PDO đến SQLite (dựa trên `config.php`).
        *   Xác thực các nguồn ảnh từ `config.php` và định nghĩa hằng số `IMAGE_SOURCES` với các nguồn hợp lệ.
        *   Định nghĩa các hằng số `CACHE_THUMB_ROOT`, `ALLOWED_EXTENSIONS`, `THUMBNAIL_SIZES` (dựa trên `config.php`).
        *   Tự động tạo bảng DB nếu chưa có.
    *   `database.sqlite`: Tệp cơ sở dữ liệu.
    *   `cache/thumbnails/`: Thư mục chứa ảnh thumbnail đã cache.
    *   `images/`: Thư mục nguồn ảnh mặc định (tham chiếu từ `config.php`).
    *   `logs/`: Chứa các tệp log.
*   **Cron/Scheduled Tasks:**
    *   `cron_cache_manager.php`: Xóa thumbnail cache cũ.
    *   `cron_log_cleaner.php`: Xoay vòng và xóa file log cũ (đọc cấu hình từ `config.php`).
    *   `run_cache_cleanup.bat`: File batch để chạy các script cron.

## 4. Luồng hoạt động & Khái niệm chính

*   **Đa nguồn ảnh:** Cấu hình trong `db_connect.php`, cho phép lấy ảnh từ nhiều thư mục gốc khác nhau.
*   **Đường dẫn có tiền tố nguồn:** Định dạng `source_key/relative/path/to/item` (ví dụ: `main/album1`, `extra_drive/photos/2024/img.jpg`). Được sử dụng làm định danh trong API, DB, và điều hướng URL frontend (`#?folder=...`).
*   **Xác thực đường dẫn:** `api.php` sử dụng `validate_source_and_path` / `validate_source_and_file_path` để đảm bảo mọi truy cập tệp/thư mục đều hợp lệ và nằm trong nguồn được phép.
*   **Bảo vệ thư mục:** Mật khẩu được hash và lưu trong `folder_passwords` (key là đường dẫn có tiền tố). `check_folder_access` kiểm tra session hoặc DB. `js/app.js` hiển thị prompt và gọi action `authenticate` để xác thực.
*   **Thumbnail:** Được tạo "on-the-fly" bởi action `get_thumbnail` nếu chưa có trong cache. Cache được lưu trong `cache/thumbnails/<size>/<md5_hash_of_source_prefixed_path>.jpg`.
*   **Quản trị:** Yêu cầu đăng nhập (`login.php`). Trang admin (`admin.php` + `js/admin.js`) cho phép quản lý mật khẩu thư mục và xem thống kê lượt xem/tải.

## 5. Trạng thái hiện tại & Ghi chú

*   **Dọn dẹp code & Cấu trúc:**
    *   Đã xóa các action API cũ/sai.
    *   Đã tập trung các cài đặt vào `config.php`.
    *   Đã refactor CSS cho modal/overlay sử dụng class chung `.modal-overlay` và `.modal-box`.
*   **Sửa lỗi & Cải thiện UX:**
    *   Đã sửa lỗi logic kiểm tra thành công trong `js/app.js` khi xác thực mật khẩu thư mục (sử dụng `authenticate` và kiểm tra `result.data.success`).
    *   Đã cập nhật API (`check_folder_access`, `list_files`) và Frontend (`js/app.js`) để hiển thị biểu tượng khóa đóng 🔒 (cần mật khẩu) hoặc khóa mở 🔓 (đã xác thực trong session) cho các thư mục được bảo vệ.
    *   Đã sửa logic điều hướng để chỉ yêu cầu mật khẩu khi cần thiết.
    *   Đã ngăn chặn trình duyệt tự động điền mật khẩu vào ô prompt.
    *   Đã tăng độ mờ nền của ô prompt mật khẩu.
    *   Đã thêm phản hồi tiến trình tải ZIP và hiệu ứng làm mờ nền cho các lớp phủ.
*   **Vấn đề bảo mật:** Mật khẩu admin (`admin_password_hash`) đã được chuyển vào `config.php`. Cần đảm bảo file này không bị đưa lên Git repository công khai nếu dự án được chia sẻ.
*   **Môi trường:** User đang phát triển trên máy local (Windows/XAMPP), có thể có sự khác biệt về đường dẫn `IMAGE_SOURCES` so với môi trường production. File `db_connect.php` được thiết kế để xử lý việc này (bỏ qua nguồn không hợp lệ khi chạy).
*   **Cần kiểm thử:** Code có vẻ hoạt động dựa trên phân tích, nhưng cần kiểm thử thực tế các chức năng (duyệt thư mục, xem ảnh, đặt/xóa mật khẩu, tải về, admin...). 