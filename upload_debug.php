<?php
/**
 * upload_debug.php
 * DROP THIS FILE in the same folder as admin.php
 * Open it in your browser, try uploading a file, and it will tell you EXACTLY what's wrong.
 * DELETE IT after fixing — do not leave on production!
 */

$results = [];

// ── 1. PHP Upload Settings ──────────────────────────────────────────────────
$results['php_upload_enabled']    = ini_get('file_uploads') ? '✅ ON' : '❌ OFF — uploads disabled in php.ini!';
$results['upload_max_filesize']   = ini_get('upload_max_filesize');
$results['post_max_size']         = ini_get('post_max_size');
$results['max_file_uploads']      = ini_get('max_file_uploads');
$results['upload_tmp_dir']        = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
$results['tmp_dir_writable']      = is_writable(ini_get('upload_tmp_dir') ?: sys_get_temp_dir()) ? '✅ Writable' : '❌ NOT writable!';

// ── 2. Target Directory ─────────────────────────────────────────────────────
$upload_dir = __DIR__ . '/uploads/files/';
$results['upload_dir_path']    = $upload_dir;
$results['upload_dir_exists']  = is_dir($upload_dir) ? '✅ Exists' : '❌ Does NOT exist';
$results['upload_dir_writable']= is_writable($upload_dir) ? '✅ Writable' : '❌ NOT writable (chmod 755 or chown www-data)';

// Try creating it if missing
if (!is_dir($upload_dir)) {
    $made = @mkdir($upload_dir, 0755, true);
    $results['mkdir_attempt'] = $made ? '✅ Created successfully' : '❌ mkdir() FAILED — check parent folder permissions';
}

// ── 3. finfo Extension ──────────────────────────────────────────────────────
$results['finfo_extension'] = extension_loaded('fileinfo') ? '✅ Loaded' : '❌ fileinfo extension missing!';

// ── 4. Session ──────────────────────────────────────────────────────────────
session_start();
$results['session_admin_logged_in'] = !empty($_SESSION['admin_logged_in'])
    ? '✅ Logged in as: ' . ($_SESSION['admin_name'] ?? 'unknown')
    : '❌ NOT logged in — upload will return Unauthorized. Log into admin.php first, then come back here.';

// ── 5. Handle actual test upload ────────────────────────────────────────────
$upload_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['test_file'])) {
    $f = $_FILES['test_file'];
    $upload_result = [
        'original_name' => $f['name'],
        'size'          => $f['size'] . ' bytes',
        'tmp_name'      => $f['tmp_name'],
        'error_code'    => $f['error'],
        'error_meaning' => [
            0 => '✅ No error',
            1 => '❌ UPLOAD_ERR_INI_SIZE — file exceeds upload_max_filesize in php.ini',
            2 => '❌ UPLOAD_ERR_FORM_SIZE — file exceeds MAX_FILE_SIZE in form',
            3 => '❌ UPLOAD_ERR_PARTIAL — file only partially uploaded',
            4 => '❌ UPLOAD_ERR_NO_FILE — no file was uploaded',
            6 => '❌ UPLOAD_ERR_NO_TMP_DIR — missing temp folder',
            7 => '❌ UPLOAD_ERR_CANT_WRITE — failed to write to disk',
            8 => '❌ UPLOAD_ERR_EXTENSION — a PHP extension stopped the upload',
        ][$f['error']] ?? '❌ Unknown error code: ' . $f['error'],
    ];

    if ($f['error'] === 0) {
        // Check MIME
        if (extension_loaded('fileinfo')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $f['tmp_name']);
            finfo_close($finfo);
            $upload_result['detected_mime'] = $mime;
        }
        // Try saving
        $dest = $upload_dir . 'test_' . time() . '_' . basename($f['name']);
        $moved = @move_uploaded_file($f['tmp_name'], $dest);
        $upload_result['move_uploaded_file'] = $moved ? '✅ SUCCESS — file saved to: ' . $dest : '❌ FAILED — move_uploaded_file() returned false. Directory not writable or safe_mode issue.';
        if ($moved) @unlink($dest); // clean up test file
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Upload Debugger</title>
<style>
  body { font-family: monospace; background: #0f172a; color: #e2e8f0; padding: 2rem; }
  h1 { color: #a78bfa; margin-bottom: 1.5rem; }
  h2 { color: #7dd3fc; margin: 1.5rem 0 .5rem; font-size: 1rem; border-bottom: 1px solid #334155; padding-bottom: .3rem; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; }
  td { padding: .4rem .6rem; border-bottom: 1px solid #1e293b; font-size: .85rem; }
  td:first-child { color: #94a3b8; width: 260px; }
  .ok  { color: #4ade80; }
  .err { color: #f87171; }
  form { background: #1e293b; padding: 1.5rem; border-radius: 8px; margin-top: 1rem; display: inline-block; }
  input[type=file] { color: #e2e8f0; }
  button { margin-top: .8rem; background: #7c3aed; color: #fff; border: none; padding: .5rem 1.2rem; border-radius: 6px; cursor: pointer; font-size: .9rem; display: block; }
  pre { background: #1e293b; padding: 1rem; border-radius: 8px; font-size: .8rem; overflow-x: auto; }
  .warn { color: #fbbf24; }
</style>
</head>
<body>
<h1>🔍 Upload Debugger</h1>
<p class="warn">⚠️ Delete this file after debugging — never leave it on production!</p>

<h2>PHP Configuration</h2>
<table>
<?php foreach (['php_upload_enabled','upload_max_filesize','post_max_size','max_file_uploads','upload_tmp_dir','tmp_dir_writable'] as $k): ?>
<tr><td><?= $k ?></td><td><?= htmlspecialchars($results[$k] ?? '') ?></td></tr>
<?php endforeach; ?>
</table>

<h2>Upload Directory</h2>
<table>
<?php foreach (['upload_dir_path','upload_dir_exists','upload_dir_writable'] as $k): ?>
<tr><td><?= $k ?></td><td><?= htmlspecialchars($results[$k] ?? '') ?></td></tr>
<?php endforeach; ?>
<?php if (isset($results['mkdir_attempt'])): ?>
<tr><td>mkdir_attempt</td><td><?= htmlspecialchars($results['mkdir_attempt']) ?></td></tr>
<?php endif; ?>
</table>

<h2>Extensions & Session</h2>
<table>
<?php foreach (['finfo_extension','session_admin_logged_in'] as $k): ?>
<tr><td><?= $k ?></td><td><?= htmlspecialchars($results[$k] ?? '') ?></td></tr>
<?php endforeach; ?>
</table>

<h2>Live Upload Test</h2>
<form method="POST" enctype="multipart/form-data">
  <label>Pick any small file:<br><input type="file" name="test_file" style="margin-top:.4rem"></label>
  <button type="submit">Test Upload</button>
</form>

<?php if ($upload_result): ?>
<h2>Test Upload Result</h2>
<pre><?= htmlspecialchars(print_r($upload_result, true)) ?></pre>
<?php endif; ?>

</body>
</html>
