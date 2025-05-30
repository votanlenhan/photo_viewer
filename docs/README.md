# Simple PHP Photo Gallery

Một ứng dụng thư viện ảnh web cơ bản được viết bằng PHP, hỗ trợ nhiều nguồn ảnh, sử dụng MySQL để lưu trữ mật khẩu thư mục, thống kê, và hàng đợi công việc. Nó có bảng điều khiển quản trị để quản lý mật khẩu thư mục.

## Tính năng chính

*   **Giao diện người dùng hiện đại:** Duyệt ảnh và thư mục một cách trực quan.
*   **Tìm kiếm Album:** Tìm kiếm nhanh album theo tên.
*   **Hỗ trợ đa nguồn ảnh:** Định cấu hình nhiều thư mục gốc chứa ảnh (ví dụ: ổ cứng chính, ổ cứng ngoài).
*   **Trình xem ảnh Lightbox:** Sử dụng PhotoSwipe để xem ảnh kích thước đầy đủ với các điều khiển tiện lợi.
*   **Bảo vệ thư mục bằng mật khẩu:** Đặt mật khẩu cho các thư mục cụ thể thông qua bảng điều khiển quản trị.
*   **Tải xuống thư mục:** Cho phép tải xuống toàn bộ nội dung thư mục dưới dạng file ZIP.
*   **Phản hồi tiến trình tải ZIP:** Hiển thị thông báo, spinner và cho phép hủy khi chuẩn bị file ZIP.
*   **Hiệu ứng Blur cho lớp phủ:** Làm mờ nền trang khi hiển thị lớp phủ (tiến trình ZIP, nhập mật khẩu).
*   **Bảng điều khiển quản trị:** Xem thống kê lượt xem/tải, quản lý mật khẩu thư mục, quản lý trạng thái cache thumbnail.
*   **Tạo thumbnail tự động và bất đồng bộ:**
    *   Tạo thumbnail "on-the-fly" cho kích thước nhỏ (ví dụ: 150px).
    *   Sử dụng **worker nền (`worker_cache.php`) và hàng đợi (`cache_jobs` table)** để tạo thumbnail kích thước lớn (ví dụ: 750px) mà không chặn người dùng admin.
    *   Trang admin sử dụng **polling** để cập nhật trạng thái tạo cache gần như tức thì.
*   **Hệ thống RAW Cache cho Jet Culling Workspace:**
    *   **Simplified Architecture:** Chỉ tạo 1 cache size (750px) thay vì 2 sizes
    *   **Performance Optimized:** ~50% faster processing, more reliable
    *   **Background Processing:** Worker `worker_jet_cache.php` xử lý RAW files bằng dcraw + ImageMagick
    *   **Database Sync Tools:** Cleanup orphaned records khi cache files bị xóa manually
    *   **Admin Management:** Queue cache jobs cho entire RAW folders, real-time status updates
    *   **Frontend Flexibility:** CSS resize 750px images cho grid và filmstrip views
*   **Dọn dẹp cache và log tự động:** Cung cấp script cron job (`cron_cache_manager.php`, `cron_log_cleaner.php`) để xóa thumbnail cũ/mồ côi và xoay vòng log.
*   **Hệ thống dọn dẹp ZIP nâng cao:**
    *   Tự động xóa file ZIP sau 5 phút kể từ khi tạo
    *   Xử lý thông minh các trường hợp lỗi và retry
    *   Tự động dọn dẹp file ZIP mồ côi (tồn tại trên disk nhưng không có trong DB)
    *   Tự động clean record database stale (có final_zip_path nhưng file không tồn tại)
    *   Theo dõi số lần thử xóa và đánh dấu các file không thể xóa
    *   Log chi tiết về quá trình dọn dẹp
    *   Không cần can thiệp thủ công

## Yêu cầu

*   PHP (>= 7.4 khuyến nghị, sử dụng các tính năng như `??` và `fn()`)
*   Web Server (ví dụ: Apache, Nginx, hoặc **XAMPP** trên Windows)
*   MySQL Server
*   PHP Extensions:
    *   `pdo_mysql` (để truy cập cơ sở dữ liệu MySQL)
    *   `gd` (để tạo thumbnail)
    *   `zip` (để tải thư mục dưới dạng zip)
    *   `mbstring` (cho các hàm xử lý chuỗi đa byte)
    *   `fileinfo` (cho `mime_content_type()`, thường được bật mặc định)
*   **Công cụ xử lý RAW (cho Jet Culling Workspace):**
    *   `dcraw.exe` - Để decode RAW files thành PPM format
    *   `magick.exe` (ImageMagick) - Để convert PPM thành JPEG và resize
    *   Cả hai executable phải được đặt trong thư mục `exe/` của project
*   **Quyền ghi** cho người dùng web server trên các thư mục và file sau:
    *   Thư mục `cache/` (và các thư mục con của nó)
    *   Thư mục `logs/`

## Cài đặt

1.  **Clone Repository hoặc Tải Code:**
    ```bash
    # Nếu dùng Git
    git clone <repository-url> photo-gallery
    cd photo-gallery
    # Hoặc tải và giải nén ZIP vào thư mục web server (ví dụ: htdocs của XAMPP)
    ```

2.  **Cấu hình `config.php`:**
    *   **Quan trọng:** File `config.php` mới chứa tất cả các cài đặt quan trọng.
    *   **Tạo file:** Nếu file này chưa có (ví dụ khi clone từ repo chưa có file này), hãy tạo nó ở thư mục gốc.
    *   **Điền thông tin:** Mở `config.php` và chỉnh sửa các giá trị cho phù hợp với môi trường của bạn:
        *   Thông tin kết nối MySQL: `type` (đặt là 'mysql'), `host`, `name` (tên database), `user`, `pass`.
        *   `admin_username`, `admin_password_hash`: Thông tin đăng nhập admin (**QUAN TRỌNG: Tạo hash mới cho mật khẩu của bạn!** Xem hướng dẫn bên dưới).
        *   `image_sources`: **Cấu hình các thư mục chứa ảnh của bạn tại đây.** Sử dụng đường dẫn tuyệt đối.
        *   `cache_thumb_root`: Đường dẫn đến thư mục cache thumbnail.
        *   Xem lại các cài đặt khác như `allowed_extensions`, `thumbnail_sizes`, `pagination_limit`, `zip_...`, `log_...`, `app_title` và chỉnh sửa nếu cần.
    *   **Lưu ý về đường dẫn:** Bạn có thể khai báo các `image_sources` ngay cả khi đường dẫn chưa tồn tại trên máy hiện tại. Ứng dụng sẽ bỏ qua các nguồn không hợp lệ khi chạy.

3.  **Khởi tạo Cơ sở dữ liệu:**
    *   Bạn cần có một cơ sở dữ liệu MySQL đã được tạo trên server MySQL của bạn.
    *   Các bảng cần thiết (`folder_passwords`, `folder_stats`, `cache_jobs`, `zip_jobs`) sẽ được **tự động tạo** trong database đã cấu hình bởi script `db_connect.php` khi ứng dụng chạy lần đầu.
    *   Bạn không cần phải tạo bảng thủ công, chỉ cần đảm bảo database tồn tại và thông tin kết nối trong `config.php` là chính xác.

4.  **Thiết lập Quyền:** Đảm bảo người dùng chạy web server (ví dụ: user chạy Apache/XAMPP) có quyền đọc tất cả các thư mục trong `IMAGE_SOURCES` và quyền ghi vào thư mục `cache/` và `logs/` (và các thư mục con). Trên Windows với XAMPP, thường quyền này đã được cấp đúng.

5.  **Tạo Hash Mật khẩu Admin (Cách thực hiện trong `config.php`):**
    *   Tạo một file PHP tạm thời (ví dụ: `generate_hash.php`) trong thư mục gốc với nội dung:
        ```php
        <?php
        // Thay 'MatKhauAdminCuaBan' bằng mật khẩu thực tế bạn muốn đặt
        echo password_hash('MatKhauAdminCuaBan', PASSWORD_DEFAULT);
        ?>
        ```
    *   Chạy file này từ trình duyệt hoặc dòng lệnh: `php generate_hash.php`.
    *   Copy chuỗi hash được tạo ra (ví dụ: `$2y$10$...`).
    *   Mở `config.php`, tìm dòng `'admin_password_hash' => '...'` và dán hash mới của bạn vào đó, thay thế hash cũ.
    *   Xóa file `generate_hash.php` sau khi hoàn tất.
    *   **Quan trọng:** Đảm bảo thay đổi mật khẩu mặc định!

6.  **Thiết lập Cron Job / Scheduled Task:**
    *   Script `cron_log_cleaner.php` giờ đọc cấu hình từ `config.php`.
    *   Các bước thiết lập Task Scheduler (Windows) hoặc crontab (Linux) vẫn giữ nguyên như mô tả trước.
    *   File `run_cache_cleanup.bat` vẫn hoạt động như cũ (tự động phát hiện PHP trong XAMPP).

7.  **Cấu hình Web Server:**
    *   Trỏ Document Root của web server đến thư mục gốc của dự án.
    *   Đảm bảo module rewrite được bật nếu dùng Apache (file `.htaccess` đã được cung cấp). Với Nginx, cần cấu hình `try_files` tương ứng.

## Sử dụng

### Giao diện chính (Gallery)
*   Truy cập ứng dụng qua trình duyệt.
*   Trang chủ hiển thị danh sách các thư mục cấp 1 từ tất cả các nguồn đã định nghĩa trong `IMAGE_SOURCES`.
*   URL sẽ có dạng `...#?folder=source_key/ten_thu_muc/thu_muc_con` khi duyệt.
*   Click vào thư mục để xem nội dung.
*   Click vào ảnh thumbnail để mở trình xem PhotoSwipe.
*   Sử dụng nút "Tải về tất cả" để tải ZIP thư mục hiện tại.

### Jet Culling Workspace (RAW Image Management)
*   Truy cập `/jet.php` để vào workspace cho RAW image culling.
*   **Tính năng chính:**
    *   Hiển thị RAW files dưới dạng JPEG previews (750px)
    *   Color labeling system (Red, Green, Blue, Grey)
    *   Filtering theo pick status và colors
    *   Sorting theo tên file hoặc ngày modified
    *   Preview mode với keyboard navigation
    *   Database lưu pick selections cho multiple users
*   **Cache Management:**
    *   RAW previews được tạo on-the-fly hoặc qua admin interface
    *   Worker `worker_jet_cache.php` xử lý background processing
    *   Admin có thể queue cache jobs cho entire folders

### Admin Panel
*   Truy cập `/login.php` để đăng nhập admin.
*   Truy cập `/admin.php` (sau khi đăng nhập) để:
    *   Quản lý mật khẩu thư mục
    *   Xem thống kê lượt truy cập
    *   Quản lý cache thumbnail cho gallery
    *   **Quản lý RAW cache cho Jet workspace:**
        *   Queue cache jobs cho RAW folders
        *   Monitor cache progress real-time
        *   Cleanup orphaned cache records
        *   View detailed cache statistics

## Bảo mật

*   **File `config.php`:** File này chứa thông tin nhạy cảm (thông tin kết nối DB, mật khẩu admin). Đảm bảo file này không bị đưa lên Git repository công khai nếu bạn chia sẻ dự án. Cân nhắc các biện pháp bảo vệ file cấu hình phù hợp với môi trường của bạn.
*   **Thay đổi mật khẩu admin mặc định** trong `config.php` ngay lập tức.
*   Đảm bảo chỉ cấp quyền ghi tối thiểu cần thiết cho web server trên các thư mục.
*   Xem xét việc bảo vệ thư mục `logs/` và `cache/` khỏi việc truy cập trực tiếp từ web (có thể dùng `.htaccess` hoặc cấu hình server). File `.htaccess` hiện tại có cấu hình cơ bản để chặn truy cập vào một số file/thư mục.


## Triển khai Worker và Tác vụ Nền trên Server Windows (XAMPP)

Để các tính năng tạo thumbnail kích thước lớn và tạo file ZIP hoạt động một cách hiệu quả và không đồng bộ trên môi trường server Windows với XAMPP, bạn cần thiết lập các script worker (`worker_cache.php` và `worker_zip.php`) và các tác vụ dọn dẹp định kỳ bằng **Windows Task Scheduler**.

### 1. Thiết lập Worker Chạy Nền bằng Windows Task Scheduler

Các script `worker_cache.php` (tạo thumbnail) và `worker_zip.php` (tạo file ZIP) xử lý các tác vụ nặng một cách bất đồng bộ. Trên Windows, cách phổ biến để chạy chúng gần như liên tục là sử dụng Task Scheduler để kích hoạt chúng thường xuyên.

**Lưu ý quan trọng:**
*   **PHP CLI:** Các worker này phải được chạy bằng `php.exe` từ bản XAMPP của bạn (ví dụ: `C:\\xampp\\php\\php.exe`). Đảm bảo phiên bản PHP CLI này có đủ các extension yêu cầu (`pdo_mysql`, `gd`, `zip`, `mbstring`, `fileinfo`). Bạn có thể kiểm tra bằng cách chạy `C:\\xampp\\php\\php.exe -m` từ Command Prompt.
*   **Một Instance:** Mỗi script worker (`worker_cache.php`, `worker_zip.php`) nên chỉ có một instance chạy tại một thời điểm để tránh xung đột. Các worker script **phải tự quản lý việc này**, ví dụ bằng cách sử dụng một "lock file". Nếu script worker được thiết kế để chạy, xử lý một batch job rồi thoát, thì việc chạy thường xuyên qua Task Scheduler sẽ mô phỏng việc chạy liên tục. Nếu script worker được thiết kế để chạy một vòng lặp vô tận, bạn cần cơ chế để Task Scheduler chỉ khởi động nó nếu nó chưa chạy.
*   **Đường dẫn tuyệt đối:** Đường dẫn trong file `config.php` (đặc biệt là `image_sources`, `cache_thumb_root`, `cache_zip_root`, `log_root`) phải là đường dẫn tuyệt đối và chính xác trên server (ví dụ: `D:\\xampp\\htdocs\\photo-gallery\\cache\\`). Thông tin kết nối database (`host`, `name`, `user`, `pass`) cũng cần chính xác.
*   **Logging:** Theo dõi file log của worker trong thư mục `logs/` (ví dụ: `logs/worker_zip_error.log`, `logs/worker_cache_error.log`) để phát hiện và sửa lỗi.

**Cách thiết lập Worker với Task Scheduler:**

1.  **Mở Task Scheduler:** Tìm "Task Scheduler" trong Windows Start Menu.
2.  **Create Task:** Trong menu "Actions", chọn "Create Task..." (Không phải "Create Basic Task" để có nhiều tùy chọn hơn).
3.  **Tab General:**
    *   **Name:** Đặt tên dễ nhớ, ví dụ `PhotoGallery_ZipWorker`.
    *   **Description:** (Tùy chọn) Mô tả tác vụ.
    *   **Security options:** Chọn "Run whether user is logged on or not". Bạn có thể cần nhập mật khẩu tài khoản người dùng Windows sẽ chạy tác vụ này. Chọn "Run with highest privileges" nếu cần.
4.  **Tab Triggers:**
    *   Nhấn "New...".
    *   **Begin the task:** Chọn "On a schedule".
    *   **Settings:** Chọn "Daily".
    *   **Advanced settings:**
        *   Check "Repeat task every:" và đặt khoảng thời gian ngắn, ví dụ "5 minutes" hoặc "1 minute" tùy theo mức độ "liên tục" bạn muốn.
        *   **For a duration of:** Chọn "Indefinitely".
        *   Ensure "Enabled" is checked.
    *   Nhấn "OK".
5.  **Tab Actions:**
    *   Nhấn "New...".
    *   **Action:** Chọn "Start a program".
    *   **Program/script:** Điền đường dẫn đầy đủ đến `php.exe` của XAMPP. Ví dụ: `C:\xampp\php\php.exe`.
    *   **Add arguments (optional):** Điền đường dẫn đầy đủ đến script worker. Ví dụ: `D:\xampp\htdocs\photo-gallery\worker_zip.php`.
    *   **Start in (optional):** Điền đường dẫn đến thư mục gốc của dự án. Ví dụ: `D:\xampp\htdocs\photo-gallery\`. Điều này quan trọng để script có thể tìm thấy các file tương đối (như `config.php`) một cách chính xác.
    *   Nhấn "OK".
6.  **Tab Conditions & Settings:** Xem lại các tùy chọn, thường thì cài đặt mặc định là ổn. Ví dụ, trong "Settings", "Allow task to be run on demand" là hữu ích. "Stop the task if it runs longer than:" có thể cần điều chỉnh nếu worker của bạn xử lý job lớn. "If the running task does not end when requested, force it to stop" cũng là một tùy chọn.
7.  Nhấn "OK" để lưu Task. Bạn có thể được yêu cầu nhập mật khẩu người dùng.
8.  Lặp lại các bước trên để tạo một Task tương tự cho `worker_cache.php`.
9.  **Thiết lập RAW Cache Worker:** Lặp lại tương tự cho `worker_jet_cache.php` với tên task `PhotoGallery_JetCacheWorker`. Worker này xử lý RAW file cache cho Jet Culling Workspace.

**Quan trọng về Lock File:**
Như đã đề cập, nếu bạn muốn các worker chạy "gần như liên tục" bằng cách lên lịch Task Scheduler chạy thường xuyên (ví dụ: mỗi phút), các script `worker_cache.php` và `worker_zip.php` **PHẢI** có cơ chế kiểm tra xem có instance nào khác của chính nó đang chạy hay không (thường dùng lock file). Nếu có instance đang chạy, script mới được Task Scheduler gọi nên thoát ngay lập tức. Nếu không, nó sẽ tạo lock file, thực hiện công việc, rồi xóa lock file khi xong. Điều này ngăn chặn nhiều instance chạy đồng thời và gây xung đột.

*Các phương pháp khác cho môi trường không phải Windows/XAMPP (ví dụ: Linux với `supervisor`, `systemd`, `nohup`) được mô tả trong các tài liệu hướng dẫn chung cho PHP và có thể được tham khảo nếu bạn triển khai trên các nền tảng đó.*

### 2. Thiết lập Tác vụ Dọn dẹp Định kỳ bằng Windows Task Scheduler

Các script sau cần được chạy định kỳ để dọn dẹp:
*   `cron_cache_manager.php`: Dọn dẹp các file thumbnail "mồ côi".
*   `cron_log_cleaner.php`: Dọn dẹp các file log cũ.
*   `cron_zip_cleanup.php`: Hệ thống dọn dẹp ZIP nâng cao:
    *   Xóa file ZIP sau 5 phút kể từ khi tạo
    *   Tự động dọn dẹp file mồ côi và record stale
    *   Xử lý retry cho các file khó xóa
    *   Log chi tiết về quá trình dọn dẹp
    *   Không cần can thiệp thủ công

**Cách thiết lập:**
Sử dụng Windows Task Scheduler tương tự như cách thiết lập cho worker ở trên, nhưng với các khác biệt sau ở **Tab Triggers**:
1.  **Mở Task Scheduler** và chọn "Create Task...".
2.  **Tab General:** Đặt tên (ví dụ `PhotoGallery_CacheCleanup`).
3.  **Tab Triggers:**
    *   Nhấn "New...".
    *   **Begin the task:** Chọn "On a schedule".
    *   **Settings:** Chọn "Daily" hoặc "Weekly" tùy nhu cầu.
    *   **Start time:** Chọn thời gian ít tải (ví dụ: 2:00 AM).
    *   KHÔNG cần "Repeat task every:".
    *   Nhấn "OK".
4.  **Tab Actions:**
    *   **Program/script:** `C:\xampp\php\php.exe`
    *   **Add arguments (optional):** Đường dẫn đến script (ví dụ: `D:\xampp\htdocs\photo-gallery\cron_cache_manager.php`).
    *   **Start in (optional):** `D:\xampp\htdocs\photo-gallery\`
    *   Nhấn "OK".
5.  Xem lại **Conditions & Settings** và nhấn "OK" để lưu.
6.  Lặp lại cho script `cron_log_cleaner.php`.
7.  Lặp lại tương tự cho script `cron_zip_cleanup.php`, đặt tên task ví dụ `PhotoGallery_ZipCleanup` và điều chỉnh tần suất nếu muốn (ví dụ, chạy mỗi 5-10 phút nếu `cleanup_interval_minutes` trong script là 5 phút).
    *File `run_cache_cleanup.bat` trong dự án có thể là một ví dụ tham khảo cách chạy các script này trên Windows, mặc dù Task Scheduler cung cấp nhiều kiểm soát hơn.*
    *Lưu ý: Dự án cũng cung cấp một file `setup_workers_schedule.bat` tiện lợi hơn để tự động tạo tất cả các Task Scheduler cần thiết (bao gồm cả workers và cron jobs) với các cấu hình đề xuất. Bạn nên xem xét sử dụng file batch này để đơn giản hóa việc thiết lập.*

### 3. Quyền Truy Cập File/Thư mục trên Windows (XAMPP)

Thông thường, khi bạn chạy XAMPP trên Windows với tài khoản người dùng của mình, các quyền truy cập file/thư mục đã đủ cho cả web server (Apache chạy trong XAMPP) và các script PHP CLI chạy qua Task Scheduler (nếu Task Scheduler được cấu hình để chạy với cùng tài khoản người dùng hoặc tài khoản có quyền tương đương).

Đảm bảo rằng:
*   **Đọc:** Tài khoản chạy PHP có quyền đọc tất cả các thư mục nguồn ảnh được định nghĩa trong `image_sources` của `config.php`.
*   **Đọc/Ghi/Sửa đổi:** Tài khoản chạy PHP có quyền này đối với:
    *   Thư mục `cache/` và tất cả các thư mục con của nó (`thumbnails/`, `zips/`).
    *   Thư mục `logs/`.

Nếu bạn gặp vấn đề về quyền, hãy kiểm tra "Security" tab trong Properties của các thư mục/file đó trong Windows Explorer và đảm bảo tài khoản người dùng mà XAMPP và Task Scheduler đang sử dụng có các quyền cần thiết.
