<?php
    header("Access-Control-Allow-Origin: *");

// Allow the necessary HTTP methods used by your API.
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");

// Allow necessary headers, especially Content-Type for JSON submissions.
header("Access-Control-Allow-Headers: Content-Type");

// Preflight request handling: Browsers send an OPTIONS request first.
// If the method is OPTIONS, we respond with 200 OK and exit.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// =================================================================
// 2. CACHING HEADERS (For CDN Readiness)
// These headers instruct CDNs and browsers on how to cache the API response.
// By setting 'no-cache', we ensure the client always re-validates the data 
// (or fetches new data), which is critical for real-time applications.
// =================================================================

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 01 Jan 2000 00:00:00 GMT");

// =================================================================
// 3. SET CONTENT TYPE (JSON)
// Ensure the browser knows the response body is JSON.
// =================================================================

header('Content-Type: application/json');





/**
 * Chat API Endpoint (api.php)
 *
 * *** DIAGNOSTIC CODE ADDED ***
 * This forces PHP to display all errors, overriding server defaults.
 * This should replace the generic 500 error with a detailed message.
 * Remove these lines once the application is working.
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// *** END DIAGNOSTIC CODE ***


// 1. Configuration and Setup
// Requires the settings file to get database credentials.
if (!file_exists('settings.php')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Fatal Error: Configuration file settings.php is missing.']);
    exit();
}
require_once 'chattingembedded.unaux.com/settings.php';

// Set headers for JSON response and allow cross-origin requests (for testing/development)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Be more restrictive in production
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Function to establish database connection
function connectDB() {
    // Attempt connection using constants defined in settings.php
    $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

    if ($conn->connect_error) {
        // Output a specific JSON error message for connection failure
        $errorMessage = "Database connection failed. Check credentials (DB_SERVER, etc.) and host permissions: " . $conn->connect_error;
        error_log($errorMessage);
        http_response_code(500); // Internal Server Error
        echo json_encode(['success' => false, 'error' => $errorMessage]);
        exit();
    }

    return $conn;
}

// Function to return a standard JSON response
function json_response($success, $data = null, $error = null, $http_code = 200) {
    http_response_code($http_code);
    $response = ['success' => $success];
    if ($success) {
        $response['data'] = $data;
    } else {
        $response['error'] = $error;
    }
    echo json_encode($response);
    exit();
}

// Get the connection
$conn = connectDB();

// Determine the action requested by the frontend
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);


// --- HANDLER FUNCTIONS (Contents unchanged) ---

/**
 * Handles all /rooms actions
 */
function handleRooms($conn, $method, $input) {
    if ($method === 'GET') {
        // Fetch all rooms
        $result = $conn->query("SELECT id, name FROM rooms ORDER BY id ASC");
        $rooms = [];
        while ($row = $result->fetch_assoc()) {
            $rooms[] = $row;
        }
        json_response(true, $rooms);

    } elseif ($method === 'POST') {
        // Create a new room
        $roomName = trim($input['name'] ?? '');
        $userId = $input['user_id'] ?? '';

        if (empty($roomName) || empty($userId)) {
            json_response(false, null, 'Room name and user ID are required.', 400);
        }

        // 1. Check for duplicate room name
        $stmt = $conn->prepare("SELECT id FROM rooms WHERE name = ?");
        $stmt->bind_param("s", $roomName);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            json_response(false, null, 'Room name already exists.', 409); // Conflict
        }
        $stmt->close();

        // 2. Insert new room
        $stmt = $conn->prepare("INSERT INTO rooms (name, created_by_user_id) VALUES (?, ?)");
        $stmt->bind_param("ss", $roomName, $userId);

        if ($stmt->execute()) {
            $newRoomId = $conn->insert_id;
            json_response(true, ['id' => $newRoomId, 'name' => $roomName]);
        } else {
            json_response(false, null, 'Failed to create room: ' . $conn->error, 500);
        }
        $stmt->close();
    } else {
        json_response(false, null, 'Method not allowed for /rooms', 405);
    }
}

/**
 * Handles all /profiles actions
 */
function handleProfiles($conn, $method, $input) {
    if ($method === 'GET') {
        // Get profile by user_id
        $userId = $_GET['user_id'] ?? '';
        if (empty($userId)) {
            json_response(false, null, 'User ID is required.', 400);
        }

        $stmt = $conn->prepare("SELECT display_name FROM users WHERE user_id = ?");
        $stmt->bind_param("s", $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            json_response(true, $row);
        } else {
            // Return success with empty data if profile not found
            json_response(true, ['display_name' => null]);
        }
        $stmt->close();

    } elseif ($method === 'POST') {
        // Set new display name
        $userId = $input['user_id'] ?? '';
        $displayName = trim($input['display_name'] ?? '');

        if (empty($userId) || empty($displayName)) {
            json_response(false, null, 'User ID and Display Name are required.', 400);
        }

        // 1. Check for duplicate display name
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE display_name = ? AND user_id != ?");
        $stmt->bind_param("ss", $displayName, $userId);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            json_response(false, null, 'Display name already taken.', 409); // Conflict
        }
        $stmt->close();

        // 2. Insert or update the user profile
        // The display name is required, so we use REPLACE INTO to handle both INSERT and UPDATE based on user_id
        $stmt = $conn->prepare("REPLACE INTO users (user_id, display_name) VALUES (?, ?)");
        $stmt->bind_param("ss", $userId, $displayName);

        if ($stmt->execute()) {
            json_response(true, ['display_name' => $displayName]);
        } else {
            json_response(false, null, 'Failed to set display name: ' . $conn->error, 500);
        }
        $stmt->close();
    } else {
        json_response(false, null, 'Method not allowed for /profile', 405);
    }
}

/**
 * Handles all /messages actions
 */
function handleMessages($conn, $method, $input) {
    if ($method === 'GET') {
        // Fetch messages for a room
        $roomId = $_GET['room_id'] ?? 0;
        $lastId = $_GET['last_id'] ?? 0; // Optimization: only fetch messages newer than last known ID

        if (!is_numeric($roomId)) {
            json_response(false, null, 'Invalid room ID.', 400);
        }

        // Fetch messages for the room, ordered by timestamp
        $sql = "SELECT id, user_id, user_name, message_text, timestamp FROM messages WHERE room_id = ? AND id > ? ORDER BY timestamp ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $roomId, $lastId);
        $stmt->execute();
        $result = $stmt->get_result();

        $messages = [];
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }
        
        json_response(true, $messages);
        $stmt->close();

    } elseif ($method === 'POST') {
        // Send a new message
        $roomId = $input['room_id'] ?? 0;
        $userId = $input['user_id'] ?? '';
        $userName = $input['user_name'] ?? 'Anonymous';
        $messageText = $input['message_text'] ?? '';

        if (!is_numeric($roomId) || empty($userId) || empty($messageText)) {
            json_response(false, null, 'Missing message data.', 400);
        }

        $stmt = $conn->prepare("INSERT INTO messages (room_id, user_id, user_name, message_text) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $roomId, $userId, $userName, $messageText);

        if ($stmt->execute()) {
            json_response(true, ['id' => $conn->insert_id]);
        } else {
            json_response(false, null, 'Failed to send message: ' . $conn->error, 500);
        }
        $stmt->close();
    } else {
        json_response(false, null, 'Method not allowed for /messages', 405);
    }
}


// --- API ROUTER ---

switch ($action) {
    case 'rooms':
        handleRooms($conn, $method, $input);
        break;
    case 'profile':
        handleProfiles($conn, $method, $input);
        break;
    case 'messages':
        handleMessages($conn, $method, $input);
        break;
    default:
        json_response(false, null, 'Invalid API action.', 404);
}

$conn->close();
?>
