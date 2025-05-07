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
    *   `api.php`: **Điểm vào chính (Entry Point)** cho tất cả các yêu cầu API. Chỉ chứa logic `require` các file xử lý khác.
    *   `api/init.php`: Khởi tạo cấu hình lỗi, session, gọi `db_connect.php`, định nghĩa hằng số và biến API toàn cục.
    *   `api/helpers.php`: Chứa các hàm hỗ trợ chung (ví dụ: `json_response()`, `validate_source_and_path()`, `check_folder_access()`, `create_thumbnail()`, `find_first_image_in_source()`).
    *   `api/actions_public.php`: Xử lý các action công khai (ví dụ: `list_files`, `get_thumbnail`, `get_image`, `download_zip`, `authenticate`).
    *   `api/actions_admin.php`: Xử lý các action yêu cầu quyền admin (ví dụ: `admin_login`, `admin_logout`, `admin_list_folders`, `admin_set_password`, `admin_remove_password`).
*   **Cấu hình & Dữ liệu:**
    *   `config.php`: **File cấu hình trung tâm** (thông tin DB, admin, nguồn ảnh, cài đặt cache, giới hạn API, log, tiêu đề). **QUAN TRỌNG:** Không đưa file này lên repo công khai nếu chứa thông tin nhạy cảm.
    *   `db_connect.php`: **File thiết lập cốt lõi.** `require` file `config.php`, kết nối DB, xác thực và định nghĩa nguồn ảnh (`IMAGE_SOURCES`), định nghĩa hằng số cache/extensions, tự động tạo bảng DB.
    *   `database.sqlite`: Tệp cơ sở dữ liệu SQLite.
    *   `cache/thumbnails/`: Thư mục lưu trữ thumbnail đã tạo.
    *   `images/`: Thư mục nguồn ảnh mặc định (có thể thay đổi/thêm trong `config.php`).
    *   `logs/`: Thư mục chứa file log ứng dụng.
*   **Tác vụ nền (Cron/Scheduled Tasks):**
    *   `worker_cache.php`: Script chạy nền (worker) để xử lý các yêu cầu tạo thumbnail kích thước lớn một cách bất đồng bộ. Lấy các job từ bảng `cache_jobs`.
    *   `cron_cache_manager.php`: Script chạy theo lịch (cron job) để:
        *   Dọn dẹp các file thumbnail "mồ côi" (không có ảnh gốc tương ứng) trong thư mục cache.
        *   **Quan trọng:** Đã thêm bước kiểm tra an toàn để ngăn chặn việc xóa toàn bộ cache nếu script không tìm thấy bất kỳ file ảnh gốc hợp lệ nào (do lỗi cấu hình, thư mục nguồn bị ngắt kết nối, v.v.).
    *   `cron_log_cleaner.php`: Script chạy theo lịch để dọn dẹp các file log cũ.
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
*   **API backend (`api.php`) đã được refactor thành cấu trúc module rõ ràng hơn trong thư mục `api/` để dễ bảo trì.**
*   Đã thực hiện nhiều cải tiến về cấu trúc code frontend (tập trung cấu hình, refactor modal CSS) và sửa lỗi giao diện/logic (hiển thị icon khóa, logic prompt mật khẩu, căn chỉnh, v.v.).
*   Hiệu ứng làm mờ nền khi hiển thị modal đã được thêm.
*   Đã thử nghiệm và hoàn nguyên về font chữ hệ thống mặc định.
*   **Đã sửa lỗi hiển thị thumbnail cho thư mục con.**
*   **Đã khắc phục lỗi thông báo "Đang tạo ZIP" không tự ẩn và lỗi "Bad Request"/"Unexpected token" khi tải ZIP.**
*   **Đã sửa lỗi cú pháp JavaScript trong `js/admin.js`.**
*   **Đã thêm tiêu đề cột 'Cache' còn thiếu vào bảng trong trang admin (`admin.php`).**
*   **Đã sửa logic tạo đường dẫn cache thumbnail để đảm bảo lưu vào thư mục con theo kích thước (ví dụ: `cache/thumbnails/150/`, `cache/thumbnails/750/`).**
*   **Đã triển khai cơ chế tạo cache bất đồng bộ bằng hàng đợi công việc (DB table `cache_jobs` và script `worker_cache.php`) để tránh chặn người dùng khi admin tạo cache.**
*   **Đã cấu hình worker cache chỉ tạo trước thumbnail kích thước lớn nhất (ví dụ: 750px), thumbnail nhỏ (150px) vẫn được tạo on-the-fly.**
*   **Đã thêm cơ chế tự động làm mới danh sách thư mục trên trang admin để cập nhật trạng thái nút cache sau khi worker xử lý xong.**
*   **Đã khắc phục lỗi khóa cơ sở dữ liệu (database locked) xảy ra do tranh chấp giữa worker và auto-refresh trang admin bằng cách thêm timeout (PDO::ATTR_TIMEOUT) cho kết nối PDO trong `db_connect.php`.**
*   **Đã cải thiện UX của nút cache: sử dụng polling nhanh hơn trong `js/admin.js` để cập nhật trạng thái nút (Đang chờ/Đang xử lý/Đã cache) gần như tức thì sau khi bấm nút hoặc worker hoàn thành, thay vì phải chờ auto-refresh toàn cục.**
*   **Đã sửa lỗi CSS hiển thị bảng quản trị trên mobile:** Thêm `data-label` vào các ô `<td>` trong `js/admin.js` và điều chỉnh CSS trong `css/style.css` để bảng hiển thị đúng dạng khối trên màn hình nhỏ.
*   **Đã cải thiện CSS bảng quản trị trên desktop:** Tăng chiều rộng ô input link chia sẻ và loại bỏ giới hạn chiều cao/thanh cuộn cho ô trạng thái cache.
*   **Đã sửa lỗi JavaScript trên trang admin:** Thay thế các lời gọi `showMessage` thành `showFeedback`, sửa lỗi đọc thuộc tính `folder.protected` và đảm bảo nút "Xóa MK" được gắn event listener đúng cách.
*   **Đã di chuyển và định dạng lại ô thông báo admin:** Di chuyển `div#admin-feedback` trong `admin.php` lên vị trí dễ thấy hơn và cập nhật CSS để có giao diện panel nhất quán.
*   **Đã cải thiện hiển thị trạng thái cache:** 
    *   Trong bảng admin, chỉ hiển thị số lượng ảnh đã cache (thay vì timestamp) và thêm icon thông tin (`ℹ️`).
    *   Khi click icon `ℹ️`, hiển thị modal chi tiết (tên, đường dẫn, số lượng, timestamp, kết quả job gần nhất).
    *   Sửa lỗi logic worker (`worker_cache.php`) và API (`api/actions_admin.php`) để lưu và trả về đúng `image_count` và `latest_job_status`.
    *   Sửa lỗi logic frontend (`js/admin.js`) để modal hiển thị đúng thông tin và cảnh báo lỗi chỉ xuất hiện khi job gần nhất thực sự `failed`.
    *   Cải thiện UX nút yêu cầu cache để cập nhật trạng thái "Đang chờ xử lý" ngay lập tức.

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

## Thay đổi gần đây (Latest Changes)

*   **2025-05-07 (Bạn & AI):**
        *   **Triển khai chức năng tạo ZIP bất đồng bộ:**
            *   Tạo script worker chạy nền (`worker_zip.php`) để xử lý các yêu cầu tạo file ZIP.
            *   Xây dựng các API endpoints mới trong `api/actions_public.php` (`request_zip`, `get_zip_status`, `download_final_zip`) để quản lý và theo dõi tiến trình.
            *   Thêm bảng `zip_jobs` vào SQLite để lưu trữ và quản lý hàng đợi các công việc tạo ZIP, với cơ chế tự động tạo bảng trong `db_connect.php`.
            *   Cập nhật giao diện người dùng (`index.php`, `js/app.js`) để hiển thị thông báo, thanh tiến trình, và link tải về cho file ZIP.
        *   **Gỡ lỗi và Tối ưu hóa chuyên sâu chức năng ZIP:**
            *   Khắc phục lỗi tràn bộ nhớ (memory exhaustion) khi tải các file ZIP dung lượng lớn bằng cách triển khai cơ chế đọc và gửi file theo từng chunk nhỏ trong action `download_final_zip`.
            *   Giải quyết các vấn đề liên quan đến đường dẫn file không nhất quán (`realpath failed`) giữa worker và API, đảm bảo file ZIP được lưu và đọc đúng vị trí.
            *   Cải thiện độ chính xác và tần suất cập nhật của thanh tiến trình ZIP bằng cách tối ưu logic ghi vào CSDL trong `worker_zip.php` và logic polling/hiển thị trong `js/app.js`.
            *   Giải quyết triệt để các lỗi "database is locked" xảy ra do tranh chấp tài nguyên:
                *   Thêm cơ chế thử lại (retry mechanism) cho các câu lệnh cập nhật CSDL quan trọng trong worker.
                *   Tăng giá trị `PDO::ATTR_TIMEOUT` trong `db_connect.php`.
                *   Tinh chỉnh logic API (`request_zip`) để xử lý các job đã tồn tại một cách hiệu quả hơn.
                *   Cải thiện logic của worker để nhận diện và bỏ qua các job "stale" (đã được xử lý hoặc không còn hợp lệ).
                *   Xác định và giải quyết nguyên nhân gốc rễ của các job "ma" (ví dụ: Job 3) bằng cách đảm bảo chỉ có một tiến trình worker duy nhất đang chạy và thêm logging chi tiết để theo dõi.
                *   Sửa lỗi CSDL "no such column: finished_at" bằng cách tự động thêm cột `finished_at` vào bảng `zip_jobs` thông qua `db_connect.php` và đảm bảo worker cập nhật cột này khi job hoàn thành.
            *   Hệ thống tạo ZIP bất đồng bộ hiện tại được xem là hoạt động ổn định.

        *   **Cải thiện UI/UX cho tiến trình tạo và hoàn thành ZIP (07-May-2025):**
            *   Thay thế khu vực hiển thị tiến trình ZIP cũ bằng một thanh tiến trình mới, cố định ở cuối màn hình (sticky footer progress bar).
            *   Thanh tiến trình mới hiển thị tên thư mục đang được nén, một thanh progress trực quan, và thông tin số file đã xử lý/tổng số file cùng tỷ lệ phần trăm.
            *   Khi quá trình tạo ZIP hoàn thành hoặc thất bại, một modal thông báo sẽ được hiển thị (sử dụng lại và tùy chỉnh hệ thống modal chung của ứng dụng để đảm bảo tính đồng nhất).
            *   Giao diện của modal hoàn thành ZIP được tinh chỉnh: nút "Tải về ngay" và "Đóng" được bố trí lại theo chiều dọc, loại bỏ thông tin tên file đầy đủ khỏi modal để tránh tràn chữ và giữ giao diện gọn gàng.
            *   Khắc phục một số lỗi JavaScript liên quan đến phạm vi biến (ví dụ: `getCurrentFolderInfo`, `generalModalOverlay`) và thứ tự định nghĩa hàm (`showModalWithMessage`) để đảm bảo các thành phần này hoạt động chính xác.
            *   Cải thiện logic của hàm `pollZipStatus` để xử lý các cấu trúc phản hồi API linh hoạt hơn, giúp cập nhật trạng thái tiến trình ZIP chính xác hơn.

        *   **Cập nhật tài liệu hướng dẫn triển khai (07-May-2025):**
            *   Chỉnh sửa và hoàn thiện file `README.md` với hướng dẫn chi tiết về cách triển khai các script worker (`worker_cache.php`, `worker_zip.php`) và các tác vụ dọn dẹp định kỳ (`cron_cache_manager.php`, `cron_log_cleaner.php`) trên môi trường server sản xuất chạy Windows với XAMPP.
            *   Nội dung cập nhật tập trung vào việc sử dụng Windows Task Scheduler để quản lý các tiến trình này, các lưu ý quan trọng về cấu hình PHP CLI, đường dẫn tuyệt đối trong `config.php`, cơ chế lock file cho worker, và quyền truy cập file/thư mục cần thiết trên Windows.
            *   Các hướng dẫn triển khai cho môi trường Linux (supervisor, systemd) đã được rút gọn hoặc loại bỏ để tập trung vào kịch bản Windows/XAMPP.
            *   Cập nhật phần khởi tạo CSDL trong `README.md` để bao gồm bảng `zip_jobs`.

*   **2025-05-06 (Bạn & AI):**
    *   Thêm cơ chế theo dõi tiến trình cache real-time vào trang Admin:
        *   Mở rộng bảng `cache_jobs` để lưu `total_files`, `processed_files`, `current_file_processing`.
        *   Cập nhật `worker_cache.php` để đếm tổng số file, cập nhật tiến trình (số file đã xử lý, file hiện tại) vào DB trong lúc chạy.
        *   Cập nhật API `admin_list_folders` để trả về thông tin tiến trình.
        *   Cập nhật `js/admin.js` và `css/style.css` để hiển thị thanh tiến trình, phần trăm hoàn thành, và file đang xử lý.
        *   Gỡ lỗi và sửa vấn đề cập nhật tiến trình cache:
            *   Giảm tần suất ghi vào DB của worker để tránh lỗi "database is locked".
            *   Thêm cơ chế thử lại (retry) khi gặp lỗi "database is locked".
            *   Sửa lỗi closure trong worker khiến biến đếm `processed_files` không được cập nhật đúng cách vào DB (sử dụng tham chiếu `&`).
        *   Dọn dẹp các log debug không cần thiết trong `worker_cache.php` sau khi sửa lỗi thành công.
*   **2025-05-05 (Bạn & AI):**
    *   Fix logic API (`api/actions_admin.php`) để lấy thông tin cache job chính xác cho từng thư mục, giải quyết lỗi hiển thị "Không rõ số lượng".
    *   Thêm bước kiểm tra an toàn vào script dọn dẹp cache (`cron_cache_manager.php`) để ngăn việc xóa toàn bộ cache khi không tìm thấy ảnh gốc.
*   **Trước đó:**
    *   Thêm cột `image_count` vào DB, sửa worker để lưu số lượng ảnh cache.

## 8. Kiểm thử End-to-End (Playwright)

*   **Trạng thái:** Đang triển khai.
*   **Cài đặt:** Playwright đã được cài đặt và cấu hình (`package.json`, `playwright.config.ts`, `tests/`). `.gitignore` đã được cập nhật.
*   **Tệp kiểm thử:** `tests/gallery.spec.ts` chứa các nhóm kiểm thử cho Admin Login, Public Gallery, và Admin Panel.
*   **Kết quả:**
    *   **PASSED:** Đăng nhập Admin, Hiển thị danh sách thư mục gốc (Public), Hiển thị danh sách thư mục (Admin).
    *   **FAILED:** Điều hướng vào thư mục và hiển thị thumbnail (Public), Mở ảnh trong PhotoSwipe (Public). Nguyên nhân gốc rễ là thumbnail trong `#image-grid` không xuất hiện sau khi điều hướng vào thư mục, nghi ngờ lỗi API hoặc lỗi render JS.
    *   **TODO:** Các kiểm thử chức năng admin khác (mật khẩu, cache), các kiểm thử public khác (tìm kiếm, ZIP, v.v.).
*   **Gỡ lỗi:** Đã thực hiện nhiều bước gỡ lỗi (thêm chờ, sửa selector, ưu tiên `data-dir`, kiểm tra cấu trúc HTML) nhưng vấn đề thumbnail chưa được giải quyết. Việc gỡ lỗi đang tạm dừng. 