# Bối cảnh Dự án: Thư viện Ảnh PHP Đơn giản

## 1. Mục tiêu Dự án

*   **Mục tiêu chính:** Xây dựng một ứng dụng web thư viện ảnh đơn giản, hiệu quả và hấp dẫn về mặt hình ảnh bằng PHP.
*   **Đối tượng người dùng:** Chủ yếu là khách hàng của Guustudio để xem và tải ảnh, có khả năng bảo vệ bằng mật khẩu cho các album cụ thể.
*   **Ưu tiên thiết kế:** Ưu tiên trải nghiệm Mobile-First, giao diện sạch sẽ, chủ đề tối lấy cảm hứng từ GitHub.

## 2. Công nghệ chính

*   **Backend:** PHP (>= 7.4)
*   **Database:** SQLite (lưu mật khẩu thư mục và thống kê)
*   **Frontend:** JavaScript thuần (ES Modules), CSS, HTML
*   **Thư viện JS:** PhotoSwipe 5 (xem ảnh)
*   **Server:** Web server hỗ trợ PHP (ví dụ: XAMPP, Apache, Nginx)
*   **PHP Extensions yêu cầu:** pdo_sqlite, gd, zip, mbstring, fileinfo

## 3. Cấu trúc Dự án & Tệp quan trọng

*   **Giao diện Người dùng (Frontend):**
    *   `index.php`: Trang chính, hiển thị danh sách thư mục hoặc ảnh.
    *   `js/app.js`: Xử lý logic phía client (tải dữ liệu, điều hướng, hiển thị modal, PhotoSwipe, tìm kiếm, v.v.).
    *   `css/style.css`: Định dạng giao diện, bao gồm các class chung cho modal (`.modal-overlay`, `.modal-box`).
*   **Quản trị (Admin):**
    *   `login.php`: Trang đăng nhập admin.
    *   `admin.php`: Trang quản lý mật khẩu thư mục và xem thống kê.
    *   `js/admin.js`: Logic phía client cho trang admin.
*   **API (Backend):**
    *   `api.php`: Điểm cuối xử lý các yêu cầu từ frontend (liệt kê file, xác thực, tạo ZIP, lấy ảnh/thumbnail, các action admin).
    *   **Hàm helper quan trọng:** `validate_source_and_path()`, `validate_source_and_file_path()`, `check_folder_access()`, `create_thumbnail()`.
*   **Cấu hình & Dữ liệu:**
    *   `config.php`: **File cấu hình trung tâm** (thông tin DB, admin, nguồn ảnh, cài đặt cache, giới hạn API, log, tiêu đề). **QUAN TRỌNG:** Không đưa file này lên repo công khai nếu chứa thông tin nhạy cảm.
    *   `db_connect.php`: **File thiết lập cốt lõi.** `require` file `config.php`, kết nối DB, xác thực và định nghĩa nguồn ảnh (`IMAGE_SOURCES`), định nghĩa hằng số cache/extensions, tự động tạo bảng DB.
    *   `database.sqlite`: Tệp cơ sở dữ liệu SQLite.
    *   `cache/thumbnails/`: Thư mục lưu trữ thumbnail đã tạo.
    *   `images/`: Thư mục nguồn ảnh mặc định (có thể thay đổi/thêm trong `config.php`).
    *   `logs/`: Thư mục chứa file log ứng dụng.
*   **Tác vụ nền (Cron/Scheduled Tasks):**
    *   `cron_cache_manager.php`: Dọn dẹp thumbnail cũ/mồ côi.
    *   `cron_log_cleaner.php`: Xoay vòng/xóa file log cũ.
    *   `run_cache_cleanup.bat`: Ví dụ file batch để chạy các script cron trên Windows.

## 4. Luồng hoạt động & Khái niệm chính

*   **Đa nguồn ảnh:** Cho phép định nghĩa nhiều thư mục gốc chứa ảnh trong `config.php`.
*   **Đường dẫn có tiền tố nguồn:** Định dạng `source_key/relative/path` (ví dụ: `main/album1`, `extra_drive/photos/img.jpg`) được dùng làm định danh nhất quán trong toàn bộ ứng dụng (API, DB, URL hash).
*   **Xác thực đường dẫn:** API luôn kiểm tra tính hợp lệ và giới hạn truy cập trong các nguồn được định nghĩa để chống path traversal.
*   **Bảo vệ thư mục:** Mật khẩu hash lưu trong DB. `check_folder_access` kiểm tra quyền dựa trên session/DB. Frontend hiển thị prompt khi cần.
*   **Thumbnail:** Mặc định tạo "on-the-fly" và cache lại. (Xem đề xuất tối ưu ở mục 6).
*   **Quản trị:** Truy cập trang admin sau khi đăng nhập để quản lý mật khẩu và xem thống kê cơ bản.

## 5. Tình trạng Hiện tại

*   Các chức năng cốt lõi (duyệt, xem ảnh, tìm kiếm, tải ZIP, bảo vệ mật khẩu) đã hoạt động.
*   Đã thực hiện nhiều cải tiến về cấu trúc code (tập trung cấu hình, refactor modal CSS) và sửa lỗi giao diện/logic (hiển thị icon khóa, logic prompt mật khẩu, căn chỉnh, v.v.).
*   Hiệu ứng làm mờ nền khi hiển thị modal đã được thêm.
*   Đã thử nghiệm và hoàn nguyên về font chữ hệ thống mặc định.

## 6. Các Cải tiến & Tối ưu Tiềm năng trong Tương lai

*   **Tối ưu Hiệu suất (Ưu tiên cao):**
    *   **Tạo Thumbnail trước:** Tạo sẵn thumbnail thay vì tạo động qua API.
    *   **Tối ưu Tạo ZIP:** Sử dụng background job hoặc streaming.
    *   **Tối ưu Liệt kê Ảnh (`list_files`):** Cache kích thước ảnh, cân nhắc phân trang phía server hiệu quả hơn.
*   **Cải thiện UX/UI:**
    *   **Hiệu ứng Skeleton Loading:** Thay thế text "Đang tải..." bằng hiệu ứng khung xương.
    *   **Cải thiện Tìm kiếm:** Thêm gợi ý (autocomplete), làm nổi bật kết quả.
    *   **Xử lý Lỗi Tốt hơn:** Hiển thị thông báo lỗi thân thiện hơn.
    *   **Tinh chỉnh Font chữ:** Xem xét lại font web nếu cần.
    *   **Kiểm tra Khả năng Tiếp cận (Accessibility - a11y):** Đảm bảo tuân thủ các tiêu chuẩn a11y.
*   **Chất lượng Mã nguồn & Khả năng Bảo trì:**
    *   **Biến CSS:** Sử dụng biến CSS cho màu sắc, font, khoảng cách.
    *   **Modular hóa Code:** Chia nhỏ file CSS/JS khi dự án lớn hơn.
    *   **Kiểm thử (Testing):** Thêm unit/integration test cho backend.
*   **Tính năng Mới Tiềm năng:**
    *   Sắp xếp/lọc album/ảnh.
    *   Chế độ xem danh sách.
    *   Chia sẻ/tải ảnh đơn lẻ.
    *   Hiển thị metadata EXIF.
    *   Mở rộng Trang Admin.

## 7. Ghi chú & Cân nhắc Chung

*   Tiếp tục tập trung vào nguyên tắc thiết kế **Mobile-First**.
*   Đảm bảo tính nhất quán giữa môi trường phát triển (dev) và sản xuất (prod), đặc biệt về cấu hình đường dẫn trong `config.php`.
*   Ưu tiên các **tối ưu về hiệu suất** vì chúng ảnh hưởng lớn đến trải nghiệm người dùng.
*   Cần **kiểm thử kỹ lưỡng** tất cả chức năng sau các thay đổi. 