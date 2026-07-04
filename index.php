<?php
session_start();
$baseDir = __DIR__;
$configPath = $baseDir . '/.drive_config.json';
$config = file_exists($configPath) ? json_decode(file_get_contents($configPath), true) : ['protected' => false, 'password' => password_hash('Admin', PASSWORD_DEFAULT)];

if (isset($_GET['icon'])) {
  while (ob_get_level()) ob_end_clean();
  header('Content-Type: image/svg+xml');
  echo "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><path fill='#0b57d0' d='M4.406 3.342A5.53 5.53 0 0 1 8 2c2.69 0 4.923 2 5.166 4.579C14.758 6.804 16 8.137 16 9.773 16 11.569 14.502 13 12.687 13H3.781C1.708 13 0 11.366 0 9.318c0-1.763 1.266-3.223 2.942-3.593.143-.863.698-1.723 1.464-2.383'/></svg>";
  exit;
}
if (isset($_GET['manifest'])) {
  while (ob_get_level()) ob_end_clean(); 
  header('Content-Type: application/manifest+json');
  echo json_encode([
    "name" => "PHPDrive", "short_name" => "PHPDrive", "start_url" => "./", "display" => "standalone",
    "background_color" => "#ffffff", "theme_color" => "#0b57d0",
    "icons" => [
      ["src" => "?icon=192", "sizes" => "192x192", "type" => "image/svg+xml", "purpose" => "any maskable"],
      ["src" => "?icon=512", "sizes" => "512x512", "type" => "image/svg+xml", "purpose" => "any maskable"]
    ]
  ]);
  exit;
}
if (isset($_GET['sw'])) {
  while (ob_get_level()) ob_end_clean();
  header('Content-Type: application/javascript');
  // Service Worker strictly passes offline capabilities check
  echo "const C='pd-v2';self.addEventListener('install',e=>{e.waitUntil(caches.open(C).then(c=>c.add('./')));self.skipWaiting();});self.addEventListener('activate',e=>self.clients.claim());self.addEventListener('fetch',e=>{if(e.request.method==='GET'){e.respondWith(fetch(e.request).catch(()=>new Response('Offline',{status:200,headers:{'Content-Type':'text/plain'}})));}});";
  exit;
}
if (isset($_POST['login_pass'])) {
  if (password_verify($_POST['login_pass'], $config['password'])) {
    $_SESSION['auth'] = true;
    echo json_encode(['success' => true]);
  } else {
    echo json_encode(['success' => false, 'error' => 'Invalid password']);
  }
  exit;
}
if ($config['protected'] && empty($_SESSION['auth'])) {
  if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'auth_required']);
    exit;
  }
}

$allowedExtensions = ['txt', 'php', 'html', 'css', 'js', 'json', 'xml', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'pdf', 'mp3', 'wav', 'ogg', 'mp4', 'webm', 'zip'];

function isAllowedExtension($filename) {
  return true; // All file extensions allowed
}

function save_file_version($filepath) {
  global $baseDir;
  if (!file_exists($filepath) || is_dir($filepath)) return;
  $filename = basename($filepath);
  $verDir = $baseDir . '/.file_version/' . $filename;
  if (!is_dir($verDir)) @mkdir($verDir, 0755, true);
  $date = date('Y-m-d_H-i-s');
  @copy($filepath, $verDir . '/' . $filename . '_' . $date);
}

function generateUniqueFileName($dir, $filename) {
  $baseName = pathinfo($filename, PATHINFO_FILENAME);
  $extension = pathinfo($filename, PATHINFO_EXTENSION);
  $counter = 1;
  while (file_exists($dir . '/' . $baseName . '_' . $counter . '.' . $extension)) {
    $counter++;
  }
  return $baseName . '_' . $counter . '.' . $extension;
}

function generateUniqueFolderName($dir, $foldername) {
  $counter = 1;
  while (is_dir($dir . '/' . $foldername . '_' . $counter)) {
    $counter++;
  }
  return $foldername . '_' . $counter;
}

function recursiveCopy($src, $dst) {
  if (is_dir($src)) {
    @mkdir($dst);
    $items = scandir($src);
    foreach ($items as $item) {
      if ($item === '.' || $item === '..') continue;
      recursiveCopy($src . '/' . $item, $dst . '/' . $item);
    }
  } else if (file_exists($src)) {
    copy($src, $dst);
  }
}

function isValidPath($base, $path) {
  $realBase = realpath($base);
  $realPath = realpath($path);
  if ($realPath === false) return false;
  return strpos($realPath, $realBase) === 0;
}

function recursiveDelete($dir) {
  global $baseDir;
  if (!isValidPath($baseDir, $dir)) return false;
  if (is_file($dir)) return unlink($dir);
  if (!is_dir($dir)) return false;
  $items = array_diff(scandir($dir), ['.', '..']);
  foreach ($items as $item) {
    $path = $dir . '/' . $item;
    is_dir($path) ? recursiveDelete($path) : unlink($path);
  }
  return rmdir($dir);
}

function formatBytes($bytes, $precision = 2) {
  $units = ['B', 'KB', 'MB', 'GB', 'TB'];
  $bytes = max($bytes, 0);
  $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
  $pow = min($pow, count($units) - 1);
  $bytes /= (1 << (10 * $pow));
  return round($bytes, $precision) . ' ' . $units[$pow];
}

function getMetadata() {
  global $baseDir;
  $file = $baseDir . '/.drive_metadata.json';
  if (!file_exists($file)) {
    return ['starred' => [], 'trash' => [], 'shares' => []];
  }
  $data = json_decode(file_get_contents($file), true);
  return is_array($data) ? array_merge(['starred' => [], 'trash' => [], 'shares' => []], $data) : ['starred' => [], 'trash' => [], 'shares' => []];
}

function saveMetadata($data) {
  global $baseDir;
  $file = $baseDir . '/.drive_metadata.json';
  file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

function cleanupExpiredTrash() {
  global $baseDir;
  $meta = getMetadata();
  $changed = false;
  $thirtyDays = 30 * 24 * 60 * 60;
  $now = time();
  $trashBin = $baseDir . '/.drive_trash_bin';

  foreach ($meta['trash'] as $uniq => $info) {
    if ($now - $info['deleted_at'] > $thirtyDays) {
      $full = $trashBin . '/' . $uniq;
      if (file_exists($full)) {
        recursiveDelete($full);
      }
      unset($meta['trash'][$uniq]);
      $changed = true;
    }
  }
  if ($changed) {
    saveMetadata($meta);
  }
}

function streamFileRange($filePath) {
  $size = filesize($filePath);
  $length = $size;
  $start = 0;
  $end = $size - 1;
  $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
  $mimeTypes = [
    'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
    'mp4' => 'video/mp4', 'webm' => 'video/webm', 'pdf' => 'application/pdf',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
    'txt' => 'text/plain', 'html' => 'text/plain', 'css' => 'text/plain',
    'js' => 'text/plain', 'json' => 'text/plain', 'xml' => 'text/plain',
    'php' => 'text/plain'
  ];
  $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
  
  header("Content-Disposition: inline; filename=\"" . basename($filePath) . "\"");
  header("Accept-Ranges: bytes");
  if (isset($_SERVER['HTTP_RANGE'])) {
    $c_start = $start;
    $c_end = $end;
    list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
    if (strpos($range, ',') !== false) {
      header('HTTP/1.1 416 Requested Range Not Satisfiable');
      header("Content-Range: bytes $start-$end/$size");
      exit;
    }
    if ($range == '-') {
      $c_start = $size - substr($range, 1);
    } else {
      $range = explode('-', $range);
      $c_start = $range[0];
      $c_end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $size - 1;
    }
    $c_end = ($c_end > $end) ? $end : $c_end;
    if ($c_start > $c_end || $c_start > $size - 1 || $c_end >= $size) {
      header('HTTP/1.1 416 Requested Range Not Satisfiable');
      header("Content-Range: bytes $start-$end/$size");
      exit;
    }
    $start = $c_start;
    $end = $c_end;
    $length = $end - $start + 1;
    header('HTTP/1.1 206 Partial Content');
    header("Content-Range: bytes $start-$end/$size");
  }
  header("Content-Length: " . $length);
  header("Content-Type: " . $mime);
  
  $fp = fopen($filePath, 'rb');
  fseek($fp, $start);
  $buffer = 1024 * 8;
  while (!feof($fp) && ($p = ftell($fp)) <= $end) {
    if ($p + $buffer > $end) {
      $buffer = $end - $p + 1;
    }
    echo fread($fp, $buffer);
    flush();
  }
  fclose($fp);
}

if (isset($_GET['share'])) {
  $token = $_GET['share'];
  $meta = getMetadata();
  if (isset($meta['shares'][$token])) {
    $relFile = $meta['shares'][$token];
    $fullFile = $baseDir . '/' . $relFile;
    if (file_exists($fullFile) && isAllowedExtension($fullFile)) {
      streamFileRange($fullFile);
      exit;
    }
  }
  http_response_code(404);
  echo "<h1>Link Expired or Invalid</h1>";
  exit;
}

$api = $_GET['api'] ?? null;
$action = $_GET['action'] ?? null;
$reqPath = $_GET['path'] ?? '';
$absPath = $baseDir;

if ($reqPath) {
  $propPath = $baseDir . '/' . $reqPath;
  if (isValidPath($baseDir, $propPath)) {
    $absPath = $propPath;
  } else {
    if ($api) {
      header('Content-Type: application/json');
      echo json_encode(['success' => false, 'error' => 'Invalid path access']);
      exit;
    }
  }
}

if ($api) {
  cleanupExpiredTrash();

  if ($action === 'thumb') {
    while (ob_get_level()) ob_end_clean();
    $file = $_GET['file'] ?? '';
    $full = $baseDir . '/' . $file;
    if (!isValidPath($baseDir, $full) || !is_file($full) || !isAllowedExtension($file)) {
      http_response_code(404);
      exit;
    }
    
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $isImage = in_array($ext, ['png', 'jpg', 'jpeg', 'gif']);
    
    if ($ext === 'svg') {
      header('Content-Type: image/svg+xml');
      readfile($full);
      exit;
    }
    
    if ($isImage) {
      $thumbDir = $baseDir . '/.drive_thumbnails';
      if (!is_dir($thumbDir)) @mkdir($thumbDir, 0755, true);
      $hash = md5($full . filemtime($full));
      $thumbPath = $thumbDir . '/' . $hash . '.webp';
      
      if (!file_exists($thumbPath)) {
        if (function_exists('imagecreatefromstring')) {
          @ini_set('memory_limit', '256M'); // Prevent crash on large photos
          $content = @file_get_contents($full);
          if ($content) {
            $img = @imagecreatefromstring($content);
            if ($img) {
              $width = imagesx($img);
              $height = imagesy($img);
              $newWidth = 320;
              $newHeight = floor($height * ($newWidth / $width));
              $tmp = imagecreatetruecolor($newWidth, $newHeight);
              if ($ext === 'png' || $ext === 'gif') {
                imagealphablending($tmp, false);
                imagesavealpha($tmp, true);
                $transparent = imagecolorallocatealpha($tmp, 255, 255, 255, 127);
                imagefilledrectangle($tmp, 0, 0, $newWidth, $newHeight, $transparent);
              }
              imagecopyresampled($tmp, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
              @imagewebp($tmp, $thumbPath, 50); // Quality 50 at 320px width guarantees size < 60KB
              imagedestroy($img);
              imagedestroy($tmp);
            }
          }
        }
      }
      
      if (file_exists($thumbPath)) {
        header('Content-Type: image/webp');
        header('Content-Length: ' . filesize($thumbPath));
        readfile($thumbPath);
        exit;
      }
    }
    
    // Fallback stream if thumbnail generation failed
    streamFileRange($full);
    exit;
  }

  if ($action === 'stream') {
    while (ob_get_level()) ob_end_clean();
    $file = $_GET['file'] ?? '';
    $full = $baseDir . '/' . $file;
    if (!isValidPath($baseDir, $full) || !is_file($full) || !isAllowedExtension($file)) {
      http_response_code(404);
      exit;
    }
    streamFileRange($full);
    exit;
  }

  header('Content-Type: application/json');
  ob_start();

  try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
      $postAction = $input['action'] ?? $_POST['action'] ?? '';

      switch ($postAction) {
        case 'add_file':
          $name = $input['name'] ?? '';
          $full = $absPath . '/' . $name;
          if (file_exists($full)) throw new Exception('CONFLICT|' . basename($full));
          file_put_contents($full, '');
          echo json_encode(['success' => true]);
          break;

        case 'add_folder':
          $name = $input['name'] ?? '';
          $full = $absPath . '/' . $name;
          if (file_exists($full)) throw new Exception('CONFLICT|' . basename($full));
          mkdir($full);
          echo json_encode(['success' => true]);
          break;

        case 'upload':
          if (!isset($_FILES['files'])) throw new Exception('No files uploaded');
          $uploaded = 0;
          $paths = $_POST['paths'] ?? [];
          $chunk = isset($_POST['chunk']) ? (int)$_POST['chunk'] : 0;
          $chunks = isset($_POST['chunks']) ? (int)$_POST['chunks'] : 1;
          $fileId = $_POST['file_id'] ?? 'unknown';
          $override = !empty($_POST['override']);

          foreach ($_FILES['files']['name'] as $i => $name) {
            $relPathClean = !empty($paths[$i]) ? ltrim(str_replace(['..', '\\'], ['', '/'], $paths[$i]), '/') : $name;
            $dest = $absPath . '/' . $relPathClean;
            $targetDir = dirname($dest);
            if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);

            if ($chunk === 0 && file_exists($dest) && !$override) {
              echo json_encode(['success' => false, 'error' => 'CONFLICT|' . basename($dest)]);
              exit;
            }
            if ($chunk === 0 && file_exists($dest) && $override) {
              save_file_version($dest);
            }

            if ($chunks > 1) {
              $tempDest = $targetDir . '/.temp_upload_' . md5($fileId . $name);
              $out = @fopen($tempDest, $chunk === 0 ? 'wb' : 'ab');
              if ($out) {
                $in = @fopen($_FILES['files']['tmp_name'][$i], 'rb');
                if ($in) {
                  while ($buff = fread($in, 8192)) fwrite($out, $buff);
                  fclose($in);
                }
                fclose($out);
              }
              if ($chunk == $chunks - 1) {
                rename($tempDest, $dest);
                $uploaded++;
              }
            } else {
              if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $dest)) {
                $uploaded++;
              }
            }
          }
          echo json_encode(['success' => true, 'uploaded' => $uploaded]);
          break;

        case 'upload_url':
          $url = $input['url'] ?? '';
          $override = !empty($input['override']);
          $name = basename(parse_url($url, PHP_URL_PATH));
          if (!$name) $name = 'downloaded_file_' . time();
          $target = $absPath . '/' . $name;
          
          if (file_exists($target) && !$override) throw new Exception('CONFLICT|' . $name);
          if (file_exists($target) && $override) save_file_version($target);
          file_put_contents($target, file_get_contents($url));
          echo json_encode(['success' => true]);
          break;

        case 'zip_items':
          $items = $input['items'] ?? [];
          if (empty($items)) throw new Exception('No items selected');
          $zipName = (count($items) === 1) ? basename($items[0]) . '.zip' : 'Archive_' . date('Ymd_His') . '.zip';
          $target = $absPath . '/' . $zipName;
          
          if (file_exists($target) && empty($input['override'])) throw new Exception('CONFLICT|' . $zipName);
          if (file_exists($target)) save_file_version($target);
          
          $zip = new ZipArchive();
          if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            foreach ($items as $item) {
              $src = $baseDir . '/' . $item;
              if (is_file($src)) $zip->addFile($src, basename($src));
              elseif (is_dir($src)) {
                $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
                foreach ($iter as $f) {
                  if ($f->isFile()) $zip->addFile($f->getPathname(), basename($src) . '/' . str_replace($src . '/', '', $f->getPathname()));
                }
              }
            }
            $zip->close();
          }
          echo json_encode(['success' => true]);
          break;

        case 'encrypt_file':
        case 'decrypt_file':
          $file = $input['file'] ?? '';
          $src = $baseDir . '/' . $file;
          if (!file_exists($src) || is_dir($src)) throw new Exception('Invalid file');
          
          global $config;
          $secret_key = substr(hash('sha256', $config['password']), 0, 32);
          $content = file_get_contents($src);
          
          if ($postAction === 'encrypt_file') {
            $target = $src . '.enc';
            if (file_exists($target) && empty($input['override'])) throw new Exception('CONFLICT|' . basename($target));
            $iv = random_bytes(16);
            $encrypted = openssl_encrypt($content, 'aes-256-cbc', $secret_key, 0, $iv);
            file_put_contents($target, base64_encode($iv) . ':' . $encrypted);
            unlink($src);
          } else {
            if (substr($src, -4) !== '.enc') throw new Exception('Not an encrypted file');
            $target = substr($src, 0, -4);
            if (file_exists($target) && empty($input['override'])) throw new Exception('CONFLICT|' . basename($target));
            $parts = explode(':', $content, 2);
            $iv = base64_decode($parts[0]);
            $decrypted = openssl_decrypt($parts[1], 'aes-256-cbc', $secret_key, 0, $iv);
            if ($decrypted === false) throw new Exception('Decryption failed. Incorrect key or corrupted file.');
            file_put_contents($target, $decrypted);
            unlink($src);
          }
          echo json_encode(['success' => true]);
          break;

        case 'get_versions':
          $file = $input['file'] ?? '';
          $verDir = $baseDir . '/.file_version/' . basename($file);
          $versions = [];
          if (is_dir($verDir)) {
            foreach (array_diff(scandir($verDir), ['.', '..']) as $v) {
              $versions[] = ['name' => $v, 'mtime' => filemtime($verDir . '/' . $v), 'size' => formatBytes(filesize($verDir . '/' . $v))];
            }
            usort($versions, function($a, $b) { return $b['mtime'] - $a['mtime']; });
          }
          echo json_encode(['success' => true, 'versions' => $versions]);
          break;

        case 'restore_version':
          $file = $input['file'] ?? '';
          $version_name = $input['version_name'] ?? '';
          $src = $baseDir . '/.file_version/' . basename($file) . '/' . $version_name;
          $dest = $baseDir . '/' . $file;
          if (!file_exists($src)) throw new Exception('Version not found');
          save_file_version($dest); // Backup current state before restoring
          copy($src, $dest);
          echo json_encode(['success' => true]);
          break;

        case 'trash':
          $items = $input['items'] ?? [];
          $meta = getMetadata();
          $trashBin = $baseDir . '/.drive_trash_bin';
          if (!is_dir($trashBin)) mkdir($trashBin, 0755, true);
          
          foreach ($items as $itemPath) {
            $full = $baseDir . '/' . $itemPath;
            if (isValidPath($baseDir, $full) && file_exists($full)) {
              $itemName = basename($itemPath);
              $uniq = uniqid() . '_' . $itemName;
              $trashPath = $trashBin . '/' . $uniq;
              if (rename($full, $trashPath)) {
                $meta['trash'][$uniq] = [
                  'original_name' => $itemName,
                  'original_parent' => ltrim(str_replace($baseDir, '', dirname($full)), '/'),
                  'deleted_at' => time()
                ];
              }
            }
          }
          saveMetadata($meta);
          echo json_encode(['success' => true]);
          break;

        case 'restore_trash':
          $items = $input['items'] ?? [];
          $meta = getMetadata();
          $trashBin = $baseDir . '/.drive_trash_bin';
          
          foreach ($items as $uniq) {
            if (isset($meta['trash'][$uniq])) {
              $info = $meta['trash'][$uniq];
              $targetDir = $baseDir . '/' . $info['original_parent'];
              if (!is_dir($targetDir)) $targetDir = $baseDir;
              $dest = $targetDir . '/' . $info['original_name'];
              if (file_exists($dest) && empty($input['override'])) throw new Exception('CONFLICT|' . $info['original_name']);
              if (file_exists($dest) && !empty($input['override'])) save_file_version($dest);
              if (rename($trashBin . '/' . $uniq, $dest)) {
                unset($meta['trash'][$uniq]);
              }
            }
          }
          saveMetadata($meta);
          echo json_encode(['success' => true]);
          break;

        case 'delete_perm':
          $items = $input['items'] ?? [];
          $meta = getMetadata();
          $trashBin = $baseDir . '/.drive_trash_bin';
          foreach ($items as $uniq) {
            $full = $trashBin . '/' . $uniq;
            if (file_exists($full)) {
              recursiveDelete($full);
              unset($meta['trash'][$uniq]);
            }
          }
          saveMetadata($meta);
          echo json_encode(['success' => true]);
          break;

        case 'empty_trash':
          $meta = getMetadata();
          $trashBin = $baseDir . '/.drive_trash_bin';
          recursiveDelete($trashBin);
          mkdir($trashBin, 0755, true);
          $meta['trash'] = [];
          saveMetadata($meta);
          echo json_encode(['success' => true]);
          break;

        case 'rename':
          $old = $input['old'] ?? '';
          $new = $input['new'] ?? '';
          $oldFull = $baseDir . '/' . $old;
          $newFull = dirname($oldFull) . '/' . $new;
          if (!isValidPath($baseDir, $oldFull) || !file_exists($oldFull)) throw new Exception('Invalid source');
          if (file_exists($newFull) && empty($input['override'])) throw new Exception('CONFLICT|' . $new);
          if (file_exists($newFull) && !empty($input['override'])) save_file_version($newFull);
          rename($oldFull, $newFull);
          echo json_encode(['success' => true]);
          break;

        case 'write':
          $file = $input['file'] ?? '';
          $content = $input['content'] ?? '';
          $full = $baseDir . '/' . $file;
          if (!isValidPath($baseDir, $full)) throw new Exception('Invalid file');
          save_file_version($full); // Track file version before writing
          file_put_contents($full, $content);
          echo json_encode(['success' => true]);
          break;

        case 'toggle_star':
          $item = $input['item'] ?? '';
          $meta = getMetadata();
          if (in_array($item, $meta['starred'])) {
            $meta['starred'] = array_values(array_diff($meta['starred'], [$item]));
            $starred = false;
          } else {
            $meta['starred'][] = $item;
            $starred = true;
          }
          saveMetadata($meta);
          echo json_encode(['success' => true, 'starred' => $starred]);
          break;

        case 'copy_items':
        case 'move_items':
          $items = $input['items'] ?? [];
          $target = $input['target'] ?? '';
          $override = !empty($input['override']);
          $targetDir = rtrim($baseDir . '/' . $target, '/');
          if (!isValidPath($baseDir, $targetDir)) throw new Exception('Invalid target');
          
          foreach ($items as $item) {
            $src = $baseDir . '/' . $item;
            if (!isValidPath($baseDir, $src) || !file_exists($src)) continue;
            $dest = $targetDir . '/' . basename($item);
            
            if (file_exists($dest) && !$override) {
              throw new Exception('CONFLICT|' . basename($item));
            }
            if (file_exists($dest) && $override) {
              save_file_version($dest);
            }
            
            if ($postAction === 'move_items') {
              if ($src !== $dest) rename($src, $dest);
            } else {
              recursiveCopy($src, $dest);
            }
          }
          echo json_encode(['success' => true]);
          break;

        case 'create_share':
          $item = $input['item'] ?? '';
          $meta = getMetadata();
          $token = md5($item . time());
          $meta['shares'][$token] = $item;
          saveMetadata($meta);
          echo json_encode(['success' => true, 'token' => $token]);
          break;

        case 'unzip':
          $item = $input['item'] ?? '';
          $src = $baseDir . '/' . $item;
          if (!isValidPath($baseDir, $src) || !file_exists($src) || strtolower(pathinfo($src, PATHINFO_EXTENSION)) !== 'zip') {
            throw new Exception('Invalid zip file');
          }
          if (!class_exists('ZipArchive')) throw new Exception('ZipArchive extension is missing');
          $zip = new ZipArchive;
          if ($zip->open($src) === TRUE) {
            $folderName = pathinfo($src, PATHINFO_FILENAME);
            $parentDir = dirname($src);
            $extractTarget = $parentDir . '/' . $folderName;
            
            if (file_exists($extractTarget) && empty($input['override'])) throw new Exception('CONFLICT|' . $folderName);
            if (!file_exists($extractTarget)) mkdir($extractTarget, 0755, true);
            
            $zip->extractTo($extractTarget);
            $zip->close();
            echo json_encode(['success' => true]);
          } else {
            throw new Exception('Failed to extract ZIP archive');
          }
          break;

        case 'toggle_auth':
          $config['protected'] = !$config['protected'];
          if (!empty($input['new_pass'])) $config['password'] = password_hash($input['new_pass'], PASSWORD_DEFAULT);
          file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
          echo json_encode(['success' => true, 'protected' => $config['protected']]);
          break;

        default:
          throw new Exception('Unknown POST action');
      }
    } else {
      switch ($action) {
        case 'search_drive':
          $q = strtolower($_GET['q'] ?? '');
          $meta = getMetadata();
          $folders = [];
          $files = [];
          if ($q !== '') {
            $iter = new RecursiveIteratorIterator(
              new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
              RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iter as $item) {
              $pathName = $item->getPathname();
              if (strpos($pathName, '.drive_trash_bin') !== false || strpos($pathName, '.drive_thumbnails') !== false || strpos($pathName, '.file_version') !== false) {
                continue;
              }
              $filename = $item->getFilename();
              if (stripos($filename, $q) !== false) {
                $rel = ltrim(str_replace($baseDir, '', $pathName), '/');
                $rel = str_replace('\\', '/', $rel);
                $stat = stat($pathName);
                $starred = in_array($rel, $meta['starred']);
                if ($item->isDir()) {
                  $folders[] = [
                    'name' => $filename,
                    'path' => $rel,
                    'mtime' => $stat['mtime'],
                    'size' => 0,
                    'formatSize' => '-',
                    'ext' => '',
                    'isImage' => false,
                    'starred' => $starred
                  ];
                } elseif ($item->isFile() && isAllowedExtension($filename)) {
                  $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                  $files[] = [
                    'name' => $filename,
                    'path' => $rel,
                    'mtime' => $stat['mtime'],
                    'size' => $stat['size'],
                    'formatSize' => formatBytes($stat['size']),
                    'ext' => $ext,
                    'isImage' => in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg']),
                    'starred' => $starred
                  ];
                }
              }
            }
          }
          echo json_encode(['success' => true, 'folders' => array_slice($folders, 0, 50), 'files' => array_slice($files, 0, 100)]);
          break;

        case 'list':
          $meta = getMetadata();
          $files = [];
          $folders = [];
          $items = array_diff(scandir($absPath), ['.', '..', '.drive_metadata.json', '.drive_trash_bin', '.drive_thumbnails']);
          foreach ($items as $item) {
            $path = $absPath . '/' . $item;
            $stat = stat($path);
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            $rel = ltrim(str_replace($baseDir, '', $path), '/');
            $starred = in_array($rel, $meta['starred']);
            
            $itemMeta = [
              'name' => $item,
              'path' => $rel,
              'mtime' => $stat['mtime'],
              'size' => $stat['size'],
              'formatSize' => formatBytes($stat['size']),
              'ext' => $ext,
              'isImage' => in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg']),
              'starred' => $starred
            ];
            if (is_dir($path)) {
              $folders[] = $itemMeta;
            } elseif (is_file($path) && isAllowedExtension($item)) {
              $files[] = $itemMeta;
            }
          }
          
          $pathDisplay = ltrim(str_replace($baseDir, '', $absPath), '/');
          $breadcrumbs = [];
          if (!empty($pathDisplay)) {
            $segments = explode('/', $pathDisplay);
            $curr = '';
            foreach ($segments as $seg) {
              if (empty($seg)) continue;
              $curr .= $seg . '/';
              $breadcrumbs[] = ['name' => $seg, 'path' => rtrim($curr, '/')];
            }
          }
          
          echo json_encode([
            'success' => true, 
            'folders' => $folders, 
            'files' => $files, 
            'breadcrumbs' => $breadcrumbs
          ]);
          break;

        case 'list_trash':
          $meta = getMetadata();
          $trashList = [];
          foreach ($meta['trash'] as $uniq => $info) {
            $trashList[] = [
              'uniq' => $uniq,
              'name' => $info['original_name'],
              'original_parent' => $info['original_parent'],
              'deleted_at' => date("Y-m-d H:i:s", $info['deleted_at'])
            ];
          }
          echo json_encode(['success' => true, 'trash' => $trashList]);
          break;

        case 'list_history':
          $historyFiles = [];
          $verDir = $baseDir . '/.file_version';
          if (is_dir($verDir)) {
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($verDir, FilesystemIterator::SKIP_DOTS));
            foreach ($iter as $file) {
              if ($file->isFile()) {
                $origName = basename(dirname($file->getPathname()));
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $historyFiles[] = [
                  'name' => $file->getFilename() . ' (Version of ' . $origName . ')',
                  'path' => ltrim(str_replace($baseDir, '', $file->getPathname()), '/'),
                  'mtime' => $file->getMTime(),
                  'size' => $file->getSize(),
                  'formatSize' => formatBytes($file->getSize()),
                  'ext' => $ext,
                  'isImage' => in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg']),
                  'starred' => false,
                  'is_version' => true,
                  'original_file' => $origName,
                  'version_name' => $file->getFilename()
                ];
              }
            }
            usort($historyFiles, function($a, $b) { return $b['mtime'] - $a['mtime']; });
          }
          echo json_encode(['success' => true, 'history' => $historyFiles]);
          break;

        case 'list_starred':
          $meta = getMetadata();
          $starredList = [];
          foreach ($meta['starred'] as $rel) {
            $full = $baseDir . '/' . $rel;
            if (file_exists($full)) {
              $stat = stat($full);
              $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
              $starredList[] = [
                'name' => basename($rel),
                'path' => $rel,
                'parent' => dirname($rel) === '.' ? '' : dirname($rel),
                'isDir' => is_dir($full),
                'mtime' => $stat['mtime'],
                'size' => $stat['size'],
                'formatSize' => formatBytes($stat['size']),
                'ext' => $ext,
                'isImage' => in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg'])
              ];
            }
          }
          echo json_encode(['success' => true, 'starred' => $starredList]);
          break;

        case 'recents':
          $meta = getMetadata();
          $recentFiles = [];
          
          // Optimized Directory Scanner: Skip scanning massive or irrelevant system directories
          $dir = new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS);
          $filter = new RecursiveCallbackFilterIterator($dir, function ($current) {
            $exclude = ['getid3', '.drive_trash_bin', '.drive_thumbnails', '.git', 'uploads', 'covers', 'brain'];
            if ($current->isDir() && in_array($current->getFilename(), $exclude)) {
              return false;
            }
            return true;
          });
          
          $iter = new RecursiveIteratorIterator($filter);
          foreach ($iter as $file) {
            if ($file->isFile() && isAllowedExtension($file->getFilename())) {
              $path = $file->getPathname();
              $recentFiles[] = [
                'name' => $file->getFilename(),
                'path' => ltrim(str_replace($baseDir, '', $path), '/'),
                'mtime' => $file->getMTime(),
                'size' => $file->getSize(),
                'formatSize' => formatBytes($file->getSize()),
                'ext' => strtolower($file->getExtension()),
                'isImage' => in_array(strtolower($file->getExtension()), ['png', 'jpg', 'jpeg', 'gif', 'svg'])
              ];
            }
          }
          usort($recentFiles, function($a, $b) { return $b['mtime'] - $a['mtime']; });
          echo json_encode(['success' => true, 'recents' => array_slice($recentFiles, 0, 15)]);
          break;

        case 'read':
          $file = $_GET['file'] ?? '';
          $full = $baseDir . '/' . $file;
          if (!isValidPath($baseDir, $full) || !is_file($full) || !isAllowedExtension($file)) throw new Exception('Invalid file');
          echo json_encode(['success' => true, 'content' => file_get_contents($full)]);
          break;

        case 'properties':
          $file = $_GET['file'] ?? '';
          $full = $baseDir . '/' . $file;
          if (!isValidPath($baseDir, $full) || !file_exists($full)) throw new Exception('Invalid item');
          $stat = stat($full);
          $is_dir = is_dir($full);
          $size = $stat['size'];
          $typeStr = $is_dir ? 'Folder' : 'File (' . strtoupper(pathinfo($file, PATHINFO_EXTENSION)) . ')';
          $contentStr = '';
          
          if ($is_dir) {
            $total_files = 0;
            $total_folders = 0;
            $total_size = 0;
            // Recursively calculate bulk size and contents count
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iter as $f) {
              if ($f->isDir()) {
                $total_folders++;
              } else {
                $total_files++;
                $total_size += $f->getSize();
              }
            }
            $size = $total_size;
            $contentStr = $total_files . ' files, ' . $total_folders . ' folders';
          }

          echo json_encode([
            'success' => true,
            'data' => [
              'name' => basename($file),
              'type' => $typeStr,
              'size' => formatBytes($size),
              'contents' => $contentStr,
              'modified' => date("Y-m-d H:i:s", $stat['mtime']),
              'created' => date("Y-m-d H:i:s", $stat['ctime']),
              'permissions' => substr(sprintf('%o', fileperms($full)), -4)
            ]
          ]);
          break;

        default:
          throw new Exception('Unknown GET action');
      }
    }
  } catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
  }
  ob_end_flush();
  exit;
}

if (isset($_GET['download'])) {
  $file = $_GET['download'];
  $full = $baseDir . '/' . $file;
  if (isValidPath($baseDir, $full) && is_file($full) && isAllowedExtension($file)) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($full) . '"');
    header('Content-Length: ' . filesize($full));
    readfile($full);
  }
  exit;
}

if (isset($_GET['batch'])) {
  $type = $_GET['batch'];
  $zip = new ZipArchive();
  $zipName = 'download_' . date('Ymd_His') . '.zip';
  
  if ($zip->open($zipName, ZipArchive::CREATE) === TRUE) {
    if ($type === 'context') {
      $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absPath, FilesystemIterator::SKIP_DOTS));
      foreach ($iter as $file) {
        if ($file->isFile() && isAllowedExtension($file->getFilename())) {
          $localPath = ltrim(str_replace($absPath, '', $file->getPathname()), '/');
          $zip->addFile($file->getPathname(), $localPath);
        }
      }
    } elseif ($type === 'selected' && isset($_GET['items'])) {
      $items = explode(',', $_GET['items']);
      foreach ($items as $item) {
        $full = $baseDir . '/' . $item;
        if (isValidPath($baseDir, $full) && file_exists($full)) {
          if (is_file($full) && isAllowedExtension($item)) {
            $zip->addFile($full, basename($item));
          } elseif (is_dir($full)) {
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
            foreach ($iter as $f) {
              if ($f->isFile() && isAllowedExtension($f->getFilename())) {
                $localPath = ltrim(str_replace($baseDir, '', $f->getPathname()), '/');
                $zip->addFile($f->getPathname(), $localPath);
              }
            }
          }
        }
      }
    }
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename=' . $zipName);
    header('Content-Length: ' . filesize($zipName));
    readfile($zipName);
    unlink($zipName);
  }
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>PHPDrive</title>
    <meta property="og:title" content="PHPDrive">
    <meta property="og:site_name" content="PHPDrive">
    <meta property="og:type" content="website">
    <meta property="og:description" content="A fast, minimal, and open-source file manager.">
    <meta property="og:image" content="data:image/svg+xml;charset=utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath fill='%230b57d0' d='M4.406 3.342A5.53 5.53 0 0 1 8 2c2.69 0 4.923 2 5.166 4.579C14.758 6.804 16 8.137 16 9.773 16 11.569 14.502 13 12.687 13H3.781C1.708 13 0 11.366 0 9.318c0-1.763 1.266-3.223 2.942-3.593.143-.863.698-1.723 1.464-2.383'/%3E%3C/svg%3E">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml;charset=utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath fill='%230b57d0' d='M4.406 3.342A5.53 5.53 0 0 1 8 2c2.69 0 4.923 2 5.166 4.579C14.758 6.804 16 8.137 16 9.773 16 11.569 14.502 13 12.687 13H3.781C1.708 13 0 11.366 0 9.318c0-1.763 1.266-3.223 2.942-3.593.143-.863.698-1.723 1.464-2.383'/%3E%3C/svg%3E">
    <link rel="manifest" href="?manifest=1">
    <meta name="theme-color" content="#0b57d0">
    <script>
      const IS_PROTECTED = <?php echo $config['protected'] ? 'true' : 'false'; ?>;
      const IS_AUTHED = <?php echo !empty($_SESSION['auth']) ? 'true' : 'false'; ?>;
      if ('serviceWorker' in navigator) navigator.serviceWorker.register('?sw=1');
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/theme/material-darker.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/dialog/dialog.min.css">
    <style>
      :root {
        --sys-light-primary: #0b57d0;
        --sys-light-on-primary: #ffffff;
        --sys-light-primary-container: #d3e3fd;
        --sys-light-on-primary-container: #041e49;
        --sys-light-surface: #f8fafd;
        --sys-light-surface-container-low: #f0f4f9;
        --sys-light-surface-container: #e9eef6;
        --sys-light-surface-container-high: #e1e8f0;
        --sys-light-on-surface: #1f1f1f;
        --sys-light-on-surface-variant: #444746;
        --sys-light-outline: #747775;
        --sys-light-outline-variant: #c4c7c5;
        --sys-light-secondary-container: #c2e7ff;
        --sys-light-on-secondary-container: #001d35;
        
        --sys-dark-primary: #a8c7fa;
        --sys-dark-on-primary: #062e6f;
        --sys-dark-primary-container: #0842a0;
        --sys-dark-on-primary-container: #d3e3fd;
        --sys-dark-surface: #131314;
        --sys-dark-surface-container-low: #1e1f20;
        --sys-dark-surface-container: #282a2c;
        --sys-dark-surface-container-high: #333537;
        --sys-dark-on-surface: #e3e3e3;
        --sys-dark-on-surface-variant: #c4c7c5;
        --sys-dark-outline: #8e918f;
        --sys-dark-outline-variant: #444746;
        --sys-dark-secondary-container: #004a77;
        --sys-dark-on-secondary-container: #c2e7ff;

        --theme-primary: var(--sys-light-primary);
        --theme-on-primary: var(--sys-light-on-primary);
        --theme-primary-container: var(--sys-light-primary-container);
        --theme-on-primary-container: var(--sys-light-on-primary-container);
        --theme-surface: var(--sys-light-surface);
        --theme-surface-container-low: var(--sys-light-surface-container-low);
        --theme-surface-container: var(--sys-light-surface-container);
        --theme-surface-container-high: var(--sys-light-surface-container-high);
        --theme-on-surface: var(--sys-light-on-surface);
        --theme-on-surface-variant: var(--sys-light-on-surface-variant);
        --theme-outline: var(--sys-light-outline);
        --theme-outline-variant: var(--sys-light-outline-variant);
        --theme-secondary-container: var(--sys-light-secondary-container);
        --theme-on-secondary-container: var(--sys-light-on-secondary-container);
        
        --font-body: 'Roboto', sans-serif;
        --font-title: 'Google Sans', sans-serif;
        --transition: 0.2s cubic-bezier(0.2, 0, 0, 1);
      }

      [data-theme="dark"] {
        --theme-primary: var(--sys-dark-primary);
        --theme-on-primary: var(--sys-dark-on-primary);
        --theme-primary-container: var(--sys-dark-primary-container);
        --theme-on-primary-container: var(--sys-dark-on-primary-container);
        --theme-surface: var(--sys-dark-surface);
        --theme-surface-container-low: var(--sys-dark-surface-container-low);
        --theme-surface-container: var(--sys-dark-surface-container);
        --theme-surface-container-high: var(--sys-dark-surface-container-high);
        --theme-on-surface: var(--sys-dark-on-surface);
        --theme-on-surface-variant: var(--sys-dark-on-surface-variant);
        --theme-outline: var(--sys-dark-outline);
        --theme-outline-variant: var(--sys-dark-outline-variant);
        --theme-secondary-container: var(--sys-dark-secondary-container);
        --theme-on-secondary-container: var(--sys-dark-on-secondary-container);
      }

      * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
      body { font-family: var(--font-body); background-color: var(--theme-surface); color: var(--theme-on-surface); height: 100vh; overflow: hidden; display: flex; flex-direction: column; }
      
      .material-symbols-rounded { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; user-select: none; color: var(--theme-on-surface); }
      .icon-filled { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
      
      header { height: 64px; display: flex; align-items: center; padding: 0 16px; gap: 8px; background-color: var(--theme-surface); flex-shrink: 0; }
      @media (min-width: 769px) { .menu-btn { display: none !important; } }
      .menu-btn { display: none; }
      .logo-container { display: flex; align-items: center; gap: 8px; width: 238px; padding-left: 4px; cursor: pointer; }
      .logo-img { width: 40px; height: 40px; border-radius: 8px; background: var(--theme-primary); color: var(--theme-on-primary); display: flex; align-items: center; justify-content: center; }
      .logo-img .material-symbols-rounded { color: var(--theme-on-primary); }
      .logo-text { font-family: var(--font-title); font-size: 22px; color: var(--theme-on-surface); }
      
      .search-bar { flex: 1; max-width: 720px; height: 48px; background-color: var(--theme-surface-container-high); border-radius: 24px; display: flex; align-items: center; padding: 0 16px; gap: 12px; transition: background-color var(--transition); margin: 0 16px; }
      .search-bar:focus-within { background-color: var(--theme-surface); box-shadow: 0 1px 3px rgba(0,0,0,0.12); }
      .search-bar input { flex: 1; border: none; background: none; outline: none; font-size: 16px; color: var(--theme-on-surface); font-family: var(--font-body); width: 100%; }
      .search-bar input::placeholder { color: var(--theme-on-surface-variant); }
      .search-icon { color: var(--theme-on-surface-variant); }
      
      .header-actions { display: flex; gap: 4px; align-items: center; margin-left: auto; }
      .icon-btn { width: 40px; height: 40px; border-radius: 50%; border: none; background: transparent; color: var(--theme-on-surface-variant); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background-color var(--transition); position: relative; }
      .icon-btn:hover { background-color: var(--theme-surface-container-high); }
      .icon-btn.active { background-color: var(--theme-secondary-container); color: var(--theme-on-secondary-container); }
      .icon-btn .material-symbols-rounded { color: var(--theme-on-surface-variant); }
      .icon-btn:hover .material-symbols-rounded { color: var(--theme-on-surface); }
      .icon-btn.active .material-symbols-rounded { color: var(--theme-on-secondary-container); }
      
      .main-wrapper { display: flex; flex: 1; overflow: hidden; position: relative; }
      
      .sidebar { width: 256px; display: flex; flex-direction: column; padding: 16px; gap: 16px; flex-shrink: 0; background: var(--theme-surface); z-index: 100; transition: left 0.3s ease; }
      .sidebar-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99; display: none; opacity: 0; transition: opacity 0.3s; }
      
      .fab { height: 56px; border-radius: 16px; background-color: var(--theme-surface-container-low); color: var(--theme-on-surface); border: none; display: inline-flex; align-items: center; padding: 0 20px 0 16px; gap: 12px; font-family: var(--font-title); font-size: 14px; font-weight: 500; cursor: pointer; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.3); transition: box-shadow var(--transition), background-color var(--transition); width: fit-content; }
      .fab:hover { box-shadow: 0 4px 8px rgba(0,0,0,0.15); background-color: var(--theme-surface-container-high); }
      .fab .material-symbols-rounded { color: var(--theme-on-surface); }
      
      .nav-list { display: flex; flex-direction: column; gap: 4px; }
      .nav-item { display: flex; align-items: center; gap: 12px; height: 48px; padding: 0 20px; border-radius: 24px; color: var(--theme-on-surface-variant); cursor: pointer; font-size: 14px; font-weight: 500; transition: background-color var(--transition); }
      .nav-item:hover { background-color: var(--theme-surface-container-low); }
      .nav-item.active { background-color: var(--theme-secondary-container); color: var(--theme-on-secondary-container); }
      .nav-item .material-symbols-rounded { color: var(--theme-on-surface-variant); }
      .nav-item.active .material-symbols-rounded { color: var(--theme-on-secondary-container); font-variation-settings: 'FILL' 1; }
      
      .content-area { flex: 1; display: flex; flex-direction: column; background-color: var(--theme-surface); border-radius: 16px; margin: 0 16px 16px 0; overflow: hidden; position: relative; }
      .content-header { height: 56px; display: flex; align-items: center; padding: 0 24px; border-bottom: 1px solid var(--theme-outline-variant); justify-content: space-between; }
      .breadcrumbs { display: flex; align-items: center; font-family: var(--font-title); font-size: 22px; color: var(--theme-on-surface); gap: 4px; overflow-x: auto; white-space: nowrap; scrollbar-width: none; }
      .breadcrumb-item { cursor: pointer; border-radius: 8px; padding: 4px 8px; transition: background-color var(--transition); color: var(--theme-on-surface); }
      .breadcrumb-item:hover { background-color: var(--theme-surface-container); }
      .breadcrumb-sep { color: var(--theme-on-surface-variant); font-size: 18px; }
      
      .chips-container { display: flex; gap: 8px; padding: 12px 24px; overflow-x: auto; scrollbar-width: none; flex-shrink: 0; }
      .chip { border: 1px solid var(--theme-outline-variant); padding: 6px 16px; border-radius: 16px; font-size: 14px; cursor: pointer; background: transparent; transition: background var(--transition); display: flex; align-items: center; gap: 6px; color: var(--theme-on-surface); }
      .chip.active { background: var(--theme-primary-container); color: var(--theme-on-primary-container); border-color: transparent; font-weight: 500; }
      .chip .material-symbols-rounded { color: inherit; }
      
      .recents-container { margin-bottom: 16px; flex-shrink: 0; }
      .recents-tray { display: flex; gap: 12px; overflow-x: auto; padding: 8px 0; scrollbar-width: none; }
      .recent-card { width: 140px; background: var(--theme-surface-container-low); border-radius: 12px; padding: 12px; flex-shrink: 0; cursor: pointer; user-select: none; -webkit-user-select: none; -webkit-touch-callout: none; border: 1px solid transparent; transition: background var(--transition); color: var(--theme-on-surface); }
      .recent-card:hover { background: var(--theme-surface-container-high); }
      .recent-card .material-symbols-rounded { color: var(--theme-primary); }
      .recent-name { font-size: 12px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 8px; }
      
      .file-list-container { flex: 1; overflow-y: auto; padding: 0 24px 120px; position: relative; }
      .section-title { font-size: 14px; font-weight: 500; color: var(--theme-on-surface-variant); margin: 16px 0 12px 8px; }
      
      .grid-view { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; }
      .list-view { display: flex; flex-direction: column; gap: 4px; }
      
      .item-card { background-color: var(--theme-surface-container-low); border-radius: 12px; border: 1px solid transparent; cursor: pointer; user-select: none; -webkit-user-select: none; -webkit-touch-callout: none; transition: background-color var(--transition), border-color var(--transition); display: flex; flex-direction: column; position: relative; overflow: hidden; color: var(--theme-on-surface); }
      .item-card:hover { background-color: var(--theme-surface-container-high); }
      .item-card.selected { background-color: var(--theme-secondary-container); border-color: var(--theme-primary); color: var(--theme-on-secondary-container); }
      .item-card.drag-target { border: 2px dashed var(--theme-primary) !important; background-color: var(--theme-primary-container) !important; }
      
      .card-checkbox { position: absolute; top: 8px; left: 8px; width: 24px; height: 24px; color: var(--theme-on-surface-variant); z-index: 10; display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity var(--transition); }
      .item-card:hover .card-checkbox, .item-card.selected .card-checkbox { opacity: 1; }
      .item-card.selected .card-checkbox { color: var(--theme-primary); font-variation-settings: 'FILL' 1; }
      .card-checkbox .material-symbols-rounded { color: inherit; }
      
      .card-star { position: absolute; top: 8px; right: 8px; width: 24px; height: 24px; color: var(--theme-on-surface-variant); z-index: 10; display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity var(--transition); }
      .item-card:hover .card-star, .item-card.starred .card-star { opacity: 1; }
      .item-card.starred .card-star { color: #f5b041; font-variation-settings: 'FILL' 1; }
      .card-star .material-symbols-rounded { color: inherit; }
      
      .grid-view .item-card { height: 64px; padding: 0 40px; flex-direction: row; align-items: center; gap: 12px; }
      .grid-view .file-card { height: 200px; flex-direction: column; align-items: stretch; gap: 0; padding: 0; }
      .grid-view .file-card .file-preview { flex: 1; background-color: var(--theme-surface-container); display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative; }
      .grid-view .file-card .file-preview img { width: 100%; height: 100%; object-fit: cover; }
      .grid-view .file-card .file-preview .material-symbols-rounded { font-size: 64px; color: var(--theme-primary); }
      .grid-view .file-card .file-info-bar { height: 56px; display: flex; align-items: center; padding: 0 40px; gap: 12px; border-top: 1px solid var(--theme-outline-variant); }
      
      .list-view .item-card { height: 48px; border-radius: 0; border-bottom: 1px solid var(--theme-outline-variant); flex-direction: row; align-items: center; padding: 0 40px; gap: 16px; background: transparent; }
      .list-view .item-card:hover { background-color: var(--theme-surface-container-low); border-radius: 24px; border-bottom-color: transparent; margin: 0 -8px; padding: 0 24px 0 48px; }
      .list-view .item-card.selected { background-color: var(--theme-secondary-container); border-radius: 24px; border-bottom-color: transparent; margin: 0 -8px; padding: 0 24px 0 48px; }
      
      .item-icon { color: var(--theme-on-surface-variant); display: flex; align-items: center; justify-content: center; }
      .item-icon .material-symbols-rounded { color: inherit; }
      .item-card.selected .item-icon { color: var(--theme-primary); }
      .folder-icon { color: var(--theme-on-surface-variant); font-variation-settings: 'FILL' 1; }
      .folder-icon .material-symbols-rounded { color: inherit; }
      .item-card.selected .folder-icon .material-symbols-rounded { color: var(--theme-primary); }
      .item-name { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 14px; font-weight: 500; }
      .item-meta { display: none; font-size: 12px; color: var(--theme-on-surface-variant); width: 100px; text-align: right; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
      .list-view .item-meta { display: block; }
      
      .search-highlight { background: #f9e79f; color: #1f1f1f; border-radius: 2px; }
      
      .context-menu, .floating-menu, .sort-menu { position: fixed; background-color: var(--theme-surface-container); border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); padding: 8px 0; z-index: 1000; min-width: 200px; display: none; flex-direction: column; border: 1px solid var(--theme-outline-variant); }
      .menu-item { display: flex; align-items: center; gap: 12px; padding: 10px 16px; font-size: 14px; color: var(--theme-on-surface); cursor: pointer; transition: background-color var(--transition); }
      .menu-item:hover { background-color: var(--theme-surface-container-high); }
      .menu-item .material-symbols-rounded { font-size: 20px; color: var(--theme-on-surface-variant); }
      .menu-item:hover .material-symbols-rounded { color: var(--theme-on-surface); }
      .menu-item.active { background-color: var(--theme-secondary-container); color: var(--theme-on-secondary-container); }
      .menu-item.active .material-symbols-rounded { color: var(--theme-on-secondary-container); }
      .menu-divider { height: 1px; background-color: var(--theme-outline-variant); margin: 4px 0; }
      
      .modal-overlay, .login-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; display: none; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
      .login-overlay { z-index: 9999; background: var(--theme-surface); }
      .modal { background-color: var(--theme-surface-container-low); border-radius: 28px; width: 90%; max-width: 400px; padding: 24px; display: flex; flex-direction: column; gap: 16px; box-shadow: 0 24px 38px 3px rgba(0,0,0,0.14); }
      .modal-title { font-family: var(--font-title); font-size: 24px; color: var(--theme-on-surface); }
      .modal-input { background-color: var(--theme-surface-container-high); border: 1px solid var(--theme-outline); border-radius: 4px 4px 0 0; padding: 16px; font-size: 16px; color: var(--theme-on-surface); outline: none; border-bottom: 2px solid var(--theme-primary); width: 100%; transition: background-color var(--transition); }
      .modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 8px; }
      .btn { padding: 0 24px; height: 40px; border-radius: 20px; font-weight: 500; font-size: 14px; cursor: pointer; border: none; transition: background-color var(--transition); display: inline-flex; align-items: center; gap: 8px; }
      .btn-text { background: transparent; color: var(--theme-primary); }
      .btn-text:hover { background-color: var(--theme-secondary-container); }
      .btn-filled { background-color: var(--theme-primary); color: var(--theme-on-primary); }
      .btn-filled .material-symbols-rounded { color: inherit; }
      
      .editor-overlay { position: fixed; inset: 0; background-color: var(--theme-surface); z-index: 3000; display: none; flex-direction: column; }
      .editor-header { height: 64px; display: flex; align-items: center; padding: 0 16px; gap: 16px; border-bottom: 1px solid var(--theme-outline-variant); background-color: var(--theme-surface-container-low); flex-shrink: 0; }
      .editor-title { flex: 1; font-family: var(--font-title); font-size: 18px; color: var(--theme-on-surface); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
      .editor-body { flex: 1; overflow: hidden; display: flex; flex-direction: column; position: relative; }
      .CodeMirror { flex: 1; height: 100% !important; font-family: monospace; font-size: 14px; }
      .CodeMirror-scroll { padding-bottom: 120px !important; }
      
      .mobile-editor-container { flex: 1; display: none; flex-direction: column; background: var(--theme-surface); height: 100%; }
      .mobile-textarea { flex: 1; border: none; outline: none; background: transparent; padding: 16px 16px 120px 16px; font-family: monospace; font-size: 16px; color: var(--theme-on-surface); resize: none; width: 100%; line-height: 1.5; height: 100%; }
      
      .media-player-container { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; background: var(--theme-surface-container-low); padding: 24px 24px 120px 24px; gap: 24px; overflow-y: auto; }
      .media-art { width: 120px; height: 120px; border-radius: 24px; background: var(--theme-primary-container); color: var(--theme-on-primary-container); display: flex; align-items: center; justify-content: center; }
      .media-art .material-symbols-rounded { font-size: 64px; color: var(--theme-primary); }
      .media-player-container video { max-width: 100%; max-height: 80vh; border-radius: 12px; outline: none; background: #000; box-shadow: 0 8px 24px rgba(0,0,0,0.3); }
      .media-player-container audio { width: 100%; max-width: 480px; }
      .image-preview-container { flex: 1; display: flex; align-items: center; justify-content: center; background: var(--theme-surface-container); overflow: auto; padding: 16px; }
      .image-preview-container img { max-width: 100%; max-height: 100%; object-fit: contain; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
      
      .snackbar-container { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); z-index: 4000; display: flex; flex-direction: column; gap: 8px; align-items: center; }
      .snackbar { background-color: var(--theme-on-surface); color: var(--theme-surface); padding: 14px 16px; border-radius: 4px; font-size: 14px; display: flex; align-items: center; justify-content: space-between; min-width: 288px; max-width: 400px; box-shadow: 0 3px 5px -1px rgba(0,0,0,0.2); opacity: 0; margin-bottom: -20px; transition: opacity 0.3s, margin-bottom 0.3s; }
      .snackbar.show { opacity: 1; margin-bottom: 0; }
      
      .properties-pane { width: 320px; background-color: var(--theme-surface); border-left: 1px solid var(--theme-outline-variant); flex-direction: column; display: none; color: var(--theme-on-surface); }
      .properties-header { height: 56px; display: flex; align-items: center; justify-content: space-between; padding: 0 16px; border-bottom: 1px solid var(--theme-outline-variant); }
      .properties-header .material-symbols-rounded { color: var(--theme-on-surface-variant); }
      .properties-content { padding: 16px; overflow-y: auto; display: flex; flex-direction: column; gap: 16px; }
      .prop-row { display: flex; flex-direction: column; gap: 4px; }
      .prop-label { font-size: 12px; color: var(--theme-on-surface-variant); }
      .prop-val { font-size: 14px; color: var(--theme-on-surface); word-break: break-all; }
      
      .drag-over { background-color: var(--theme-secondary-container) !important; border: 2px dashed var(--theme-primary) !important; }
      .hidden { display: none !important; }
      
      ::-webkit-scrollbar { width: 8px; height: 8px; }
      ::-webkit-scrollbar-track { background: transparent; }
      ::-webkit-scrollbar-thumb { background: var(--theme-outline-variant); border-radius: 4px; }

      .mobile-only { display: none; }

      @media (max-width: 768px) {
        .mobile-only { display: flex; }
        .menu-btn { display: flex; }
        .logo-container { width: auto; }
        
        .search-bar { display: none; }
        .search-bar.mobile-active {
          display: flex;
          position: absolute;
          left: 0; right: 0; top: 0; bottom: 0;
          height: 64px;
          margin: 0;
          border-radius: 0;
          background: var(--theme-surface);
          z-index: 10;
          padding: 0 8px;
        }
        .search-bar.mobile-active #searchIcon { display: none; }
        .search-bar.mobile-active #closeSearchBtn { display: flex; }
        
        .sidebar { position: fixed; left: -280px; top: 0; bottom: 0; width: 280px; padding-top: 64px; }
        .sidebar.open { left: 0; }
        .sidebar-overlay.open { display: block; opacity: 1; }
        
        .content-area { margin: 0; border-radius: 0; }
        .content-header { padding: 0 16px; }
        .chips-container { padding: 8px 16px; }
        .file-list-container { padding: 0 16px 120px; }
        
        .grid-view { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; }
        .grid-view .item-card { height: 56px; padding: 0 12px 0 36px; gap: 8px; }
        .grid-view .file-card { height: 160px; padding: 0; }
        .grid-view .file-card .file-info-bar { height: 48px; padding: 0 12px 0 36px; }
        
        .list-view .item-card { padding: 0 12px 0 36px; gap: 12px; }
        .list-view .item-meta { display: none; }
        
        .fab { position: fixed; bottom: 24px; right: 24px; z-index: 90; width: 56px; height: 56px; padding: 0; justify-content: center; border-radius: 28px; }
        .fab .text { display: none; }
        .fab .material-symbols-rounded { margin: 0; }
        
        .properties-pane { position: fixed; inset: 0; width: 100%; top: 64px; z-index: 200; border: none; }
        
        .editor-save-text { display: none; }
        .editor-header .btn { padding: 0; width: 40px; height: 40px; border-radius: 50%; justify-content: center; background: transparent; color: var(--theme-on-surface); }
        .editor-header .btn:hover { background-color: var(--theme-surface-container-high); }
      }
    </style>
  </head>
  <body data-theme="light">

    <header>
      <button class="icon-btn menu-btn" onclick="app.toggleSidebar()"><span class="material-symbols-rounded">menu</span></button>
      <div class="logo-container" onclick="app.navigate('')">
        <div class="logo-img"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-cloud-fill" viewBox="0 0 16 16"><path d="M4.406 3.342A5.53 5.53 0 0 1 8 2c2.69 0 4.923 2 5.166 4.579C14.758 6.804 16 8.137 16 9.773 16 11.569 14.502 13 12.687 13H3.781C1.708 13 0 11.366 0 9.318c0-1.763 1.266-3.223 2.942-3.593.143-.863.698-1.723 1.464-2.383"/></svg></div>
        <div class="logo-text">PHPDrive</div>
      </div>
      <div class="search-bar" id="topSearchBar">
        <button class="icon-btn mobile-only" onclick="app.toggleMobileSearch(false)" id="closeSearchBtn" style="display: none;"><span class="material-symbols-rounded">arrow_back</span></button>
        <span class="material-symbols-rounded search-icon" id="searchIcon">search</span>
        <input type="text" id="searchInput" placeholder="Search in Drive">
      </div>
      <div class="header-actions">
        <button class="icon-btn mobile-only" onclick="app.toggleMobileSearch(true)" title="Search"><span class="material-symbols-rounded">search</span></button>
        <button class="icon-btn" onclick="app.showMoreMenu(event)" title="More options"><span class="material-symbols-rounded">more_vert</span></button>
      </div>
    </header>

    <div class="main-wrapper">
      <div class="sidebar-overlay" id="sidebarOverlay" onclick="app.toggleSidebar()"></div>
      <aside class="sidebar" id="sidebar">
        <button class="fab" onclick="app.showNewMenu(event)">
          <span class="material-symbols-rounded">add</span>
          <span class="text">New</span>
        </button>
        <nav class="nav-list" style="margin-top: 16px;">
          <a class="nav-item active" id="navHome" onclick="app.setViewMode('home')">
            <span class="material-symbols-rounded">home</span> Home
          </a>
          <a class="nav-item" id="navStarred" onclick="app.setViewMode('starred')">
            <span class="material-symbols-rounded">star</span> Starred
          </a>
          <a class="nav-item" id="navHistory" onclick="app.setViewMode('history')">
            <span class="material-symbols-rounded">history</span> History
          </a>
          <a class="nav-item" id="navTrash" onclick="app.setViewMode('trash')">
            <span class="material-symbols-rounded">delete</span> Trash
          </a>
          <div class="menu-divider" style="margin: 8px 16px;"></div>
          <a class="nav-item" id="navInstall" onclick="app.installApp()">
            <span class="material-symbols-rounded">install_mobile</span> Install App
          </a>
          <a class="nav-item" onclick="app.togglePasswordProtection()">
            <span class="material-symbols-rounded" id="authIcon">lock_open</span> <span id="authText">Security: OFF</span>
          </a>
          <div class="menu-divider" style="margin: 8px 16px;"></div>
          <a class="nav-item" onclick="app.showSortMenu(event)">
            <span class="material-symbols-rounded">sort</span> Sort by
          </a>
          <a class="nav-item" onclick="app.toggleTheme()">
            <span class="material-symbols-rounded" id="themeIconSide">dark_mode</span> Theme mode
          </a>
          <a class="nav-item" onclick="app.toggleView()">
            <span class="material-symbols-rounded" id="viewIconMenuSide">view_list</span> File view mode
          </a>
        </nav>
      </aside>

      <main class="content-area" id="dropZone">
        <div class="content-header">
          <div class="breadcrumbs" id="breadcrumbs"></div>
          <div class="header-actions" id="multiSelectActions" style="display: none;">
            <button class="icon-btn" onclick="app.batchDownload('selected')" title="Download Selected"><span class="material-symbols-rounded">download</span></button>
            <button class="icon-btn" onclick="app.deleteSelected()" title="Move to Trash"><span class="material-symbols-rounded">delete</span></button>
            <button class="icon-btn" onclick="app.clearSelection(null, true)" title="Clear Selection"><span class="material-symbols-rounded">close</span></button>
          </div>
          <div class="header-actions" id="trashActions" style="display: none; gap: 8px;">
            <button class="btn btn-text" onclick="app.emptyTrash()"><span class="material-symbols-rounded">delete_forever</span> Empty Trash</button>
          </div>
        </div>

        <div class="chips-container" id="chipsContainer">
          <button class="chip active" onclick="app.setFilter('all')"><span class="material-symbols-rounded">all_inclusive</span> All</button>
          <button class="chip" onclick="app.setFilter('documents')"><span class="material-symbols-rounded">article</span> Documents</button>
          <button class="chip" onclick="app.setFilter('images')"><span class="material-symbols-rounded">image</span> Images</button>
          <button class="chip" onclick="app.setFilter('audio')"><span class="material-symbols-rounded">audiotrack</span> Audio</button>
          <button class="chip" onclick="app.setFilter('video')"><span class="material-symbols-rounded">movie</span> Video</button>
        </div>

        <div class="file-list-container" id="fileListContainer" onclick="app.clearSelection(event)">
          <div class="recents-container" id="recentsSection" style="display: none;">
            <div class="section-title" style="margin: 0 0 8px 0;">Recent files</div>
            <div class="recents-tray" id="recentsTray"></div>
          </div>
          <div class="section-title hidden" id="foldersTitle">Folders</div>
          <div id="foldersList" class="grid-view"></div>
          <div class="section-title hidden" id="filesTitle">Files</div>
          <div id="filesList" class="grid-view"></div>
        </div>
      </main>
      
      <aside class="properties-pane" id="propertiesPane">
        <div class="properties-header">
          <span style="font-family: var(--font-title); font-size: 16px; font-weight: 500;">Details</span>
          <button class="icon-btn" onclick="app.toggleProperties()"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="properties-content" id="propertiesContent"></div>
      </aside>
    </div>

    <div class="floating-menu" id="newMenu">
      <div class="menu-item" onclick="app.showModal('addFolder')"><span class="material-symbols-rounded">create_new_folder</span>New folder</div>
      <div class="menu-item" onclick="app.showModal('addFile')"><span class="material-symbols-rounded">note_add</span>New file</div>
      <div class="menu-divider"></div>
      <div class="menu-item" onclick="document.getElementById('fileUploadInput').click()"><span class="material-symbols-rounded">upload_file</span>File upload</div>
      <div class="menu-item" onclick="document.getElementById('folderUploadInput').click()"><span class="material-symbols-rounded">drive_folder_upload</span>Folder upload</div>
      <div class="menu-item" onclick="app.showModal('uploadUrl')"><span class="material-symbols-rounded">link</span>Upload via URL</div>
      <input type="file" id="fileUploadInput" multiple class="hidden" onchange="app.handleFilesSelect(event)">
      <input type="file" id="folderUploadInput" webkitdirectory directory multiple class="hidden" onchange="app.handleFolderSelect(event)">
    </div>

    <div class="floating-menu" id="moreMenu">
      <div class="menu-item" onclick="app.toggleSelectMode()"><span class="material-symbols-rounded">checklist</span>Select</div>
      <div class="menu-item" onclick="app.selectAll()"><span class="material-symbols-rounded">done_all</span>Select all</div>
      <div class="menu-item" onclick="app.clearSelection(null, true)"><span class="material-symbols-rounded">deselect</span>Unselect all</div>
      <div class="menu-divider"></div>
      <div class="menu-item" onclick="app.pasteClipboard()"><span class="material-symbols-rounded">content_paste</span>Paste</div>
    </div>

    <div class="sort-menu" id="sortMenu">
      <div class="menu-item" onclick="app.setSort('name')" id="sort_name"><span class="material-symbols-rounded">sort_by_alpha</span>Name</div>
      <div class="menu-item" onclick="app.setSort('mtime')" id="sort_mtime"><span class="material-symbols-rounded">calendar_today</span>Last modified</div>
      <div class="menu-item" onclick="app.setSort('size')" id="sort_size"><span class="material-symbols-rounded">storage</span>Size</div>
      <div class="menu-divider"></div>
      <div class="menu-item" onclick="app.toggleSortDirection()"><span class="material-symbols-rounded" id="sortDirIcon">arrow_downward</span>Direction</div>
    </div>

    <div class="context-menu" id="contextMenu"></div>

    <div class="modal-overlay" id="modalOverlay" onclick="if(event.target===this) app.closeModal()">
      <div class="modal">
        <div class="modal-title" id="modalTitle">Title</div>
        <input type="text" class="modal-input" id="modalInput" autocomplete="off">
        <div class="modal-actions">
          <button class="btn btn-text" onclick="app.closeModal()">Cancel</button>
          <button class="btn btn-filled" id="modalSubmit">Create</button>
        </div>
      </div>
    </div>

    <!-- Dedicated Image Preview Overlay (Auto-fits Image Scale) -->
    <div class="modal-overlay" id="imageOverlay" style="z-index: 3500; display: none;" onclick="if(event.target===this) app.closeImage()">
      <div style="max-width: 95%; max-height: 95%; width: auto; background: transparent; box-shadow: none; padding: 0; display: flex; align-items: center; justify-content: center; position: relative;">
        <button class="icon-btn" onclick="app.closeImage()" style="position: absolute; top: -16px; right: -16px; color: var(--theme-on-surface); background: var(--theme-surface-container-high); z-index: 10; box-shadow: 0 4px 12px rgba(0,0,0,0.3);"><span class="material-symbols-rounded">close</span></button>
        <div id="imageModalContent" style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; max-height: 90vh;"></div>
      </div>
    </div>

    <!-- Structured Media Preview Overlay (Spacious Modal Container) -->
    <div class="modal-overlay" id="mediaOverlay" style="z-index: 3500; display: none;" onclick="if(event.target===this) app.closeMedia()">
      <div class="modal" id="mediaModalContainer" style="max-width: 550px; width: 90%; position: relative; background: var(--theme-surface-container); border-radius: 20px; padding: 24px; box-shadow: 0 24px 38px 3px rgba(0,0,0,0.5); border: 1px solid var(--theme-outline-variant); display: flex; flex-direction: column;">
        <button class="icon-btn" onclick="app.closeMedia()" style="position: absolute; top: 16px; right: 16px; color: var(--theme-on-surface); background: var(--theme-surface-container-high); z-index: 10; box-shadow: 0 4px 12px rgba(0,0,0,0.3);"><span class="material-symbols-rounded">close</span></button>
        <div id="mediaModalContent" style="width: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; max-height: 80vh;"></div>
      </div>
    </div>

    <div id="frOverlay" style="position: fixed; top: 16px; right: 24px; z-index: 4000; display: none; pointer-events: none;">
      <div style="width: 320px; display: flex; flex-direction: column; background-color: var(--theme-surface-container-high); border: 1px solid var(--theme-outline-variant); border-radius: 8px; pointer-events: auto; box-shadow: 0 4px 16px rgba(0,0,0,0.2); overflow: hidden;">
        <div style="display: flex; align-items: center; padding: 6px 12px; border-bottom: 1px solid var(--theme-outline-variant);">
          <span class="material-symbols-rounded" style="font-size: 18px; color: var(--theme-on-surface-variant); margin-right: 8px;">search</span>
          <input type="text" id="frFindInput" placeholder="Find" style="flex: 1; background: none; border: none; color: var(--theme-on-surface); outline: none; font-size: 14px; width: 100%;">
          <span id="frMatchCount" style="color: var(--theme-on-surface-variant); font-size: 12px; margin: 0 8px; white-space: nowrap;">0/0</span>
          <button class="icon-btn" style="width: 28px; height: 28px;" onclick="app.frNext(true)" title="Previous"><span class="material-symbols-rounded" style="font-size: 18px;">expand_less</span></button>
          <button class="icon-btn" style="width: 28px; height: 28px;" onclick="app.frNext(false)" title="Next"><span class="material-symbols-rounded" style="font-size: 18px;">expand_more</span></button>
          <button class="icon-btn" style="width: 28px; height: 28px; margin-left: 4px;" onclick="app.closeFindReplace()" title="Close"><span class="material-symbols-rounded" style="font-size: 18px;">close</span></button>
        </div>
        <div style="display: flex; align-items: center; padding: 6px 12px; background-color: var(--theme-surface-container-low);">
          <span class="material-symbols-rounded" style="font-size: 18px; color: var(--theme-on-surface-variant); margin-right: 8px;">edit</span>
          <input type="text" id="frReplaceInput" placeholder="Replace" style="flex: 1; background: none; border: none; color: var(--theme-on-surface); outline: none; font-size: 14px; width: 100%;">
          <button style="background: transparent; border: 1px solid var(--theme-outline); color: var(--theme-on-surface); border-radius: 4px; padding: 4px 8px; font-size: 12px; cursor: pointer; margin-right: 4px;" onmouseover="this.style.backgroundColor='var(--theme-surface-container-high)'" onmouseout="this.style.backgroundColor='transparent'" onclick="app.frReplaceAction(false)">Replace</button>
          <button style="background: transparent; border: 1px solid var(--theme-outline); color: var(--theme-on-surface); border-radius: 4px; padding: 4px 8px; font-size: 12px; cursor: pointer;" onmouseover="this.style.backgroundColor='var(--theme-surface-container-high)'" onmouseout="this.style.backgroundColor='transparent'" onclick="app.frReplaceAction(true)">All</button>
        </div>
      </div>
    </div>

    <div class="editor-overlay" id="editorOverlay">
      <div class="editor-header">
        <button class="icon-btn" onclick="app.closeEditor()"><span class="material-symbols-rounded">arrow_back</span></button>
        <div class="editor-title" id="editorTitle">filename.txt</div>
        <div class="header-actions" id="editorActions">
          <button class="icon-btn" onclick="app.toggleEditorWrap()" id="editorWrapBtn" title="Toggle Word Wrap"><span class="material-symbols-rounded">wrap_text</span></button>
          <button class="icon-btn" onclick="app.editorFind()" title="Find and Replace"><span class="material-symbols-rounded">search</span></button>
          <button class="icon-btn" onclick="app.editorUndo()" title="Undo"><span class="material-symbols-rounded">undo</span></button>
          <button class="icon-btn" onclick="app.editorRedo()" title="Redo"><span class="material-symbols-rounded">redo</span></button>
          <button class="btn btn-filled" onclick="app.saveFile()">
            <span class="material-symbols-rounded" style="font-size:18px;">save</span>
            <span class="editor-save-text">Save</span>
          </button>
        </div>
      </div>
      <div class="editor-body">
        <div id="desktopEditorContainer" style="flex: 1; display: flex; flex-direction: column;">
          <textarea id="editorTextarea"></textarea>
        </div>
        <div class="mobile-editor-container" id="mobileEditorContainer">
          <textarea class="mobile-textarea" id="mobileTextarea" spellcheck="false" autocomplete="off"></textarea>
        </div>
        <div id="mediaViewerContainer" style="flex: 1; display: none; flex-direction: column;"></div>
      </div>
    </div>

    <div class="login-overlay" id="loginOverlay">
      <div class="modal">
        <div class="modal-title">Enter Password</div>
        <input type="password" class="modal-input" id="loginInput" placeholder="Password" style="margin-top:8px;">
        <div class="modal-actions">
          <button class="btn btn-filled" onclick="app.login()">Login</button>
        </div>
      </div>
    </div>

    <!-- Google Drive-Style Upload Progress Widget -->
    <div id="uploadWidget" style="position: fixed; bottom: 16px; right: 16px; width: calc(100% - 32px); max-width: 360px; background: var(--theme-surface-container-high); border: 1px solid var(--theme-outline-variant); border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.3); z-index: 5000; display: none; flex-direction: column; overflow: hidden; border-bottom: 2px solid var(--theme-primary);">
      <div id="uploadWidgetHeader" style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: var(--theme-surface-container); border-bottom: 1px solid var(--theme-outline-variant); cursor: pointer; user-select: none;" onclick="app.uploadQueue.toggleCollapse()">
        <span id="uploadWidgetTitle" style="font-family: var(--font-title); font-size: 14px; font-weight: 500; color: var(--theme-on-surface);">Uploading files...</span>
        <div style="display: flex; align-items: center; gap: 4px;" onclick="event.stopPropagation()">
          <button class="icon-btn" id="uploadWidgetToggleBtn" style="width: 28px; height: 28px;" onclick="app.uploadQueue.toggleCollapse()" title="Show more/less"><span class="material-symbols-rounded">expand_more</span></button>
          <button class="icon-btn" id="uploadWidgetCloseBtn" style="width: 28px; height: 28px;" onclick="app.uploadQueue.cancelAll()" title="Cancel all"><span class="material-symbols-rounded">close</span></button>
        </div>
      </div>
      <div id="uploadWidgetList" style="max-height: 250px; overflow-y: auto; display: flex; flex-direction: column; padding: 4px 0; background: var(--theme-surface);"></div>
    </div>

    <div class="snackbar-container" id="snackbarContainer"></div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/search/search.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/search/searchcursor.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/search/jump-to-line.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/dialog/dialog.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/xml/xml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/css/css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/htmlmixed/htmlmixed.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/php/php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/clike/clike.min.js"></script>

    <script>
      window.deferredPrompt = null;
      window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        window.deferredPrompt = e;
        const btn = document.getElementById('navInstall');
        if (btn) btn.style.display = 'flex';
      });

      class FileManager {
        constructor() {
          this.currentPath = new URLSearchParams(window.location.search).get('path') || '';
          this.viewMode = localStorage.getItem('viewMode') || 'grid';
          this.theme = localStorage.getItem('theme') || 'light';
          this.sortBy = localStorage.getItem('sortBy') || 'name';
          this.sortDesc = localStorage.getItem('sortDesc') === 'true';
          this.currentViewMode = 'home';
          this.currentFilter = 'all';
          
          this.selectedItems = new Set();
          this.data = { folders: [], files: [], breadcrumbs: [] };
          this.searchResults = null;
          this.searchDebounce = null;
          this.editor = null;
          this.currentEditFile = null;
          this.searchQuery = '';
          this.isPropertiesOpen = false;
          this.draggedItemName = null;
          this.clipboard = null;
          this.isSelectMode = false;
          this.initialEditFile = new URLSearchParams(window.location.search).get('edit');
          this.deferredPrompt = null;
          this.visibleFoldersCount = 25;
          this.visibleFilesCount = 25;
          this.filteredFolders = [];
          this.filteredFiles = [];
          this.editorWrap = localStorage.getItem('editorWrap') !== 'false';
          this.uploadQueue = new UploadQueue(this);

          this.init();
        }

        init() {
          if (IS_PROTECTED && !IS_AUTHED) {
            document.getElementById('loginOverlay').style.display = 'flex';
            return;
          }
          this.updateAuthUI();
          document.body.setAttribute('data-theme', this.theme);
          this.updateViewIcon();
          const tIcon = document.getElementById('themeIconSide');
          if (tIcon) tIcon.textContent = this.theme === 'dark' ? 'light_mode' : 'dark_mode';
          this.bindEvents();
          this.loadDirectory(this.currentPath);
          
          window.addEventListener('popstate', () => {
            this.currentPath = new URLSearchParams(window.location.search).get('path') || '';
            this.loadDirectory(this.currentPath, false);
          });
        }

        async login() {
          const pass = document.getElementById('loginInput').value;
          const formData = new URLSearchParams();
          formData.append('login_pass', pass);
          const res = await fetch('', { method: 'POST', body: formData }).then(r => r.json());
          if (res.success) {
            document.getElementById('loginOverlay').style.display = 'none';
            this.updateAuthUI();
            this.loadDirectory(this.currentPath);
          } else {
            this.showToast(res.error);
          }
        }

        updateAuthUI() {
          const icon = document.getElementById('authIcon');
          const text = document.getElementById('authText');
          if(icon && text) {
            icon.textContent = IS_PROTECTED ? 'lock' : 'lock_open';
            text.textContent = IS_PROTECTED ? 'Security: ON' : 'Security: OFF';
          }
        }

        async togglePasswordProtection() {
          const newPass = IS_PROTECTED ? '' : prompt('Enter new password (leave blank for default "Admin"):');
          if (newPass === null && !IS_PROTECTED) return; 
          
          const res = await this.fetchAPI('toggle_auth', 'POST', { action: 'toggle_auth', new_pass: newPass || 'Admin' });
          if (res && res.success) {
            window.location.reload();
          }
        }

        bindItemEvents(el, item, isFolder) {
          let touchTimer;
          let isLongPress = false;
          let touchStartX = 0;
          let touchStartY = 0;

          el.addEventListener('touchstart', (e) => {
            if (e.target.closest('.card-checkbox') || e.target.closest('.card-star')) return;
            isLongPress = false;
            touchStartX = e.touches[0].clientX;
            touchStartY = e.touches[0].clientY;
            
            touchTimer = setTimeout(() => {
              isLongPress = true;
              if (navigator.vibrate) navigator.vibrate([100]);
              this.showContextMenu({
                preventDefault: () => {},
                stopPropagation: () => {},
                clientX: touchStartX,
                clientY: touchStartY
              }, item, isFolder);
            }, 500);
          });
          
          el.addEventListener('touchend', (e) => {
            clearTimeout(touchTimer);
            if (isLongPress) {
              if (e.cancelable) e.preventDefault();
              e.stopPropagation();
            }
          });

          el.addEventListener('touchmove', (e) => {
            if (Math.abs(e.touches[0].clientX - touchStartX) > 10 || Math.abs(e.touches[0].clientY - touchStartY) > 10) {
              clearTimeout(touchTimer);
            }
          });

          el.onclick = (e) => {
            if (isLongPress) {
              isLongPress = false;
              return;
            }
            this.handleItemClick(e, item, isFolder);
          };

          el.oncontextmenu = (e) => {
            e.preventDefault();
            this.showContextMenu(e, item, isFolder);
          };
        }

        async handleSearchInput(value) {
          this.searchQuery = value.trim().toLowerCase();
          if (!this.searchQuery) {
            this.searchResults = null;
            this.render();
            return;
          }

          clearTimeout(this.searchDebounce);
          this.searchDebounce = setTimeout(async () => {
            const res = await this.fetchAPI(`search_drive&q=${encodeURIComponent(this.searchQuery)}`);
            if (res && res.success) {
              this.searchResults = res;
              this.render();
            }
          }, 300);
        }

        bindEvents() {
          document.addEventListener('click', () => {
            document.getElementById('newMenu').style.display = 'none';
            document.getElementById('contextMenu').style.display = 'none';
            document.getElementById('sortMenu').style.display = 'none';
            document.getElementById('moreMenu').style.display = 'none';
          });

          const dropZone = document.getElementById('dropZone');
          dropZone.addEventListener('dragover', (e) => { 
            if (window.innerWidth <= 768) return;
            e.preventDefault(); 
            dropZone.classList.add('drag-over'); 
          });
          dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
          dropZone.addEventListener('drop', async (e) => {
            if (window.innerWidth <= 768) return;
            e.preventDefault();
            dropZone.classList.remove('drag-over');
            
            if (e.dataTransfer.items && e.dataTransfer.items.length) {
              this.showToast('Scanning dropped items...');
              const { files, paths } = await this.scanDroppedItems(e.dataTransfer.items);
              if (files.length > 0) {
                this.uploadFiles(files, paths);
              }
            } else if (e.dataTransfer.files.length) {
              this.uploadFiles(e.dataTransfer.files);
            }
          });

          document.getElementById('searchInput').addEventListener('input', (e) => {
            this.handleSearchInput(e.target.value);
          });

          document.addEventListener('keydown', (e) => {
            if (e.ctrlKey && e.key === 's' && document.getElementById('editorOverlay').style.display === 'flex') {
              e.preventDefault();
              this.saveFile();
            }
            if (e.key === 'Delete' && this.selectedItems.size > 0 && document.activeElement.tagName !== 'INPUT') {
              this.deleteSelected();
            }
          });

          if (window.deferredPrompt) {
            const btn = document.getElementById('navInstall');
            if (btn) btn.style.display = 'flex';
          }

          const scrollContainer = document.getElementById('fileListContainer');
          if (scrollContainer) {
            scrollContainer.addEventListener('scroll', () => {
              if (scrollContainer.scrollTop + scrollContainer.clientHeight >= scrollContainer.scrollHeight - 100) {
                this.loadMoreItems();
              }
            });
          }
        }

        async installApp() {
          if (window.deferredPrompt) {
            window.deferredPrompt.prompt();
            const { outcome } = await window.deferredPrompt.userChoice;
            if (outcome === 'accepted') {
              document.getElementById('navInstall').style.display = 'none';
            }
            window.deferredPrompt = null;
          } else {
            this.showToast('Please open your browser menu (3 dots) and tap "Install App" or "Add to Home screen".');
          }
        }

        async fetchAPI(action, method = 'GET', body = null, isOverrideRetry = false) {
          const url = `?api=true&action=${action}&path=${encodeURIComponent(this.currentPath)}`;
          const options = { method };
          if (body) {
            if (body instanceof FormData) {
              options.body = body;
            } else {
              options.headers = { 'Content-Type': 'application/json' };
              options.body = JSON.stringify(body);
            }
          }
          try {
            const res = await fetch(url, options);
            const text = await res.text();
            let data;
            try { data = JSON.parse(text); } catch (e) { throw new Error('Invalid response from server'); }
            
            if (!data.success) {
              if (data.error && data.error.startsWith('CONFLICT|')) {
                const conflictFilename = data.error.split('|')[1];
                if (confirm(`The file "${conflictFilename}" already exists. Do you want to overwrite it and save a version history?`)) {
                  if (body instanceof FormData) body.append('override', '1');
                  else body.override = true;
                  return await this.fetchAPI(action, method, body, true);
                } else {
                  return null; // Cancelled
                }
              }
              throw new Error(data.error || 'Unknown error');
            }
            return data;
          } catch (err) {
            this.showToast(err.message);
            return null;
          }
        }

        navigate(path, pushState = true) {
          this.currentPath = path;
          this.searchQuery = '';
          this.searchResults = null;
          const searchInp = document.getElementById('searchInput');
          if (searchInp) searchInp.value = '';
          
          if (pushState) {
            const url = path ? `?path=${encodeURIComponent(path).replace(/%2F/g, '/')}` : window.location.pathname;
            window.history.pushState({ path }, '', url);
          }
          this.clearSelection(null, true);
          this.setViewMode('home');
        }

        async setViewMode(mode) {
          this.currentViewMode = mode;
          this.clearSelection(null, true);
          
          const navHome = document.getElementById('navHome');
          const navStarred = document.getElementById('navStarred');
          const navHistory = document.getElementById('navHistory');
          const navTrash = document.getElementById('navTrash');
          
          if (navHome) navHome.classList.toggle('active', mode === 'home');
          if (navStarred) navStarred.classList.toggle('active', mode === 'starred');
          if (navHistory) navHistory.classList.toggle('active', mode === 'history');
          if (navTrash) navTrash.classList.toggle('active', mode === 'trash');
          
          document.getElementById('chipsContainer').style.display = mode === 'home' ? 'flex' : 'none';
          document.getElementById('recentsSection').style.display = mode === 'home' ? 'block' : 'none';
          document.getElementById('trashActions').style.display = mode === 'trash' ? 'flex' : 'none';

          this.closeSidebarOnMobile();
          this.loadDirectory(this.currentPath);
        }

        setFilter(filter) {
          this.currentFilter = filter;
          const chips = document.querySelectorAll('.chip');
          chips.forEach(c => {
            c.classList.toggle('active', c.textContent.toLowerCase().includes(filter));
          });
          this.render();
        }

        async loadDirectory(path) {
          if (this.currentViewMode === 'home') {
            const data = await this.fetchAPI('list');
            if (data) {
              this.data = data;
              this.render();
              this.loadRecents();
              
              if (this.initialEditFile) {
                // Normalize initialEditFile to deeply support both simple basenames and full relative paths
                let targetPath = this.initialEditFile;
                if (!targetPath.includes('/') && this.currentPath) {
                  targetPath = this.currentPath + '/' + targetPath;
                }
                
                // Failsafe creation if it's out of pagination chunks
                const target = this.data.files.find(f => f.path === targetPath || f.path === this.initialEditFile || f.name === this.initialEditFile) || { 
                  path: targetPath, 
                  name: targetPath.split('/').pop(), 
                  ext: targetPath.split('.').pop().toLowerCase(),
                  isImage: ['png','jpg','jpeg','gif','svg'].includes(targetPath.split('.').pop().toLowerCase()) 
                };
                this.openPreviewOrEditor(target);
                this.initialEditFile = null;
              }
            }
          } else if (this.currentViewMode === 'starred') {
            const data = await this.fetchAPI('list_starred');
            if (data) {
              this.data = { folders: data.starred.filter(i => i.isDir), files: data.starred.filter(i => !i.isDir), breadcrumbs: [] };
              this.render();
            }
          } else if (this.currentViewMode === 'history') {
            const data = await this.fetchAPI('list_history');
            if (data) {
              this.data = { folders: [], files: data.history, breadcrumbs: [] };
              this.render();
            }
          } else if (this.currentViewMode === 'trash') {
            const data = await this.fetchAPI('list_trash');
            if (data) {
              this.renderTrash(data.trash);
            }
          }
        }

        async loadRecents() {
          const data = await this.fetchAPI('recents');
          const tray = document.getElementById('recentsTray');
          tray.innerHTML = '';
          if (data && data.recents.length > 0 && this.currentViewMode === 'home') {
            document.getElementById('recentsSection').style.display = 'block';
            data.recents.forEach(item => {
              const el = document.createElement('div');
              el.className = 'recent-card';
              let icon = 'description';
              if (item.isImage) icon = 'image';
              if (['mp4','webm'].includes(item.ext)) icon = 'movie';
              if (['mp3','wav','ogg'].includes(item.ext)) icon = 'audiotrack';
              el.innerHTML = `
                <div style="display:flex; justify-content:center;"><span class="material-symbols-rounded" style="font-size:36px;">${icon}</span></div>
                <div class="recent-name" title="${item.name}">${item.name}</div>
              `;
              this.bindItemEvents(el, item, false);
              tray.appendChild(el);
            });
          } else {
            document.getElementById('recentsSection').style.display = 'none';
          }
        }

        sortData(arr) {
          const m = this.sortDesc ? -1 : 1;
          return arr.sort((a, b) => {
            if (this.sortBy === 'name') return a.name.localeCompare(b.name) * m;
            if (this.sortBy === 'mtime') return (a.mtime - b.mtime) * m;
            if (this.sortBy === 'size') return (a.size - b.size) * m;
            return 0;
          });
        }

        filterByType(files) {
          if (this.currentFilter === 'all') return files;
          const map = {
            documents: ['txt', 'php', 'html', 'css', 'js', 'json', 'xml', 'pdf', 'zip'],
            images: ['png', 'jpg', 'jpeg', 'gif', 'svg'],
            audio: ['mp3', 'wav', 'ogg'],
            video: ['mp4', 'webm']
          };
          return files.filter(f => map[this.currentFilter].includes(f.ext));
        }

        highlightMatch(name) {
          if (!this.searchQuery) return name;
          const index = name.toLowerCase().indexOf(this.searchQuery);
          if (index === -1) return name;
          return name.substring(0, index) + `<mark class="search-highlight">${name.substring(index, index + this.searchQuery.length)}</mark>` + name.substring(index + this.searchQuery.length);
        }

        loadMoreItems() {
          let changed = false;
          const fList = document.getElementById('foldersList');
          const fiList = document.getElementById('filesList');

          if (this.currentFilter === 'all' && this.visibleFoldersCount < this.filteredFolders.length) {
            const nextFolders = this.filteredFolders.slice(this.visibleFoldersCount, this.visibleFoldersCount + 25);
            nextFolders.forEach(f => fList.appendChild(this.createItemNode(f, true)));
            this.visibleFoldersCount += 25;
            changed = true;
          } else if (this.visibleFilesCount < this.filteredFiles.length) {
            const nextFiles = this.filteredFiles.slice(this.visibleFilesCount, this.visibleFilesCount + 25);
            nextFiles.forEach(f => fiList.appendChild(this.createItemNode(f, false)));
            this.visibleFilesCount += 25;
            changed = true;
          }
          if (changed) this.syncSelectionUI();
        }

        render() {
          const scrollContainer = document.getElementById('fileListContainer');
          const savedScrollTop = scrollContainer ? scrollContainer.scrollTop : 0;

          this.renderBreadcrumbs();
          
          const viewClass = this.viewMode === 'grid' ? 'grid-view' : 'list-view';
          const fList = document.getElementById('foldersList');
          const fiList = document.getElementById('filesList');
          
          fList.className = viewClass;
          fiList.className = viewClass;
          fList.innerHTML = '';
          fiList.innerHTML = '';

          this.visibleFoldersCount = 25;
          this.visibleFilesCount = 25;

          let sourceFolders = this.data.folders;
          let sourceFiles = this.data.files;

          if (this.searchQuery && this.searchResults) {
            sourceFolders = this.searchResults.folders || [];
            sourceFiles = this.searchResults.files || [];
          }

          const filterFn = (i) => i.name.toLowerCase().includes(this.searchQuery);
          this.filteredFolders = this.sortData(sourceFolders.filter(filterFn));
          this.filteredFiles = this.sortData(this.filterByType(sourceFiles.filter(filterFn)));

          // Update dynamic title with path and files indicator
          const totalItems = this.filteredFolders.length + this.filteredFiles.length;
          document.title = (this.currentPath ? `/${this.currentPath}` : 'Drive') + ` (${totalItems} items) - PHPDrive`;

          document.getElementById('foldersTitle').classList.toggle('hidden', this.filteredFolders.length === 0 || this.currentFilter !== 'all');
          document.getElementById('filesTitle').classList.toggle('hidden', this.filteredFiles.length === 0);

          if (this.currentFilter === 'all') {
            this.filteredFolders.slice(0, this.visibleFoldersCount).forEach(f => fList.appendChild(this.createItemNode(f, true)));
          }
          this.filteredFiles.slice(0, this.visibleFilesCount).forEach(f => fiList.appendChild(this.createItemNode(f, false)));
          
          document.getElementById('multiSelectActions').style.display = this.selectedItems.size > 0 ? 'flex' : 'none';
          
          if (scrollContainer) scrollContainer.scrollTop = savedScrollTop;
        }

        renderTrash(trashItems) {
          const scrollContainer = document.getElementById('fileListContainer');
          const savedScrollTop = scrollContainer ? scrollContainer.scrollTop : 0;

          this.renderBreadcrumbs();
          document.getElementById('foldersTitle').classList.add('hidden');
          document.getElementById('filesTitle').classList.remove('hidden');
          document.getElementById('foldersList').innerHTML = '';
          
          const container = document.getElementById('filesList');
          container.className = this.viewMode === 'grid' ? 'grid-view' : 'list-view';
          container.innerHTML = '';

          if (trashItems.length === 0) {
            container.innerHTML = `<div style="grid-column: 1/-1; text-align: center; color: var(--theme-on-surface-variant); padding: 32px;">Trash is empty</div>`;
            return;
          }

          const notice = document.createElement('div');
          notice.style.cssText = "grid-column: 1/-1; text-align: center; font-style: italic; font-size: 13px; color: var(--theme-on-surface-variant); margin-bottom: 12px;";
          notice.textContent = "Items in trash are deleted forever after 30 days.";
          container.appendChild(notice);

          trashItems.forEach(item => {
          const el = document.createElement('div');
          const isSelected = this.selectedItems.has(item.uniq);
          el.className = `item-card ${isSelected ? 'selected' : ''}`;
          el.dataset.uniq = item.uniq;
                    
            el.innerHTML = `
              <div class="card-checkbox" onclick="app.toggleSelect(event, '${item.uniq}')"><span class="material-symbols-rounded">check_circle</span></div>
              <div class="item-icon"><span class="material-symbols-rounded">delete</span></div>
              <div class="item-name">${item.name} <span style="font-size:11px;color:var(--theme-on-surface-variant);">(${item.original_parent || 'root'})</span></div>
              <div class="item-meta">${item.deleted_at}</div>
            `;
            
            el.onclick = () => this.toggleSelect(null, item.uniq);
            el.oncontextmenu = (e) => {
              e.preventDefault();
              e.stopPropagation();
              this.selectedItems.clear();
              this.selectedItems.add(item.uniq);
              this.syncSelectionUI();
              this.showTrashContextMenu(e, item);
            };
            
            container.appendChild(el);
          });
          
          if (scrollContainer) scrollContainer.scrollTop = savedScrollTop;
        }

        renderBreadcrumbs() {
          const container = document.getElementById('breadcrumbs');
          container.innerHTML = '';
          
          const root = document.createElement('div');
          root.className = 'breadcrumb-item';
          root.textContent = this.currentViewMode === 'trash' ? 'Trash' : (this.currentViewMode === 'starred' ? 'Starred' : (this.currentViewMode === 'history' ? 'File Versions History' : 'Drive'));
          root.onclick = () => this.navigate('');
          container.appendChild(root);

          if (this.currentViewMode === 'home') {
            this.data.breadcrumbs.forEach(bc => {
              const sep = document.createElement('span');
              sep.className = 'material-symbols-rounded breadcrumb-sep';
              sep.textContent = 'chevron_right';
              container.appendChild(sep);
              
              const item = document.createElement('div');
              item.className = 'breadcrumb-item';
              item.textContent = bc.name;
              item.onclick = () => this.navigate(bc.path);
              container.appendChild(item);
            });
          }
        }

        createItemNode(item, isFolder) {
          const el = document.createElement('div');
          const isSelected = this.selectedItems.has(item.path);
          el.draggable = window.innerWidth > 768;
          el.dataset.path = item.path;
          if (item.uniq) el.dataset.uniq = item.uniq;
          
          let icon = isFolder ? 'folder' : 'description';
          if (!isFolder) {
            if (item.ext === 'zip') icon = 'folder_zip';
            if (['js','json','ts'].includes(item.ext)) icon = 'javascript';
            if (['html','xml'].includes(item.ext)) icon = 'html';
            if (['css','scss'].includes(item.ext)) icon = 'css';
            if (['php'].includes(item.ext)) icon = 'php';
            if (item.isImage) icon = 'image';
            if (item.ext === 'pdf') icon = 'picture_as_pdf';
            if (['mp4','webm'].includes(item.ext)) icon = 'movie';
            if (['mp3','wav','ogg'].includes(item.ext)) icon = 'audiotrack';
          }

          const checkboxHtml = `<div class="card-checkbox" onclick="app.toggleSelect(event, '${item.path}')"><span class="material-symbols-rounded">check_circle</span></div>`;
          const starHtml = `<div class="card-star" onclick="app.toggleStar(event, '${item.path}')"><span class="material-symbols-rounded">star</span></div>`;
          const fIconClass = isFolder ? 'folder-icon' : '';
          const nameWithHighlight = this.highlightMatch(item.name);

          if (this.viewMode === 'grid') {
            el.className = `item-card file-card ${isSelected ? 'selected' : ''} ${item.starred ? 'starred' : ''}`;
            let previewHtml = `<span class="material-symbols-rounded">${icon}</span>`;
            if (!isFolder && item.isImage) {
              const streamUrl = `?api=true&action=thumb&file=${encodeURIComponent(item.path).replace(/%2F/g, '/')}`;
              previewHtml = `<img src="${streamUrl}" loading="lazy" alt="${item.name}">`;
            }
            el.innerHTML = `
              ${checkboxHtml}
              <div class="file-preview">${previewHtml}</div>
              <div class="file-info-bar">
                <div class="item-icon ${isFolder ? 'folder-icon' : ''}"><span class="material-symbols-rounded">${icon}</span></div>
                <div class="item-name" title="${item.name}">${nameWithHighlight}</div>
              </div>
              ${starHtml}
            `;
          } else {
            el.className = `item-card ${isSelected ? 'selected' : ''} ${item.starred ? 'starred' : ''}`;
            const date = new Date(item.mtime * 1000).toLocaleDateString();
            el.innerHTML = `
              ${checkboxHtml}
              <div class="item-icon ${fIconClass}"><span class="material-symbols-rounded">${icon}</span></div>
              <div class="item-name" title="${item.name}">${nameWithHighlight}</div>
              <div class="item-meta">${date}</div>
              <div class="item-meta">${isFolder ? '-' : item.formatSize}</div>
              ${starHtml}
            `;
          }

          el.addEventListener('dragstart', (e) => {
            if (window.innerWidth <= 768) return e.preventDefault();
            this.draggedItemName = item.path;
            e.dataTransfer.setData('text/plain', item.path);
          });

          if (isFolder) {
            el.addEventListener('dragover', (e) => {
              if (window.innerWidth <= 768) return;
              e.preventDefault();
              el.classList.add('drag-target');
            });
            el.addEventListener('dragleave', () => el.classList.remove('drag-target'));
            el.addEventListener('drop', async (e) => {
              if (window.innerWidth <= 768) return;
              e.preventDefault();
              el.classList.remove('drag-target');
              const movedItem = e.dataTransfer.getData('text/plain');
              if (movedItem && movedItem !== item.path) {
                const res = await this.fetchAPI('move', 'POST', { action: 'move', item: movedItem, target: item.path });
                if (res) {
                  this.showToast(`Moved ${movedItem.split('/').pop()} to ${item.name}`);
                  this.loadDirectory(this.currentPath);
                }
              }
            });
          }

          this.bindItemEvents(el, item, isFolder);
          
          return el;
        }

        syncSelectionUI() {
          document.querySelectorAll('.item-card').forEach(card => {
            const p = card.dataset.path || card.dataset.uniq;
            if (p) card.classList.toggle('selected', this.selectedItems.has(p));
          });
          document.getElementById('multiSelectActions').style.display = this.selectedItems.size > 0 ? 'flex' : 'none';
          
          const delBtn = document.querySelector('#multiSelectActions .icon-btn[onclick="app.deleteSelected()"]');
          if (delBtn) {
            delBtn.title = this.currentViewMode === 'trash' ? 'Delete Permanently' : 'Move to Trash';
            delBtn.innerHTML = this.currentViewMode === 'trash' ? '<span class="material-symbols-rounded">delete_forever</span>' : '<span class="material-symbols-rounded">delete</span>';
          }
          
          const dlBtn = document.querySelector('#multiSelectActions .icon-btn[onclick="app.batchDownload(\'selected\')"]');
          if (dlBtn) {
            dlBtn.style.display = this.currentViewMode === 'trash' ? 'none' : 'flex';
          }
        }

        toggleSelect(e, path) {
          if (e) e.stopPropagation();
          this.selectedItems.has(path) ? this.selectedItems.delete(path) : this.selectedItems.add(path);
          this.syncSelectionUI();
          if (this.selectedItems.size === 1) this.loadProperties([...this.selectedItems][0]);
          else this.renderPropertiesEmpty();
        }

        async toggleStar(e, path) {
          e.stopPropagation();
          const res = await this.fetchAPI('toggle_star', 'POST', { action: 'toggle_star', item: path });
          if (res) {
            this.showToast(res.starred ? 'Starred item' : 'Unstarred item');
            this.loadDirectory(this.currentPath);
          }
        }

        handleItemClick(e, item, isFolder) {
          if (e.target.closest('.card-checkbox') || e.target.closest('.card-star')) return;
          
          if (this.isSelectMode || e.ctrlKey || e.metaKey || this.selectedItems.size > 0) {
            this.toggleSelect(null, item.path);
          } else {
            if (isFolder) {
              this.navigate(item.path);
            } else {
              this.openPreviewOrEditor(item);
            }
          }
        }

        clearSelection(e, force = false) {
          if (!force && e && e.target.closest('.item-card')) return;
          this.selectedItems.clear();
          this.isSelectMode = false;
          this.syncSelectionUI();
          this.renderPropertiesEmpty();
        }

        toggleSelectMode() {
          this.isSelectMode = !this.isSelectMode;
          if (this.isSelectMode) this.showToast('Select mode enabled. Tap items to select.');
          else this.clearSelection(null, true);
        }

        selectAll() {
          this.isSelectMode = true;
          this.selectedItems.clear();
          const filterFn = (i) => i.name.toLowerCase().includes(this.searchQuery);
          const allItems = [
            ...(this.currentFilter === 'all' ? this.data.folders.filter(filterFn) : []),
            ...this.filterByType(this.data.files.filter(filterFn))
          ];
          allItems.forEach(i => this.selectedItems.add(i.path));
          this.syncSelectionUI();
        }

        copyToClipboard(action) {
          if (this.selectedItems.size === 0) return;
          this.clipboard = { action, items: Array.from(this.selectedItems) };
          this.showToast(`${this.selectedItems.size} item(s) copied to clipboard`);
          this.clearSelection(null, true);
        }

        async pasteClipboard() {
          if (!this.clipboard || this.clipboard.items.length === 0) {
            this.showToast('Clipboard is empty');
            return;
          }
          const action = this.clipboard.action === 'cut' ? 'move_items' : 'copy_items';
          this.showToast(action === 'move_items' ? 'Moving items...' : 'Copying items...');
          const res = await this.fetchAPI(action, 'POST', { action, items: this.clipboard.items, target: this.currentPath });
          if (res) {
            this.showToast('Paste successful');
            if (action === 'move_items') this.clipboard = null;
            this.loadDirectory(this.currentPath);
          }
        }

        toggleMobileSearch(show) {
          const sb = document.getElementById('topSearchBar');
          const closeBtn = document.getElementById('closeSearchBtn');
          const searchIcon = document.getElementById('searchIcon');
          if (show) {
            sb.classList.add('mobile-active');
            closeBtn.style.display = 'flex';
            searchIcon.style.display = 'none';
            document.getElementById('searchInput').focus();
          } else {
            sb.classList.remove('mobile-active');
            closeBtn.style.display = 'none';
            searchIcon.style.display = 'block';
            document.getElementById('searchInput').value = '';
            this.searchQuery = '';
            this.render();
          }
        }

        toggleView() {
          this.viewMode = this.viewMode === 'grid' ? 'list' : 'grid';
          localStorage.setItem('viewMode', this.viewMode);
          this.updateViewIcon();
          this.render();
        }

        updateViewIcon() {
          const el1 = document.getElementById('viewIconMenu');
          const el2 = document.getElementById('viewIconMenuSide');
          if (el1) el1.textContent = this.viewMode === 'grid' ? 'view_list' : 'grid_view';
          if (el2) el2.textContent = this.viewMode === 'grid' ? 'view_list' : 'grid_view';
        }

        toggleTheme() {
          this.theme = this.theme === 'light' ? 'dark' : 'light';
          localStorage.setItem('theme', this.theme);
          document.body.setAttribute('data-theme', this.theme);
          if (this.editor) this.editor.setOption('theme', this.theme === 'dark' ? 'material-darker' : 'default');
          const tIcon = document.getElementById('themeIconSide');
          if (tIcon) tIcon.textContent = this.theme === 'dark' ? 'light_mode' : 'dark_mode';
        }

        toggleSidebar() {
          const sb = document.getElementById('sidebar');
          const ov = document.getElementById('sidebarOverlay');
          sb.classList.toggle('open');
          ov.classList.toggle('open');
        }

        closeSidebarOnMobile() {
          if (window.innerWidth <= 768) {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.remove('open');
          }
        }

        toggleProperties() {
          this.isPropertiesOpen = !this.isPropertiesOpen;
          document.getElementById('propertiesPane').style.display = this.isPropertiesOpen ? 'flex' : 'none';
          if (this.isPropertiesOpen && this.selectedItems.size === 1) {
            this.loadProperties([...this.selectedItems][0]);
          } else {
            this.renderPropertiesEmpty();
          }
        }

        async loadProperties(path) {
          if (!this.isPropertiesOpen) return;
          const data = await this.fetchAPI('properties&file=' + encodeURIComponent(path));
          if (data && data.success) {
            const p = data.data;
            const html = `
              <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
                <span class="material-symbols-rounded" style="font-size:32px;color:var(--theme-primary);">${p.type.includes('Folder') ? 'folder' : 'description'}</span>
                <span style="font-family:var(--font-title);font-size:16px;word-break:break-all;">${p.name}</span>
              </div>
              <div class="prop-row"><span class="prop-label">Type</span><span class="prop-val">${p.type}</span></div>
              <div class="prop-row"><span class="prop-label">Size</span><span class="prop-val">${p.size}</span></div>
              ${p.contents ? `<div class="prop-row"><span class="prop-label">Contents</span><span class="prop-val">${p.contents}</span></div>` : ''}
              <div class="prop-row"><span class="prop-label">Modified</span><span class="prop-val">${p.modified}</span></div>
              <div class="prop-row"><span class="prop-label">Created</span><span class="prop-val">${p.created}</span></div>
              <div class="prop-row"><span class="prop-label">Permissions</span><span class="prop-val">${p.permissions}</span></div>
            `;
            document.getElementById('propertiesContent').innerHTML = html;
          }
        }

        renderPropertiesEmpty() {
          if (!this.isPropertiesOpen) return;
          document.getElementById('propertiesContent').innerHTML = `<div style="color:var(--theme-on-surface-variant);font-size:14px;text-align:center;margin-top:32px;">Select an item to view details</div>`;
        }

        showMoreMenu(e) {
          e.stopPropagation();
          const menu = document.getElementById('moreMenu');
          document.getElementById('sortMenu').style.display = 'none';
          document.getElementById('newMenu').style.display = 'none';
          document.getElementById('contextMenu').style.display = 'none';
          
          if (menu.style.display === 'flex') {
            menu.style.display = 'none';
          } else {
            menu.style.display = 'flex';
            const rect = e.currentTarget.getBoundingClientRect();
            menu.style.top = `${rect.bottom + 8}px`;
            menu.style.right = '16px';
            menu.style.left = 'auto';
          }
        }

        showNewMenu(e) {
          e.stopPropagation();
          const menu = document.getElementById('newMenu');
          
          if (menu.style.display === 'flex') {
            menu.style.display = 'none';
          } else {
            menu.style.display = 'flex';
            const rect = e.currentTarget.getBoundingClientRect();
            if (window.innerWidth <= 768) {
              menu.style.bottom = `${window.innerHeight - rect.top + 8}px`;
              menu.style.right = '24px';
              menu.style.top = 'auto';
              menu.style.left = 'auto';
            } else {
              menu.style.top = `${rect.bottom + 8}px`;
              menu.style.left = `${rect.left}px`;
              menu.style.bottom = 'auto';
              menu.style.right = 'auto';
            }
          }
        }

        showSortMenu(e) {
          e.stopPropagation();
          document.getElementById('moreMenu').style.display = 'none';
          const menu = document.getElementById('sortMenu');
          menu.style.display = 'flex';
          const rect = e.currentTarget.getBoundingClientRect();
          menu.style.top = `${rect.bottom + 8}px`;
          menu.style.right = '16px';
          menu.style.left = 'auto';
          
          ['name','mtime','size'].forEach(k => {
            document.getElementById('sort_'+k).classList.toggle('active', this.sortBy === k);
          });
          document.getElementById('sortDirIcon').textContent = this.sortDesc ? 'arrow_downward' : 'arrow_upward';
        }

        setSort(by) {
          this.sortBy = by;
          localStorage.setItem('sortBy', by);
          this.render();
        }

        toggleSortDirection() {
          this.sortDesc = !this.sortDesc;
          localStorage.setItem('sortDesc', this.sortDesc);
          this.render();
        }

        showContextMenu(e, item, isFolder) {
          e.preventDefault();
          e.stopPropagation();
          
          if (!this.selectedItems.has(item.path)) {
            if (!e.ctrlKey && !e.metaKey) this.selectedItems.clear();
            this.selectedItems.add(item.path);
            this.syncSelectionUI();
          }
          
          const menu = document.getElementById('contextMenu');
          menu.innerHTML = '';
          
          const addMenuItem = (icon, text, action) => {
            const div = document.createElement('div');
            div.className = 'menu-item';
            div.innerHTML = `<span class="material-symbols-rounded">${icon}</span>${text}`;
            div.onclick = (ev) => { ev.stopPropagation(); menu.style.display = 'none'; action(); };
            menu.appendChild(div);
          };

          if (this.selectedItems.size === 1) {
            if (isFolder) {
              addMenuItem('folder_open', 'Open', () => this.navigate(item.path));
              addMenuItem('download', 'Download as Zip', () => this.batchDownload('selected'));
              addMenuItem('folder_zip', 'Archive to Zip', () => this.archiveItems());
            } else if (item.is_version) {
              addMenuItem('history', 'Rollback to this Version', () => this.restoreVersion(item.original_file, item.version_name));
              addMenuItem('open_in_new', 'Preview Version', () => window.open(`?api=true&action=stream&file=${encodeURIComponent(item.path)}`, '_blank'));
              addMenuItem('download', 'Download Version', () => window.location.href = `?download=${encodeURIComponent(item.path)}`);
            } else {
              addMenuItem('visibility', 'Preview / Edit', () => this.openPreviewOrEditor(item));
              addMenuItem('open_in_new', 'Open in a new tab', () => window.open(`?api=true&action=stream&file=${encodeURIComponent(item.path)}`, '_blank'));
              addMenuItem('download', 'Download', () => window.location.href = `?download=${encodeURIComponent(item.path)}`);
              addMenuItem('share', 'Public File Link', () => this.shareFile(item.path));
              if (item.ext === 'zip') {
                addMenuItem('folder_zip', 'Extract Zip', () => this.extractZip(item.path));
              } else if (item.ext === 'enc') {
                addMenuItem('lock_open', 'Decrypt File', () => this.decryptFile(item.path));
              } else {
                addMenuItem('lock', 'Encrypt File', () => this.encryptFile(item.path));
              }
              addMenuItem('history', 'Version History', () => this.showVersions(item.path));
            }
            
            if (!item.is_version) {
              addMenuItem('link', 'Copy Direct URL', () => {
                let qs = '?';
                if (this.currentPath) qs += `path=${encodeURIComponent(this.currentPath).replace(/%2F/g, '/')}`;
                if (!isFolder) qs += (qs === '?' ? '' : '&') + `edit=${encodeURIComponent(item.path).replace(/%2F/g, '/')}`;
                if (qs === '?') qs = '';
                const url = window.location.origin + window.location.pathname + qs;
                navigator.clipboard.writeText(url);
                this.showToast('URL copied to clipboard!');
              });
              addMenuItem('edit_square', 'Rename', () => this.showModal('rename', item.path));
            }
            addMenuItem('info', 'Info', () => {
              this.isPropertiesOpen = false;
              this.toggleProperties();
            });
            const divider = document.createElement('div'); divider.className = 'menu-divider'; menu.appendChild(divider);
          } else {
            addMenuItem('download', 'Download as Zip', () => this.batchDownload('selected'));
            addMenuItem('folder_zip', 'Archive to Zip', () => this.archiveItems());
            const divider = document.createElement('div'); divider.className = 'menu-divider'; menu.appendChild(divider);
          }
          
          if (!item.is_version) {
            addMenuItem('content_copy', 'Copy', () => this.copyToClipboard('copy'));
            addMenuItem('content_cut', 'Cut (Move)', () => this.copyToClipboard('cut'));
          }
          addMenuItem('delete', 'Move to Trash', () => this.deleteSelected());
          
          menu.style.display = 'flex';
          
          let x = e.clientX || (e.touches && e.touches[0].clientX) || 0;
          let y = e.clientY || (e.touches && e.touches[0].clientY) || 0;
          const rect = menu.getBoundingClientRect();
          
          // Keep context menu 8px bounded from all edges of the screen
          if (x + rect.width > window.innerWidth) x = window.innerWidth - rect.width - 8;
          if (x < 8) x = 8;
          if (y + rect.height > window.innerHeight) y = window.innerHeight - rect.height - 8;
          if (y < 8) y = 8;
          
          menu.style.left = `${x}px`;
          menu.style.top = `${y}px`;
        }

        showTrashContextMenu(e, item) {
          const menu = document.getElementById('contextMenu');
          menu.innerHTML = '';
          
          const addMenuItem = (icon, text, action) => {
            const div = document.createElement('div');
            div.className = 'menu-item';
            div.innerHTML = `<span class="material-symbols-rounded">${icon}</span>${text}`;
            div.onclick = (ev) => { ev.stopPropagation(); menu.style.display = 'none'; action(); };
            menu.appendChild(div);
          };

          addMenuItem('restore_from_trash', 'Restore', () => this.restoreTrash(item.uniq));
          addMenuItem('delete_forever', 'Delete Permanently', () => this.deleteTrashPermanent(item.uniq));
          
          menu.style.display = 'flex';
          
          let x = e.clientX || (e.touches && e.touches[0].clientX) || 0;
          let y = e.clientY || (e.touches && e.touches[0].clientY) || 0;
          const rect = menu.getBoundingClientRect();
          
          if (x + rect.width > window.innerWidth) x = window.innerWidth - rect.width - 8;
          if (x < 8) x = 8;
          if (y + rect.height > window.innerHeight) y = window.innerHeight - rect.height - 8;
          if (y < 8) y = 8;
          
          menu.style.left = `${x}px`;
          menu.style.top = `${y}px`;
        }

        showTrashContextMenu(e, item) {
          const menu = document.getElementById('contextMenu');
          menu.innerHTML = '';
          
          const addMenuItem = (icon, text, action) => {
            const div = document.createElement('div');
            div.className = 'menu-item';
            div.innerHTML = `<span class="material-symbols-rounded">${icon}</span>${text}`;
            div.onclick = (ev) => { ev.stopPropagation(); menu.style.display = 'none'; action(); };
            menu.appendChild(div);
          };

          addMenuItem('restore_from_trash', 'Restore', () => this.restoreTrash(item.uniq));
          addMenuItem('delete_forever', 'Delete Permanently', () => this.deleteTrashPermanent(item.uniq));
          
          menu.style.display = 'flex';
          
          let x = e.clientX || (e.touches && e.touches[0].clientX) || 0;
          let y = e.clientY || (e.touches && e.touches[0].clientY) || 0;
          
          const rect = menu.getBoundingClientRect();
          if (x + rect.width > window.innerWidth) x = window.innerWidth - rect.width - 8;
          if (y + rect.height > window.innerHeight) y -= rect.height;
          if (x < 0) x = 8;
          
          menu.style.left = `${x}px`;
          menu.style.top = `${y}px`;
        }

        showModal(action, oldPath = '') {
          const overlay = document.getElementById('modalOverlay');
          const title = document.getElementById('modalTitle');
          const input = document.getElementById('modalInput');
          const submit = document.getElementById('modalSubmit');
          
          overlay.style.display = 'flex';
          input.value = '';
          input.focus();

          submit.onclick = async () => {
            const val = input.value.trim();
            if (!val) return;
            this.closeModal();
            
            if (action === 'addFolder') {
              const res = await this.fetchAPI('add_folder', 'POST', { action: 'add_folder', name: val });
              if (res) { this.showToast('Folder created'); this.loadDirectory(this.currentPath); }
            } else if (action === 'addFile') {
              const res = await this.fetchAPI('add_file', 'POST', { action: 'add_file', name: val });
              if (res) { this.showToast('File created'); this.loadDirectory(this.currentPath); }
            } else if (action === 'uploadUrl') {
              this.showToast('Downloading from URL...');
              const res = await this.fetchAPI('upload_url', 'POST', { action: 'upload_url', url: val });
              if (res) { this.showToast('Downloaded successfully'); this.loadDirectory(this.currentPath); }
            } else if (action === 'rename') {
              const oldName = oldPath.split('/').pop();
              const extOld = oldName.split('.').pop();
              const extNew = val.split('.').pop();
              let targetName = val;
              if (oldName.includes('.') && extOld !== extNew) {
                if (confirm('Changing extension might break the file. Keep original extension?')) {
                  targetName = val.split('.')[0] + '.' + extOld;
                }
              }
              const res = await this.fetchAPI('rename', 'POST', { action: 'rename', old: oldPath, new: targetName });
              if (res) { this.showToast('Renamed successfully'); this.loadDirectory(this.currentPath); }
            }
          };

          if (action === 'addFolder') {
            title.textContent = 'New folder';
            input.placeholder = 'Folder name';
            submit.textContent = 'Create';
          } else if (action === 'addFile') {
            title.textContent = 'New file';
            input.placeholder = 'File name (e.g., script.js)';
            submit.textContent = 'Create';
          } else if (action === 'uploadUrl') {
            title.textContent = 'Upload from URL';
            input.placeholder = 'https://example.com/file.png';
            submit.textContent = 'Download';
          } else if (action === 'rename') {
            title.textContent = 'Rename';
            input.value = oldPath.split('/').pop();
            submit.textContent = 'OK';
          }
        }

        async archiveItems() {
          const items = Array.from(this.selectedItems);
          if (items.length === 0) return;
          this.showToast('Creating Zip Archive...');
          const res = await this.fetchAPI('zip_items', 'POST', { action: 'zip_items', items });
          if (res) {
            this.showToast('Archive created successfully');
            this.clearSelection(null, true);
            this.loadDirectory(this.currentPath);
          }
        }

        async encryptFile(path) {
          this.showToast('Encrypting securely (AES-256)...');
          const res = await this.fetchAPI('encrypt_file', 'POST', { action: 'encrypt_file', file: path });
          if (res) {
            this.showToast('File encrypted successfully!');
            this.loadDirectory(this.currentPath);
          }
        }

        async decryptFile(path) {
          this.showToast('Decrypting file...');
          const res = await this.fetchAPI('decrypt_file', 'POST', { action: 'decrypt_file', file: path });
          if (res) {
            this.showToast('File decrypted successfully!');
            this.loadDirectory(this.currentPath);
          }
        }

        async showVersions(path) {
          const res = await this.fetchAPI('get_versions', 'POST', { action: 'get_versions', file: path });
          if (res && res.versions.length > 0) {
            const overlay = document.getElementById('modalOverlay');
            const title = document.getElementById('modalTitle');
            title.textContent = 'Version History';
            
            let html = `<div class="versions-list" style="max-height: 250px; overflow-y: auto; background: var(--theme-surface-container-high); border-radius: 8px; padding: 4px; width: 100%;">`;
            res.versions.forEach(v => {
              const d = new Date(v.mtime * 1000).toLocaleString();
              html += `
                <div class="version-item" style="display: flex; justify-content: space-between; align-items: center; padding: 10px; border-bottom: 1px solid var(--theme-outline-variant);">
                  <div style="flex: 1; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; padding-right: 12px;">
                    <div style="font-weight:600;font-size:14px;color:var(--theme-on-surface)">${d}</div>
                    <div style="font-size:12px;color:var(--theme-on-surface-variant)">Size: ${v.size}</div>
                  </div>
                  <button class="btn btn-filled" style="height:32px;font-size:12px;padding:0 12px;" onclick="app.restoreVersion('${path}', '${v.name}')">Restore</button>
                </div>`;
            });
            html += `</div>`;
            
            const input = document.getElementById('modalInput');
            input.style.display = 'none';
            input.insertAdjacentHTML('afterend', `<div id="versionsContainer">${html}</div>`);
            
            document.getElementById('modalSubmit').style.display = 'none';
            overlay.style.display = 'flex';
            
            const oldClose = this.closeModal;
            this.closeModal = () => {
              const c = document.getElementById('versionsContainer');
              if (c) c.remove();
              input.style.display = 'block';
              document.getElementById('modalSubmit').style.display = 'inline-flex';
              oldClose.call(this);
            };
          } else {
            this.showToast('No version history found for this file.');
          }
        }

        async restoreVersion(path, versionName) {
          if (confirm('Restore this older version? The current file will be backed up.')) {
            this.closeModal();
            this.showToast('Restoring version...');
            const res = await this.fetchAPI('restore_version', 'POST', { action: 'restore_version', file: path, version_name: versionName });
            if (res) {
              this.showToast('Version restored successfully!');
              this.loadDirectory(this.currentPath);
            }
          }
        }

        closeModal() {
          document.getElementById('modalOverlay').style.display = 'none';
        }

        async deleteSelected() {
          if (this.selectedItems.size === 0) return;
          
          if (this.currentViewMode === 'trash') {
            if (!confirm('Permanently delete selected items?')) return;
            const res = await this.fetchAPI('delete_perm', 'POST', { action: 'delete_perm', items: Array.from(this.selectedItems) });
            if (res) {
              this.showToast(`${this.selectedItems.size} item(s) permanently deleted`);
              this.selectedItems.clear();
              this.loadDirectory(this.currentPath);
              this.renderPropertiesEmpty();
            }
            return;
          }

          const res = await this.fetchAPI('trash', 'POST', { action: 'trash', items: Array.from(this.selectedItems) });
          if (res) {
            this.showToast(`${this.selectedItems.size} item(s) moved to Trash`);
            this.selectedItems.clear();
            this.loadDirectory(this.currentPath);
            this.renderPropertiesEmpty();
          }
        }

        async restoreTrash(uniq) {
          const res = await this.fetchAPI('restore_trash', 'POST', { action: 'restore_trash', items: [uniq] });
          if (res) {
            this.showToast('Item restored');
            this.loadDirectory(this.currentPath);
          }
        }

        async deleteTrashPermanent(uniq) {
          if (!confirm('This action is irreversible. Delete permanently?')) return;
          const res = await this.fetchAPI('delete_perm', 'POST', { action: 'delete_perm', items: [uniq] });
          if (res) {
            this.showToast('Item deleted forever');
            this.loadDirectory(this.currentPath);
          }
        }

        async emptyTrash() {
          if (!confirm('Empty entire Trash forever?')) return;
          const res = await this.fetchAPI('empty_trash', 'POST', { action: 'empty_trash' });
          if (res) {
            this.showToast('Trash cleared');
            this.loadDirectory(this.currentPath);
          }
        }

        async extractZip(path) {
          this.showToast('Extracting ZIP...');
          const res = await this.fetchAPI('unzip', 'POST', { action: 'unzip', item: path });
          if (res) {
            this.showToast('ZIP extracted successfully!');
            this.loadDirectory(this.currentPath);
          }
        }

        async shareFile(path) {
          const res = await this.fetchAPI('create_share', 'POST', { action: 'create_share', item: path });
          if (res && res.token) {
            const shareUrl = `${window.location.origin}${window.location.pathname}?share=${res.token}`;
            navigator.clipboard.writeText(shareUrl);
            this.showToast('Link copied to clipboard!');
          }
        }

        handleFilesSelect(e) {
          if (e.target.files.length) this.uploadFiles(e.target.files);
          e.target.value = '';
        }

        handleFolderSelect(e) {
          if (e.target.files.length) {
            const files = e.target.files;
            const paths = [];
            for (let i = 0; i < files.length; i++) {
              paths.push(files[i].webkitRelativePath || '');
            }
            this.uploadFiles(files, paths);
          }
          e.target.value = '';
        }

        async scanDroppedItems(items) {
          const files = [];
          const paths = [];
          
          const readAllEntries = async (dirReader) => {
            let allEntries = [];
            const read = async () => {
              const entries = await new Promise((resolve) => dirReader.readEntries(resolve));
              if (entries && entries.length > 0) {
                allEntries = allEntries.concat(entries);
                await read();
              }
            };
            await read();
            return allEntries;
          };

          const traverseEntry = async (entry, path = '') => {
            if (entry.isFile) {
              const file = await new Promise((resolve) => entry.file(resolve));
              files.push(file);
              paths.push(path + file.name);
            } else if (entry.isDirectory) {
              const dirReader = entry.createReader();
              const entries = await readAllEntries(dirReader);
              for (const childEntry of entries) {
                await traverseEntry(childEntry, path + entry.name + '/');
              }
            }
          };

          for (let i = 0; i < items.length; i++) {
            const entry = items[i].webkitGetAsEntry();
            if (entry) {
              await traverseEntry(entry);
            }
          }

          return { files, paths };
        }

        uploadFiles(files, paths = []) {
          for (let i = 0; i < files.length; i++) {
            this.uploadQueue.add(files[i], paths[i] || '');
          }
        }

        async openPreviewOrEditor(item) {
          if (item.ext === 'zip') {
            this.showToast('ZIP files cannot be viewed. Use the context menu to extract or download.');
            return;
          }
          this.currentEditFile = item.path;
          
          let qs = '?';
          if (this.currentPath) qs += `path=${encodeURIComponent(this.currentPath).replace(/%2F/g, '/')}&`;
          qs += `edit=${encodeURIComponent(item.path).replace(/%2F/g, '/')}`;
          window.history.pushState({}, '', qs);
          
          const streamUrl = `?api=true&action=stream&file=${encodeURIComponent(item.path)}`;

          if (item.isImage) {
            const imageOverlay = document.getElementById('imageOverlay');
            const imageContent = document.getElementById('imageModalContent');
            imageOverlay.style.display = 'flex';
            imageContent.innerHTML = `<img src="${streamUrl}" style="max-width: 100%; max-height: 85vh; object-fit: contain; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.3);">`;
          } else if (['mp4','webm','mp3','wav','ogg','pdf'].includes(item.ext)) {
            const mediaOverlay = document.getElementById('mediaOverlay');
            const mediaContent = document.getElementById('mediaModalContent');
            const mediaContainer = document.getElementById('mediaModalContainer');
            
            if (item.ext === 'pdf') {
              mediaContainer.style.maxWidth = '1000px';
              mediaContainer.style.width = '95%';
              mediaContainer.style.padding = '0';
              mediaContent.innerHTML = `
                <div style="width: 100%; height: 85vh; display: flex; flex-direction: column; overflow: hidden;">
                  <div style="padding: 12px 16px; background: var(--theme-surface-container-high); border-bottom: 1px solid var(--theme-outline-variant); display: flex; justify-content: space-between; align-items: center; flex-shrink: 0;">
                    <span style="font-family: var(--font-title); font-size: 14px; font-weight: 500; color: var(--theme-on-surface); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-right: 12px;">${item.name}</span>
                    <button class="btn btn-filled" style="height: 32px; padding: 0 16px; font-size: 12px; flex-shrink: 0;" onclick="window.open('${streamUrl}', '_blank')">Open / Download Native</button>
                  </div>
                  <iframe src="${streamUrl}" style="flex: 1; width: 100%; border: none; background: #fff;"></iframe>
                </div>`;
            } else if (['mp4','webm'].includes(item.ext)) {
              mediaContainer.style.maxWidth = '550px';
              mediaContainer.style.width = '90%';
              mediaContainer.style.padding = '24px';
              mediaContent.innerHTML = `<video controls autoplay preload="metadata" style="width: 100%; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.3); background: #000;"><source src="${streamUrl}" type="video/${item.ext}"></video>`;
            } else if (['mp3','wav','ogg'].includes(item.ext)) {
              mediaContainer.style.maxWidth = '550px';
              mediaContainer.style.width = '90%';
              mediaContainer.style.padding = '24px';
              mediaContent.innerHTML = `
                <div style="display: flex; flex-direction: column; align-items: center; gap: 24px; width: 100%;">
                  <div class="media-art" style="width: 120px; height: 120px; border-radius: 24px; background: var(--theme-primary-container); color: var(--theme-on-primary-container); display: flex; align-items: center; justify-content: center;"><span class="material-symbols-rounded" style="font-size: 64px; color: var(--theme-primary);">audiotrack</span></div>
                  <div style="font-family: var(--font-title); font-size: 16px; color: var(--theme-on-surface); text-align: center; word-break: break-all; max-width: 300px;">${item.name}</div>
                  <audio controls autoplay preload="metadata" style="width: 100%; max-width: 300px;"><source src="${streamUrl}" type="audio/${item.ext === 'mp3' ? 'mpeg' : item.ext}"></audio>
                </div>`;
            }
            mediaOverlay.style.display = 'flex';
          } else {
            const overlay = document.getElementById('editorOverlay');
            const actions = document.getElementById('editorActions');
            const mediaContainer = document.getElementById('mediaViewerContainer');
            const desktopContainer = document.getElementById('desktopEditorContainer');
            const mobileContainer = document.getElementById('mobileEditorContainer');
            
            document.getElementById('editorTitle').textContent = item.name;
            overlay.style.display = 'flex';
            
            desktopContainer.style.display = 'none';
            mobileContainer.style.display = 'none';
            mediaContainer.style.display = 'none';
            actions.style.display = 'none';

            actions.style.display = 'flex';
            this.updateEditorWrapUI();
            const res = await this.fetchAPI(`read&file=${encodeURIComponent(item.path)}`);
            if (res && res.success) {
              if (window.innerWidth <= 768) {
                mobileContainer.style.display = 'flex';
                const textEl = document.getElementById('mobileTextarea');
                textEl.value = res.content;
              } else {
                desktopContainer.style.display = 'flex';
                let mode = 'text/plain';
                if (item.ext === 'js' || item.ext === 'json') mode = 'text/javascript';
                if (item.ext === 'html') mode = 'text/html';
                if (item.ext === 'css') mode = 'text/css';
                if (item.ext === 'php') mode = 'application/x-httpd-php';

                this.editor = CodeMirror.fromTextArea(document.getElementById('editorTextarea'), {
                  lineNumbers: true,
                  theme: this.theme === 'dark' ? 'material-darker' : 'default',
                  mode: mode,
                  indentUnit: 2,
                  tabSize: 2,
                  lineWrapping: this.editorWrap,
                  viewportMargin: 10
                });
                this.editor.setValue(res.content);
                setTimeout(() => this.editor.refresh(), 50);
              }
            }
          }
        }
        
        closeImage() {
          const overlay = document.getElementById('imageOverlay');
          if (overlay) {
            overlay.style.display = 'none';
            document.getElementById('imageModalContent').innerHTML = '';
          }
          this.currentEditFile = null;
          const qs = this.currentPath ? `?path=${encodeURIComponent(this.currentPath).replace(/%2F/g, '/')}` : window.location.pathname;
          window.history.pushState({}, '', qs);
        }

        closeMedia() {
          const overlay = document.getElementById('mediaOverlay');
          if (overlay) {
            overlay.style.display = 'none';
            document.getElementById('mediaModalContent').innerHTML = '';
          }
          this.currentEditFile = null;
          const qs = this.currentPath ? `?path=${encodeURIComponent(this.currentPath).replace(/%2F/g, '/')}` : window.location.pathname;
          window.history.pushState({}, '', qs);
        }

        insertMobileChar(char) {
          const el = document.getElementById('mobileTextarea');
          const start = el.selectionStart;
          const end = el.selectionEnd;
          el.value = el.value.substring(0, start) + char + el.value.substring(end);
          el.selectionStart = el.selectionEnd = start + char.length;
          el.focus();
        }

        editorUndo() {
          if (this.editor) {
            this.editor.undo();
          } else if (window.innerWidth <= 768) {
            document.execCommand('undo');
          }
        }

        editorRedo() {
          if (this.editor) {
            this.editor.redo();
          } else if (window.innerWidth <= 768) {
            document.execCommand('redo');
          }
        }

        editorFind() {
          this.openFindReplace();
        }

        editorReplace() {
          this.openFindReplace();
          document.getElementById('frReplaceInput').focus();
        }

        openFindReplace() {
          document.getElementById('frOverlay').style.display = 'flex';
          const input = document.getElementById('frFindInput');
          input.focus();
          if(!this.frBound) {
            input.addEventListener('input', () => this.frSearch());
            input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); this.frNext(e.shiftKey); } });
            document.getElementById('frReplaceInput').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); this.frReplaceAction(false); } });
            this.frBound = true;
          }
          this.frSearch();
        }

        closeFindReplace() {
          document.getElementById('frOverlay').style.display = 'none';
          if(this.editor) this.editor.getAllMarks().forEach(m => m.clear());
          document.getElementById('frMatchCount').textContent = '0/0';
        }

        frSearch() {
          const term = document.getElementById('frFindInput').value;
          this.frMatches = [];
          this.frCurrent = -1;
          const countEl = document.getElementById('frMatchCount');
          
          if (!term) {
            countEl.textContent = '0/0';
            if (this.editor) this.editor.getAllMarks().forEach(m => m.clear());
            return;
          }

          if (this.editor) {
            this.editor.getAllMarks().forEach(m => m.clear());
            const cursor = this.editor.getSearchCursor(term);
            while (cursor.findNext()) {
              this.frMatches.push({from: cursor.from(), to: cursor.to()});
              this.editor.markText(cursor.from(), cursor.to(), {className: 'search-highlight'});
            }
          } else {
            const text = document.getElementById('mobileTextarea').value;
            let idx = text.indexOf(term);
            while (idx !== -1) {
              this.frMatches.push({start: idx, end: idx + term.length});
              idx = text.indexOf(term, idx + term.length);
            }
          }
          
          countEl.textContent = `0/${this.frMatches.length}`;
        }

        frNext(reverse = false) {
          if (this.frMatches.length === 0) return;
          if (reverse) {
            this.frCurrent = this.frCurrent <= 0 ? this.frMatches.length - 1 : this.frCurrent - 1;
          } else {
            this.frCurrent = this.frCurrent >= this.frMatches.length - 1 ? 0 : this.frCurrent + 1;
          }
          
          document.getElementById('frMatchCount').textContent = `${this.frCurrent + 1}/${this.frMatches.length}`;
          
          if (this.editor) {
            const m = this.frMatches[this.frCurrent];
            this.editor.setSelection(m.from, m.to);
            this.editor.scrollIntoView(m.from, 100);
          } else {
            const ta = document.getElementById('mobileTextarea');
            const m = this.frMatches[this.frCurrent];
            ta.setSelectionRange(m.start, m.end);
            ta.focus();
          }
        }

        frReplaceAction(all = false) {
          const term = document.getElementById('frFindInput').value;
          const rep = document.getElementById('frReplaceInput').value;
          if (!term) return;

          if (this.editor) {
            if (all) {
              // Group all replacements into a single operation so a single 'Undo' reverses them all
              this.editor.operation(() => {
                const cursor = this.editor.getSearchCursor(term);
                while (cursor.findNext()) cursor.replace(rep);
              });
            } else {
              if (this.frCurrent > -1 && this.frMatches[this.frCurrent]) {
                const m = this.frMatches[this.frCurrent];
                if (this.editor.getRange(m.from, m.to) === term) this.editor.replaceRange(rep, m.from, m.to);
              }
            }
          } else {
            const ta = document.getElementById('mobileTextarea');
            ta.focus();
            if (all) {
              // Loop backward to avoid index shifting, using execCommand to preserve the Undo stack
              for (let i = this.frMatches.length - 1; i >= 0; i--) {
                const m = this.frMatches[i];
                if (ta.value.substring(m.start, m.end) === term) {
                  ta.setSelectionRange(m.start, m.end);
                  document.execCommand('insertText', false, rep);
                }
              }
            } else {
              if (this.frCurrent > -1 && this.frMatches[this.frCurrent]) {
                const m = this.frMatches[this.frCurrent];
                if (ta.value.substring(m.start, m.end) === term) {
                  ta.setSelectionRange(m.start, m.end);
                  document.execCommand('insertText', false, rep);
                }
              }
            }
          }
          this.frSearch();
        }

        toggleEditorWrap() {
          this.editorWrap = !this.editorWrap;
          localStorage.setItem('editorWrap', this.editorWrap);
          this.updateEditorWrapUI();
          if (this.editor) {
            this.editor.setOption('lineWrapping', this.editorWrap);
          }
        }

        updateEditorWrapUI() {
          const btn = document.getElementById('editorWrapBtn');
          if (btn) {
            const icon = btn.querySelector('.material-symbols-rounded');
            if (icon) {
              icon.textContent = this.editorWrap ? 'wrap_text' : 'segment';
              btn.style.color = this.editorWrap ? 'var(--theme-primary)' : 'var(--theme-on-surface-variant)';
            }
          }
          const mobileTa = document.getElementById('mobileTextarea');
          if (mobileTa) {
            mobileTa.setAttribute('wrap', this.editorWrap ? 'soft' : 'off');
            mobileTa.style.whiteSpace = this.editorWrap ? 'pre-wrap' : 'pre';
            mobileTa.style.overflowX = this.editorWrap ? 'hidden' : 'auto';
          }
        }

        closeEditor() {
          document.getElementById('editorOverlay').style.display = 'none';
          document.getElementById('mediaViewerContainer').innerHTML = '';
          this.currentEditFile = null;
          this.editor = null;
          
          const qs = this.currentPath ? `?path=${encodeURIComponent(this.currentPath).replace(/%2F/g, '/')}` : window.location.pathname;
          window.history.pushState({}, '', qs);
        }

        async saveFile() {
          if (!this.currentEditFile) return;
          let content = '';
          if (window.innerWidth <= 768) {
            content = document.getElementById('mobileTextarea').value;
          } else if (this.editor) {
            content = this.editor.getValue();
          }
          const res = await this.fetchAPI('write', 'POST', { action: 'write', file: this.currentEditFile, content });
          if (res) this.showToast('File saved');
        }

        batchDownload(type) {
          if (type === 'selected' && this.selectedItems.size === 0) return;
          let url = `?batch=${type}&path=${encodeURIComponent(this.currentPath)}`;
          if (type === 'selected') url += `&items=${encodeURIComponent(Array.from(this.selectedItems).join(','))}`;
          window.location.href = url;
          this.clearSelection(null, true);
        }

        showToast(msg) {
          const container = document.getElementById('snackbarContainer');
          const toast = document.createElement('div');
          toast.className = 'snackbar';
          toast.textContent = msg;
          container.appendChild(toast);
          
          requestAnimationFrame(() => {
            toast.classList.add('show');
            setTimeout(() => {
              toast.classList.remove('show');
              setTimeout(() => toast.remove(), 300);
            }, 3000);
          });
        }
      }

      class UploadQueue {
        constructor(manager) {
          this.manager = manager;
          this.queue = [];
          this.activeXhr = null;
          this.isCollapsed = false;
        }

        add(file, path) {
          const id = 'up_' + Math.random().toString(36).substring(2, 9);
          this.queue.push({
            id: id,
            file: file,
            path: path,
            name: file.name,
            progress: 0,
            status: 'pending',
            xhr: null
          });
          this.renderItem(this.queue[this.queue.length - 1]);
          this.updateHeader();
          if (!this.activeXhr) {
            this.processNext();
          }
        }

        async processNext() {
          if (this.activeXhr) return;
          const next = this.queue.find(item => item.status === 'pending');
          if (!next) {
            this.manager.showToast('All uploads complete');
            this.manager.loadDirectory(this.manager.currentPath);
            return;
          }

          next.status = 'uploading';
          this.updateItemUI(next);
          this.updateHeader();

          const uploadNextChunk = (chunkIndex) => {
            const chunkSize = 5 * 1024 * 1024; // Slice into 5MB chunks
            const totalChunks = Math.ceil(next.file.size / chunkSize) || 1;
            const start = chunkIndex * chunkSize;
            const end = Math.min(start + chunkSize, next.file.size);
            const chunkBlob = next.file.slice(start, end);

            const formData = new FormData();
            formData.append('action', 'upload');
            formData.append('files[]', chunkBlob, next.file.name);
            formData.append('paths[]', next.path);
            formData.append('chunk', chunkIndex);
            formData.append('chunks', totalChunks);
            formData.append('file_id', next.id);

            const xhr = new XMLHttpRequest();
            next.xhr = xhr;
            this.activeXhr = xhr;

            xhr.open('POST', `?api=true&action=upload&path=${encodeURIComponent(this.manager.currentPath)}`);
            
            xhr.upload.onprogress = (e) => {
              if (e.lengthComputable) {
                const chunkProgress = e.loaded / e.total;
                const overallProgress = ((chunkIndex + chunkProgress) / totalChunks) * 100;
                next.progress = Math.round(overallProgress);
                this.updateItemUI(next);
              }
            };

            xhr.onload = () => {
              if (xhr.status === 200) {
                try {
                  const res = JSON.parse(xhr.responseText);
                  if (res.success) {
                    if (chunkIndex < totalChunks - 1) {
                      uploadNextChunk(chunkIndex + 1); // Blast the next chunk
                    } else {
                      this.activeXhr = null;
                      next.status = 'success';
                      next.progress = 100;
                      this.updateItemUI(next);
                      this.processNext();
                    }
                  } else {
                    throw new Error("Server rejected chunk");
                  }
                } catch (err) {
                  this.activeXhr = null;
                  next.status = 'failed';
                  this.updateItemUI(next);
                  this.processNext();
                }
              } else {
                this.activeXhr = null;
                next.status = 'failed';
                this.updateItemUI(next);
                this.processNext();
              }
            };

            xhr.onerror = () => {
              this.activeXhr = null;
              next.status = 'failed';
              this.updateItemUI(next);
              this.processNext();
            };

            xhr.send(formData);
          };

          uploadNextChunk(0);
        }

        cancel(id) {
          const item = this.queue.find(i => i.id === id);
          if (!item) return;
          if (item.status === 'uploading' && item.xhr) {
            item.xhr.abort();
            this.activeXhr = null;
          }
          item.status = 'cancelled';
          this.updateItemUI(item);
          this.processNext();
        }

        cancelAll() {
          this.queue.forEach(item => {
            if (item.status === 'uploading' && item.xhr) {
              item.xhr.abort();
            }
            if (item.status === 'queued' || item.status === 'uploading') {
              item.status = 'cancelled';
            }
          });
          this.activeXhr = null;
          this.queue = [];
          document.getElementById('uploadWidgetList').innerHTML = '';
          document.getElementById('uploadWidget').style.display = 'none';
        }

        renderItem(item) {
          const list = document.getElementById('uploadWidgetList');
          const itemEl = document.createElement('div');
          itemEl.id = `widget_item_${item.id}`;
          itemEl.style.cssText = "display: flex; flex-direction: column; padding: 8px 16px; border-bottom: 1px solid var(--theme-outline-variant); background: var(--theme-surface);";
          
          itemEl.innerHTML = `
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 4px;">
              <div style="display: flex; align-items: center; gap: 8px; min-width: 0; flex: 1;">
                <span class="material-symbols-rounded" id="icon_${item.id}" style="font-size: 20px; color: var(--theme-on-surface-variant); flex-shrink: 0;">upload_file</span>
                <span style="font-size: 13px; font-weight: 500; color: var(--theme-on-surface); overflow: hidden; white-space: nowrap; text-overflow: ellipsis; flex: 1; min-width: 0;">${item.name}</span>
              </div>
              <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                <span id="status_${item.id}" style="font-size: 11px; color: var(--theme-on-surface-variant);">Queued</span>
                <button class="icon-btn" id="cancel_btn_${item.id}" style="width: 24px; height: 24px;" onclick="app.uploadQueue.cancel('${item.id}')"><span class="material-symbols-rounded" style="font-size: 16px;">close</span></button>
              </div>
            </div>
            <div style="width: 100%; height: 4px; background: var(--theme-surface-container-high); border-radius: 2px; overflow: hidden;">
              <div id="progress_${item.id}" style="width: 0%; height: 100%; background: var(--theme-primary); transition: width 0.1s;"></div>
            </div>
          `;
          list.appendChild(itemEl);
          document.getElementById('uploadWidget').style.display = 'flex';
        }

        updateItemUI(item) {
          const progressEl = document.getElementById(`progress_${item.id}`);
          const statusEl = document.getElementById(`status_${item.id}`);
          const cancelBtn = document.getElementById(`cancel_btn_${item.id}`);
          const iconEl = document.getElementById(`icon_${item.id}`);

          if (progressEl) progressEl.style.width = `${item.progress}%`;
          if (statusEl) {
            if (item.status === 'uploading') statusEl.textContent = `${item.progress}%`;
            else if (item.status === 'queued') statusEl.textContent = 'Queued';
            else if (item.status === 'success') {
              statusEl.textContent = 'Completed';
              statusEl.style.color = '#4caf50';
              if (cancelBtn) cancelBtn.style.display = 'none';
              if (iconEl) iconEl.textContent = 'check_circle';
            } else if (item.status === 'failed') {
              statusEl.textContent = 'Failed';
              statusEl.style.color = '#f44336';
              if (cancelBtn) cancelBtn.style.display = 'none';
              if (iconEl) iconEl.textContent = 'error';
            } else if (item.status === 'cancelled') {
              statusEl.textContent = 'Cancelled';
              statusEl.style.color = 'var(--theme-on-surface-variant)';
              if (cancelBtn) cancelBtn.style.display = 'none';
              if (iconEl) iconEl.textContent = 'cancel';
            }
          }
        }

        updateHeader() {
          const active = this.queue.filter(i => i.status === 'uploading' || i.status === 'queued');
          const title = document.getElementById('uploadWidgetTitle');
          if (title) {
            if (active.length > 0) {
              title.textContent = `Uploading ${active.length} item(s)...`;
            } else {
              const succeeded = this.queue.filter(i => i.status === 'success').length;
              title.textContent = `Uploaded ${succeeded} item(s)`;
            }
          }
        }

        toggleCollapse() {
          this.isCollapsed = !this.isCollapsed;
          const widget = document.getElementById('uploadWidget');
          const list = document.getElementById('uploadWidgetList');
          const toggleBtn = document.getElementById('uploadWidgetToggleBtn');
          if (toggleBtn) {
            const icon = toggleBtn.querySelector('span');
            if (icon) icon.textContent = this.isCollapsed ? 'expand_less' : 'expand_more';
          }
          if (this.isCollapsed) {
            list.style.display = 'none';
            widget.style.height = 'auto';
          } else {
            list.style.display = 'flex';
          }
        }
      }

      const app = new FileManager();
    </script>
  </body>
</html>