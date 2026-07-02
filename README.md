# PHPDrive

A fast, minimal, and open-source file manager contained entirely in a single PHP file. 

PHPDrive provides a fully-featured web interface to manage files and directories directly from your browser. It features a responsive, Material Design-inspired interface with progressive web app (PWA) capabilities.

## Features

* Single-File Deployment: Drop the `index.php` file onto your server to install.
* Complete File Management: Upload, create, rename, delete, move, and copy files and folders.
* Built-in Code Editor: Edit text and code files directly in the browser with syntax highlighting (powered by CodeMirror) and find/replace capabilities.
* Media Streaming & Previews: Built-in viewers for images, PDFs, audio (MP3, WAV, OGG), and video (MP4, WebM) with support for partial content/range requests.
* Password Protection: Built-in toggleable security layer. The default password is "Admin" and can be changed from the UI.
* Trash System: Safely soft-delete files and restore them later.
* Batch Downloads: Select multiple files or directories and download them as a ZIP archive.
* File Sharing: Generate public links to share specific files.
* UI Customization: Supports Dark and Light themes, Grid and List views, and sorting/filtering.
* Mobile Ready: Fully responsive layout that can be installed as a Progressive Web App (PWA) on mobile devices.

## Installation

1. Place the `index.php` file into any web-accessible directory on your PHP server.
2. Navigate to that directory in your web browser.
3. If security is enabled, log in using the default password: `Admin`.

## Configuration and Data Storage

PHPDrive automatically creates the following hidden items in its root directory to manage state:

* `.drive_config.json`: Stores authentication settings and bcrypt password hashes.
* `.drive_metadata.json`: Stores metadata for starred items, shared links, and trash bin tracking.
* `.drive_trash_bin/`: A hidden directory used to safely store soft-deleted files.

## Requirements

* PHP 7.0 or higher
* `zip` PHP extension (required for batch downloading files)
* Read/Write permissions in the directory where the script is placed
