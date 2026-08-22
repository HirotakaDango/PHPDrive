# PHPDrive

<img width="1366" height="768" alt="image" src="https://github.com/user-attachments/assets/ed0ea30b-3d99-4b03-8a94-ecfd78118917" />

A fast, minimal, and open-source cloud file manager and workspace contained entirely in a **single PHP file**.

PHPDrive delivers a desktop-grade, Google Drive-inspired web interface to browse, preview, edit, and organize files directly in your browser. Engineered with a lightweight Single-Page Application (SPA) architecture, it is optimized for high-throughput media streaming and fluid responsiveness even on low-spec hardware.

---

## ✨ Features

* **Single-File Deployment**: Drop `drive.php` (or `index.php`) onto any PHP server and get started immediately with zero configuration or build steps.
* **Seamless SPA & URL Navigation**: Deep-linking support (`?path=...`, `?edit=...`, `?view=...`) with seamless browser history back/forward navigation without hard page reloads.
* **High-Performance Media Streaming**:
  * Optimized byte-range playback (`HTTP 206 Partial Content`) with 512KB zero-copy chunk delivery and connection abort detection.
  * Released PHP session locks to ensure smooth concurrent streaming.
  * Broad video and audio codec support: **MP4, WebM, MKV, M4V, MOV, AVI, TS, MP3, WAV, OGG, FLAC, M4A, OPUS**.
  * Direct "Native Tab / External Player" toggle for zero-overhead hardware playback on legacy devices.
* **Large-Scale Code Editor**:
  * Optimized CodeMirror editor built to handle large files (millions of characters) smoothly.
  * Intelligent syntax highlighting (PHP, JS, JSON, HTML, CSS) with adaptive fallback for large files to guarantee 60fps performance.
  * Built-in multi-line Find & Replace, Undo/Redo, word wrap toggle, and mobile touch fallback.
* **File Version History & Visual Diff Viewer**:
  * Automatic backup created before any file overwrite, save, or restore.
  * Built-in visual **Diff Viewer** to inspect side-by-side or inline additions and deletions against past versions.
  * One-click version rollback.
* **Dual-Role Authentication (Admin & Demo)**:
  * **Admin Role**: Full workspace access and write permissions.
  * **Demo Role** (`demo`): Read-only sandbox mode where all modifications, uploads, and deletions are securely blocked.
* **Image Thumbnails in Grid & List Views**:
  * Automatic on-the-fly WebP thumbnail generation with local disk caching.
  * Integrated visual previews across both Grid and List view modes.
* **Chunked Upload Widget**:
  * Google Drive-style collapsible upload drawer.
  * 5MB sliced upload engine to bypass strict `upload_max_filesize` and `post_max_size` PHP limits.
  * Full recursive folder drag-and-drop support.
* **AES-256 File Encryption**: Encrypt and decrypt sensitive files directly from the context menu.
* **Trash & Soft-Delete**: Safe recycling bin with individual item restoration, properties inspection, and automatic 30-day purge cleanup.
* **ZIP Archive & Extraction**: Compress multiple selections on the fly or extract ZIP archives directly on the server.
* **Public File Sharing**: Generate tokenized public direct download/stream links.
* **Themes & Sorting**: Full Light and Dark themes, flexible multi-select actions, and instant sorting (by name, modification date, size, and type).

---

## 🔒 Authentication & Access Roles

When password protection is enabled, PHPDrive supports two authentication modes:

| Role | Default Password | Permissions |
| :--- | :--- | :--- |
| **Admin** | `admin` *(or custom configured)* | **Full Access** — Upload, edit, delete, rename, encrypt, and manage security settings. |
| **Demo** | `demo` | **Read-Only** — Browse, search, preview media, compare diffs, and inspect properties without modifying files. |

> **Tip:** You can toggle password protection or set your custom master password directly from the sidebar security toggle.

---

## 🚀 Installation

1. Upload `drive.php` (or rename it to `index.php`) to any web-accessible directory on your server.
2. Open the URL in your web browser (e.g., `http://your-server.com/drive.php`).
3. If security is enabled, enter your password or use `admin` for full access or `demo` for read-only preview.

---

## ⚙️ Data Storage & Cache

PHPDrive stores metadata and cache silently in hidden files within its directory:

* `.drive_config.json`: Stores authentication preferences and bcrypt password hashes.
* `.drive_metadata.json`: Tracks starred items, active public share links, and trash bin states.
* `.drive_trash_bin/`: Hidden storage directory for soft-deleted items awaiting restoration or purge.
* `.file_version/`: Directory storing timestamped snapshots of modified and overwritten files.
* `.drive_thumbnails/`: Cached lightweight WebP image thumbnails for instant gallery and list rendering.

---

## 📋 Requirements

* **PHP 7.4+** or **PHP 8.0+**
* PHP Extensions:
  * `json` (Standard)
  * `fileinfo` (Standard)
  * `gd` *(Optional — used for generating WebP thumbnail previews)*
  * `zip` *(Optional — used for batch ZIP downloads and ZIP archive extraction)*
  * `openssl` *(Optional — used for AES-256 file encryption)*
* Read & Write permissions on the host folder.

---

## 📄 License

This project is open-source and available under the [MIT License](LICENSE).
