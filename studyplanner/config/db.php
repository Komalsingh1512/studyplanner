<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'study_planner');

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8");

$notes_table_sql = "CREATE TABLE IF NOT EXISTS subject_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    subject_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    note_content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
)";

mysqli_query($conn, $notes_table_sql);

$subtopics_table_sql = "CREATE TABLE IF NOT EXISTS subject_subtopics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    subject_id INT NOT NULL,
    title VARCHAR(180) NOT NULL,
    note_content TEXT,
    is_completed TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
)";

mysqli_query($conn, $subtopics_table_sql);

$chat_messages_table_sql = "CREATE TABLE IF NOT EXISTS subject_chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    subject_id INT NOT NULL,
    role ENUM('user', 'assistant') NOT NULL,
    message_text LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
)";

mysqli_query($conn, $chat_messages_table_sql);

$room_messages_table_sql = "CREATE TABLE IF NOT EXISTS subject_room_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    subject_id INT NOT NULL,
    room_key VARCHAR(150) NOT NULL,
    subject_name_snapshot VARCHAR(100) NOT NULL,
    user_name_snapshot VARCHAR(100) NOT NULL,
    message_text LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
)";

mysqli_query($conn, $room_messages_table_sql);

$room_key_column = mysqli_query($conn, "SHOW COLUMNS FROM subject_room_messages LIKE 'room_key'");
if ($room_key_column && mysqli_num_rows($room_key_column) === 0) {
    mysqli_query($conn, "ALTER TABLE subject_room_messages ADD COLUMN room_key VARCHAR(150) NOT NULL DEFAULT '' AFTER subject_id");
}

$subject_name_snapshot_column = mysqli_query($conn, "SHOW COLUMNS FROM subject_room_messages LIKE 'subject_name_snapshot'");
if ($subject_name_snapshot_column && mysqli_num_rows($subject_name_snapshot_column) === 0) {
    mysqli_query($conn, "ALTER TABLE subject_room_messages ADD COLUMN subject_name_snapshot VARCHAR(100) NOT NULL DEFAULT '' AFTER room_key");
}
?>
