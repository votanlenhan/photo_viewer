<?php
// api.php (Main Entry Point)

// --- 1. Initialization & Global Setup ---
// This file now handles error reporting, session, db connection, constants, and basic variables ($pdo, $action, etc.)
require_once __DIR__ . '/api/init.php';

// --- 2. Load Helper Functions ---
require_once __DIR__ . '/api/helpers.php';

// --- 3. Route Action to Appropriate Handler ---

// Determine if it's likely an admin action
$isAdminAction = strpos($action, 'admin_') === 0;

if ($isAdminAction) {
    // Load and execute admin actions
    // The switch statement inside actions_admin.php will handle the specific action
    // or fall through if it's an unknown admin action.
    require_once __DIR__ . '/api/actions_admin.php';
} else {
    // Load and execute public actions
    // The switch statement inside actions_public.php will handle the specific action
    // or fall through if it's not a known public action.
    require_once __DIR__ . '/api/actions_public.php';
}

// --- 4. Fallback for Unknown Actions ---
// If the script execution reaches this point, it means:
// - The action was not handled by actions_public.php (not a known public action)
// - AND the action was not handled by actions_admin.php (not a known admin action)
// This catches genuinely unknown/invalid actions.

// json_error function is loaded from helpers.php
json_error("Hành động không xác định hoặc không được hỗ trợ: " . htmlspecialchars($action), 400);

// Note: The json_error function includes an exit call, so the script terminates here.

?>