<?php
/**
 * Serves one uploaded ID proof.
 *
 * The files live outside public_html and have no URL of their own. This is the
 * only way to see one, it requires a signed-in session, and every view is
 * recorded. These are identity documents; that is the whole point.
 */

declare(strict_types=1);
require_once __DIR__ . '/auth.php';

require_admin();
require_can('view_id_proof');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Bad request.');
}

$st = db()->prepare('SELECT registration_id, id_proof_file FROM registrations WHERE id = ?');
$st->execute([$id]);
$row = $st->fetch();

if (!$row || !$row['id_proof_file']) {
    http_response_code(404);
    exit('No ID proof on file for that registration.');
}

// The filename came from our own random generator, but it is read back out of
// the database, so treat it as untrusted anyway: strip any path, then confirm
// the resolved file really sits inside the upload directory. Without this a
// stored value of "../../config.php" would read a file it has no business
// reading.
$name = basename((string) $row['id_proof_file']);
$dir  = id_upload_dir();
$path = $dir . '/' . $name;

$real    = realpath($path);
$realDir = realpath($dir);

if ($real === false || $realDir === false || strpos($real, $realDir . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404);
    exit('That file is missing from the upload folder.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = (string) $finfo->file($real);

// Only ever hand back the types we accepted on upload. Anything else is
// downloaded rather than rendered, so the browser cannot be talked into
// executing it.
$safe = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
$download = isset($_GET['download']) || !in_array($mime, $safe, true);

audit('view_id_proof', (string) $row['registration_id']);

$ext      = pathinfo($real, PATHINFO_EXTENSION);
$filename = $row['registration_id'] . '-id.' . ($ext ?: 'bin');

no_store();
header('Content-Type: ' . ($download ? 'application/octet-stream' : $mime));
header('Content-Length: ' . filesize($real));
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline')
    . '; filename="' . $filename . '"');
// Belt and braces: never let this response be treated as a page.
header("Content-Security-Policy: default-src 'none'; img-src 'self'; object-src 'self'");

readfile($real);
