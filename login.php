<?php
session_start(); // Luôn bắt đầu session ở đầu file cần dùng session

// --- CẤU HÌNH ADMIN ---
define('ADMIN_USERNAME', 'admin'); // Đặt tên đăng nhập admin mong muốn

// !!! SECURITY WARNING !!!
// DO NOT COMMIT YOUR REAL PASSWORD HASH TO A PUBLIC REPOSITORY.
// Ideally, move these credentials to environment variables or a config file outside the web root (and add to .gitignore).
// Make sure to change the password immediately after deploying to a new server.
// The hash below is a placeholder for the password "password".
define('ADMIN_PASSWORD_HASH', '$2y$10$.lWLpmOjLY6pun9c62z9iOo9/in5RA/UQKTMd513rR2QhlB34Vvu2'); // <<<--- GENERATE AND REPLACE WITH YOUR OWN SECURE HASH !!!

$error_message = '';

// Nếu admin đã đăng nhập rồi, chuyển thẳng đến trang admin
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin.php');
    exit;
}

// Xử lý khi người dùng gửi form đăng nhập
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username_attempt = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password_attempt = isset($_POST['password']) ? $_POST['password'] : '';

    // Kiểm tra thông tin đăng nhập
    if ($username_attempt === ADMIN_USERNAME && password_verify($password_attempt, ADMIN_PASSWORD_HASH)) {
        // Đăng nhập thành công
        // Tái tạo session ID để tăng bảo mật (ngăn chặn session fixation)
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true; // Lưu trạng thái đăng nhập vào session
        $_SESSION['admin_username'] = $username_attempt; // Lưu tên đăng nhập
        header('Location: admin.php'); // Chuyển hướng đến trang admin
        exit;
    } else {
        // Đăng nhập thất bại
        $error_message = 'Tên đăng nhập hoặc mật khẩu không đúng.';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập Admin</title>
    <link rel="stylesheet" href="css/style.css"> <style>
        /* CSS riêng cho trang login */
        html, body { height: 100%; } /* Đảm bảo body chiếm toàn bộ chiều cao */
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: #0d1117; /* Nền tối giống theme */
            color: #e6edf3;
        }
        .login-container {
            background: #161b22; /* Nền tối hơn cho box */
            padding: 35px 40px;
            border-radius: 8px;
            border: 1px solid #30363d;
            box-shadow: 0 8px 24px rgba(0,0,0,0.4);
            text-align: center;
            width: 100%;
            max-width: 360px; /* Giới hạn chiều rộng */
        }
        .login-container h2 {
             margin-top: 0; margin-bottom: 25px; color: #f0f6fc; font-size: 1.6em;
        }
        .login-container label {
             display: block; text-align: left; margin-bottom: 8px; font-weight: 500; color: #c9d1d9; font-size: 0.9em;
        }
        .login-container input[type="text"],
        .login-container input[type="password"] {
            width: 100%;
            padding: 10px 12px;
            margin-bottom: 20px;
            border: 1px solid #30363d;
            border-radius: 6px;
            background-color: #0d1117; /* Nền input tối */
            color: #e6edf3; /* Chữ trong input */
            font-size: 1em;
            box-sizing: border-box;
        }
         .login-container input:focus {
             outline: none;
             border-color: #58a6ff;
             box-shadow: 0 0 0 3px rgba(88, 166, 255, 0.2);
         }
        .login-container button { width: 100%; padding: 11px; font-size: 1.1em; background-color: #238636; border-color: #2ea043;}
        .login-container button:hover { background-color: #2ea043; border-color: #3fb950;}
        .error { color: #f85149; margin-top: -10px; margin-bottom: 15px; font-size: 0.9em; text-align: center; }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Đăng nhập Trang Quản trị</h2>
        <?php if (!empty($error_message)): ?>
            <p class="error"><?php echo htmlspecialchars($error_message); ?></p>
        <?php endif; ?>
        <form method="post" action="login.php" novalidate>
             <label for="username">Tên đăng nhập:</label>
            <input type="text" id="username" name="username" required autocomplete="username">
             <label for="password">Mật khẩu:</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
            <button type="submit" class="button">Đăng nhập</button>
        </form>
    </div>
</body>
</html>