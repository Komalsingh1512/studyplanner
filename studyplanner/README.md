# Study Planner — Deployment Guide

## XAMPP Pe Local Setup

### Step 1 — XAMPP Install Karo
- Download: https://www.apachefriends.org
- Install karo aur Apache + MySQL start karo

### Step 2 — Project Copy Karo
```
C:/xampp/htdocs/study-planner/
```
ZIP extract karke yahan paste karo.

### Step 3 — Database Banao
1. Browser mein jao: http://localhost/phpmyadmin
2. New database banao: `study_planner`
3. SQL tab pe jao
4. `config/database.sql` ka content paste karo
5. Execute karo

### Step 4 — DB Config Check Karo
`config/db.php` mein:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');        // XAMPP mein default blank hota hai
define('DB_NAME', 'study_planner');
```

### Step 5 — API Key Lagao
`pages/ai_plan.php` mein line 47:
```php
$api_key = 'sk-ant-api03-...'; // Apni real key yahan
```
Key lene ke liye: https://console.anthropic.com

### Step 6 — Run Karo
Browser mein jao: http://localhost/study-planner

---

## Online Hosting (Free) — 000webhost

### Step 1 — Account Banao
- https://www.000webhost.com pe register karo

### Step 2 — Files Upload Karo
- File Manager open karo
- `public_html/` mein saari files upload karo

### Step 3 — Database Setup
- Hosting panel mein MySQL Database banao
- phpMyAdmin se `database.sql` import karo
- `config/db.php` mein hosting ka DB credentials daalo:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'tumhara_db_user');
define('DB_PASS', 'tumhara_db_password');
define('DB_NAME', 'tumhara_db_name');
```

### Step 4 — Live!
Tumhara link hoga: `https://tumhara-naam.000webhostapp.com`

---

## Project Files List

```
study-planner/
├── index.php                  ← Entry point (redirect)
├── config/
│   ├── db.php                 ← Database connection
│   └── database.sql           ← Tables create karne ka SQL
├── includes/
│   └── auth.php               ← Login/session helpers
├── pages/
│   ├── register.php           ← Registration
│   ├── login.php              ← Login
│   ├── logout.php             ← Logout
│   ├── dashboard.php          ← Main dashboard
│   ├── subjects.php           ← Subjects manage
│   ├── tasks.php              ← Tasks manage
│   ├── toggle_task.php        ← Task complete toggle
│   └── ai_plan.php            ← AI study plan generator
└── assets/
    └── css/
        └── style.css          ← Styling
```

---

## Technologies Used

| Layer      | Technology        |
|------------|-------------------|
| Frontend   | HTML, CSS, Bootstrap 5, JavaScript |
| Backend    | PHP 8+            |
| Database   | MySQL             |
| AI         | Claude API (Anthropic) |
| Hosting    | XAMPP (local) / 000webhost (online) |

---

## Realtime Chat Setup

The subject chat page now uses:
- Groq API with `llama-3.1-8b-instant`
- WebSocket server for realtime AI + student room chat

### Configure
Update:
`config/runtime.json`

Set:
- `groq.api_key`
- `realtime.shared_secret`
- `realtime.ws_url`
- `realtime.internal_base_url`

### Run WebSocket Server
From project root:

```bash
node studyplanner/realtime/chat-server.js
```

Default WebSocket URL:
`ws://127.0.0.1:8081`
