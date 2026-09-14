# Study Planner

**Study Planner** is an AI-powered educational web application designed to help students organize their learning and revision. Built with a PHP and MySQL backend alongside a custom Node.js WebSocket server, the platform allows users to:
- **Organize Subjects:** Upload syllabus files (PDF/DOCX) which are automatically parsed and broken down into actionable subtopics using AI.
- **Generate Study Plans:** Instantly create tailored, day-by-day revision schedules based on exam dates, difficulty, and current progress.
- **Chat in Real-Time:** Engage with a dedicated AI tutor for subject-specific help, or chat live with other students studying the same subject in shared rooms.
- **Track Progress:** Log study sessions, take notes, and manage daily tasks in a clean, responsive dashboard.

---

## Table of Contents

1. [Features](#features)
2. [Architecture Overview](#architecture-overview)
3. [Tech Stack](#tech-stack)
4. [Database Schema](#database-schema)
5. [Setup Instructions](#setup-instructions)
6. [Configuration](#configuration)
7. [Running the Real-Time Chat Server](#running-the-real-time-chat-server)
8. [Project Structure](#project-structure)
9. [Feature Implementation Details](#feature-implementation-details)
10. [Known Limitations / Next Steps](#known-limitations--next-steps)

---

## Features

- **Auth** — registration/login with hashed passwords and PHP session-based auth guarding every page.
- **Subjects** — create subjects with difficulty, exam date, topic counts, and an optional syllabus
  (typed in manually **or** uploaded as a PDF / DOCX / DOC / TXT file, which is parsed into plain text
  on the server).
- **AI-generated subtopics** — given a subject's syllabus text, an LLM (via the Groq API) generates a
  configurable number of concrete subtopics, deduplicated against subtopics that already exist.
- **Subject workspace** — per-subject view to add/complete subtopics manually and attach freeform
  notes to each subtopic.
- **Tasks** — schedule study sessions per subject/day/time with a completion toggle, filterable by date.
- **Notes** — freeform notes tied to a subject.
- **AI Study Plan generator** — turns all of a student's subjects (with completion %, difficulty, and
  days-to-exam) into a structured multi-day study plan via an LLM prompt, rendered as formatted
  Markdown (headings, tables, bold text) in the browser, with copy/print actions.
- **Real-time subject chat** — a hand-rolled WebSocket server (no external WebSocket library) that
  provides two channels per subject: a private AI-tutor conversation, and a shared "student room" so
  everyone studying the same subject can chat live. Messages are persisted to MySQL through a signed
  internal API call from the Node process back to PHP.
- **Dashboard** — an overview of subjects, today's tasks, and quick stats.

---

## Architecture Overview

```
┌──────────────┐        HTTP (PHP pages)        ┌───────────────┐
│   Browser    │ ──────────────────────────────▶ │  Apache + PHP │
│ (Bootstrap5, │ ◀────────────────────────────── │   (mysqli)    │
│  vanilla JS) │                                  └───────┬───────┘
│              │                                          │
│              │        WebSocket (ws://…:8081)           │
│              │ ───────────────────────────────▶ ┌───────▼───────┐
│              │ ◀─────────────────────────────── │  Node.js      │
└──────────────┘                                  │  chat-server  │
                                                   └───────┬───────┘
                                                           │ signed HTTP
                                                           │ (HMAC)
                                                    ┌──────▼──────┐
                                                    │   MySQL     │
                                                    └─────────────┘
                                                           ▲
                                                           │ HTTPS
                                          ┌────────────────┴────────────────┐
                                          │        Groq Chat Completions    │
                                          │    (OpenAI-compatible API)      │
                                          └──────────────────────────────────┘
```

Two independent processes run side by side:

1. **The PHP/Apache app** (traditional server-rendered pages) handles auth, CRUD for subjects/
   tasks/notes/subtopics, syllabus file parsing, AI subtopic generation, and AI study plan generation.
2. **A standalone Node.js process** (`realtime/chat-server.js`) implements the WebSocket protocol
   from scratch (HTTP Upgrade handshake, RFC 6455 frame encoding/decoding — no `ws` npm package) to
   power the live subject chat. It authenticates each connection with a short-lived, HMAC-signed
   token issued by PHP, calls the Groq API directly for AI tutor replies, and writes every message
   back into MySQL via a signed internal PHP endpoint (`api/realtime_store.php`) so chat history
   survives page reloads.

Both processes read the same `config/runtime.json` for their Groq credentials and shared secret, so
there is a single source of truth for configuration.

---

## Tech Stack

| Layer            | Technology                                             |
|-------------------|--------------------------------------------------------|
| Frontend          | HTML, Bootstrap 5, Bootstrap Icons, vanilla JavaScript |
| Markdown rendering| marked.js (client-side, for the AI study plan)          |
| Backend           | PHP 8+ (mysqli, no framework)                          |
| Realtime server   | Node.js (raw `http`/`crypto`/`net`, hand-rolled WebSocket frames) |
| Database          | MySQL                                                  |
| AI provider       | Groq (OpenAI-compatible Chat Completions API)          |
| Hosting (local)   | XAMPP (Apache + MySQL + PHP)                           |

---

## Database Schema

All tables are created by `config/database.sql` (and self-healed on boot by `config/db.php`, which
runs idempotent `CREATE TABLE IF NOT EXISTS` / column-existence checks so the schema stays in sync
even if you pull a newer version of the code onto an existing DB).

| Table                     | Purpose                                                            |
|----------------------------|---------------------------------------------------------------------|
| `users`                   | Account credentials (bcrypt/password_hash), name, email             |
| `subjects`                | One row per subject: topic counts, difficulty, exam date, syllabus text/file, AI subtopic target |
| `study_tasks`             | Scheduled study sessions (date, time, duration, completion state)    |
| `progress_log`            | Daily hours-studied / topics-covered log (per subject)               |
| `subject_notes`           | Freeform notes tied to a subject                                     |
| `subject_subtopics`       | Subtopics (manual or AI-generated) with per-subtopic notes and completion |
| `subject_chat_messages`   | Private AI-tutor chat history, per user + subject                    |
| `subject_room_messages`   | Shared "student room" chat history, per subject room                 |

All child tables use `ON DELETE CASCADE` on `user_id`/`subject_id` foreign keys.

---

## Setup Instructions

### 1. Install prerequisites
- [XAMPP](https://www.apachefriends.org) (or any Apache + PHP 8+ + MySQL stack)
- [Node.js](https://nodejs.org) 18+ (for the real-time chat server; Node 18+ has built-in `fetch`)

### 2. Get the code onto your server
Clone/copy this repository into your web root, e.g.:
```
C:/xampp/htdocs/studyplanner/
```

### 3. Create the database
1. Start Apache + MySQL from the XAMPP control panel.
2. Open `http://localhost/phpmyadmin`.
3. Create a database named `study_planner`.
4. Open the SQL tab and run the contents of `config/database.sql`.

### 4. Configure the database connection
Edit `config/db.php`:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');        // blank by default on XAMPP
define('DB_NAME', 'study_planner');
```

### 5. Configure the AI provider (Groq)
Edit `config/runtime.json`:
```json
{
  "groq": {
    "api_key": "YOUR_GROQ_API_KEY",
    "model": "openai/gpt-oss-20b",
    "api_url": "https://api.groq.com/openai/v1/chat/completions"
  },
  "realtime": {
    "ws_url": "ws://127.0.0.1:8081",
    "internal_base_url": "http://127.0.0.1:8000",
    "shared_secret": "choose-a-long-random-string"
  }
}
```
Get a free API key from [console.groq.com](https://console.groq.com). You can alternatively set the
`GROQ_API_KEY` and `STUDYPLANNER_REALTIME_SECRET` environment variables instead of editing the JSON
file directly — the code checks the environment first.

> Both the AI subtopic generator and the AI study plan generator use this same Groq configuration,
> so a personal API key is never required from end users of the app.

### 6. Run the app
You can start the PHP built-in development server by running this in your terminal from the project root:
```bash
php -S 127.0.0.1:8000 -t .
```
Visit `http://127.0.0.1:8000` and register a new account.

---

## Running the Real-Time Chat Server

The subject chat feature (`pages/chat.php`) needs the Node process running alongside Apache:

```bash
node realtime/chat-server.js
```

By default it listens on `ws://127.0.0.1:8081` (configurable via `STUDYPLANNER_WS_PORT`). Convenience
launch scripts are included for Windows: `realtime/start-server.bat` and `realtime/start-server.ps1`.

If the WebSocket server isn't running, the chat page will show a connection error but the rest of the
app (subjects, tasks, notes, AI plan) works independently of it.

---

## Project Structure

```
studyplanner/
├── index.php                      Entry point — redirects to dashboard or login
├── config/
│   ├── db.php                     MySQL connection + idempotent schema migrations
│   ├── database.sql               Base schema (run once on a fresh DB)
│   ├── runtime.php                Reads config/runtime.json, builds/verifies HMAC tokens
│   ├── runtime.json               Groq + realtime credentials (git-ignore this in production!)
│   └── ai.php                     (legacy/unused — earlier direct-Anthropic integration)
├── includes/
│   ├── auth.php                   Session helpers: requireLogin(), getCurrentUser(), sanitize()
│   └── syllabus_upload.php        Uploads + parses PDF/DOCX/DOC/TXT syllabus files to plain text
├── api/
│   ├── generate_subtopics.php     Calls Groq to turn a subject's syllabus into subtopics
│   ├── chat_bootstrap.php         Issues a signed realtime token + chat history for a subject
│   └── realtime_store.php         Internal endpoint the Node server calls to persist chat messages
├── pages/
│   ├── register.php / login.php / logout.php
│   ├── dashboard.php              Overview: subjects, today's tasks, quick stats
│   ├── subjects.php               Create/list/update/delete subjects, upload syllabus
│   ├── subject_workspace.php      Per-subject subtopics + notes management
│   ├── tasks.php / toggle_task.php
│   ├── notes.php
│   ├── ai_plan.php                AI-generated multi-day study plan (Groq + Markdown rendering)
│   └── chat.php                   Real-time AI tutor + student room UI (WebSocket client)
├── realtime/
│   ├── chat-server.js             Hand-rolled WebSocket server (Node, no ws dependency)
│   └── start-server.bat / .ps1    Windows convenience launchers
├── assets/css/style.css           All styling
└── uploads/syllabus/              Uploaded syllabus files land here
```

---

## Feature Implementation Details

### Authentication
Simple PHP session auth (`includes/auth.php`). Every protected page calls `requireLogin()`, which
redirects unauthenticated visitors to `login.php`. Passwords are stored using PHP's built-in
`password_hash()`/`password_verify()`.

### Syllabus upload & parsing
`includes/syllabus_upload.php` accepts PDF, DOCX, DOC, and TXT files. DOCX is parsed by unzipping the
file (it's a ZIP archive) and stripping tags from `word/document.xml`; PDF/DOC/TXT go through their own
extraction routines. The extracted text is normalized (line-ending/whitespace cleanup) and stored
alongside any manually-typed syllabus text in the `subjects.subject_syllabus` column.

### AI subtopic generation (`api/generate_subtopics.php`)
1. Loads the subject's syllabus text (and its configured `ai_subtopic_target` count, 1–25).
2. Builds a prompt instructing the model to return **only** a JSON array of subtopic names.
3. Calls Groq's Chat Completions endpoint (OpenAI-compatible payload shape) with that prompt.
4. Parses the JSON response defensively — if the model wraps the array in a code fence or returns a
   plain numbered list instead of JSON, a fallback line-parser still extracts usable items.
5. Deduplicates against subtopics that already exist for that subject before inserting new rows.
6. Surfaces Groq's real error message on failure (invalid key, decommissioned model, rate limit, etc.)
   instead of a generic error string, and logs the full response server-side for debugging.

### AI study plan (`pages/ai_plan.php`)
1. Gathers all of the student's subjects with completion %, difficulty, and days remaining until each
   exam.
2. Sends a single prompt to Groq asking for a day-by-day plan (morning/afternoon/evening sessions +
   daily goal) formatted as clean Markdown, explicitly prioritizing subjects that are less complete,
   harder, or closer to their exam date.
3. The raw Markdown is embedded in the page as a JSON string and rendered client-side with
   **marked.js** into real HTML (headings, tables, bold text, etc.) rather than shown as escaped plain
   text — with matching CSS for tables/headings/blockquotes.
4. "Copy Plan" copies the original Markdown text; "Print / Save PDF" uses the browser's native print
   dialog.

### Real-time subject chat (`pages/chat.php` + `realtime/chat-server.js` + `api/*`)
1. On page load, the browser calls `api/chat_bootstrap.php`, which verifies the subject belongs to the
   logged-in user, computes a normalized "room key" from the subject name (so all students in the same
   subject share one room), loads recent AI + room chat history from MySQL, and issues a short-lived
   token: `base64url(payload) + "." + HMAC-SHA256(payload, shared_secret)`.
2. The browser opens a raw WebSocket to the Node server, passing that token in the URL.
3. The Node server implements the WebSocket protocol itself:
   - Computes the `Sec-WebSocket-Accept` handshake response by hashing the client's key with the
     RFC 6455 magic GUID.
   - Encodes/decodes WebSocket frames by hand (including masking/unmasking client frames and
     handling the 7-bit / 16-bit / 64-bit payload-length variants).
   - Verifies the token's HMAC signature and expiry before accepting any messages.
4. Two message types are handled per connection:
   - `ai_message` → forwarded to Groq with a system prompt scoping the tutor to that subject, plus the
     recent conversation history; the reply is sent back to just that client.
   - `student_message` → broadcast to every other socket currently in the same room key.
5. Every message (both AI and room chat) is persisted back to MySQL by having the Node process POST
   to `api/realtime_store.php`, signing the request body with the same shared-secret HMAC scheme (this
   keeps the database credentials entirely on the PHP side — Node never talks to MySQL directly).

### Dashboard, Tasks, Notes, Subjects
Standard CRUD pages built with prepared statements (`mysqli_prepare`/`mysqli_stmt_bind_param`)
throughout to avoid SQL injection on user-supplied values, with `sanitize()` (trim + strip_tags +
htmlspecialchars) applied to free-text input before display.

---

## Known Limitations / Next Steps

- `config/runtime.json` currently stores the Groq API key in plain text in the repo — for a real
  deployment this should move to environment variables only and be excluded via `.gitignore`.
- `config/ai.php` is dead code left over from an earlier version that called the Anthropic API
  directly with a user-supplied key; the AI Plan page no longer uses it (it now uses the shared Groq
  configuration), and it can be safely deleted.
- No automated tests yet; CRUD and API endpoints are currently verified manually.
- The real-time server currently keeps all connected clients in an in-process `Set`, so it does not
  horizontally scale across multiple Node instances without adding a shared pub/sub layer (e.g. Redis).
