<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(int $code, array $body): void { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE); exit; }
function fail(int $code, string $msg, array $extra = []): void { out($code, ['ok' => false, 'error' => $msg] + $extra); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Use POST.');
$u = current_user();
if (!$u) fail(401, 'You are signed out.');
if (!csrf_ok($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) fail(403, 'Your session changed. Reload the page.');

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 24 * 1024 * 1024) fail(413, 'That is too large to save.');
$in = json_decode($raw, true);
if (!is_array($in)) fail(400, 'The request could not be read.');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

    /* Save the whole builder state of one project (optimistic locking on rev). */
    case 'save': {
        $id = (int)($in['id'] ?? 0);
        $p = $id ? load_project($id, $u) : null;
        if (!$p) fail(404, 'This project no longer exists or isn’t yours.');
        if (!isset($in['state']) || !is_array($in['state'])) fail(400, 'Nothing to save.');
        $state = json_encode($in['state'], JSON_UNESCAPED_UNICODE);
        if ($state === false) fail(400, 'The project data could not be stored.');
        $m = is_array($in['meta'] ?? null) ? $in['meta'] : [];
        $num = fn($v) => is_numeric($v) ? (float)$v : 0.0;
        $rev = (int)($in['rev'] ?? -1);
        $st = db()->prepare('UPDATE projects SET state = ?, step = ?, customer_name = ?, project_title = ?, provider = ?, version = ?,
                               monthly_usd = ?, one_time_usd = ?, person_days = ?, rev = rev + 1, updated_at = NOW()
                             WHERE id = ? AND rev = ?');
        $st->execute([
            $state,
            max(0, min(20, (int)($in['step'] ?? 0))),
            mb_substr((string)($m['customer'] ?? ''), 0, 190),
            mb_substr((string)($m['project'] ?? ''), 0, 255),
            mb_substr((string)($m['provider'] ?? ''), 0, 32),
            mb_substr((string)($m['version'] ?? ''), 0, 20),
            round($num($m['monthly'] ?? 0), 2),
            round($num($m['once'] ?? 0), 2),
            round($num($m['days'] ?? 0), 1),
            $id, $rev,
        ]);
        if ($st->rowCount() === 0) {
            $cur = db()->prepare('SELECT rev, updated_at FROM projects WHERE id = ?');
            $cur->execute([$id]);
            $c = $cur->fetch();
            fail(409, 'This project was saved from another window.', ['rev' => (int)($c['rev'] ?? 0), 'updated_at' => $c['updated_at'] ?? null]);
        }
        out(200, ['ok' => true, 'rev' => $rev + 1, 'saved_at' => date('c')]);
    }

    /* Upload a BOM template (.xlsx) for this user, or as the company default (admins). */
    case 'template_put': {
        $scope = ($in['scope'] ?? 'user') === 'company' ? 'company' : 'user';
        if ($scope === 'company' && $u['role'] !== 'admin') fail(403, 'Only administrators can set the company template.');
        $name = mb_substr(basename((string)($in['name'] ?? 'template.xlsx')), 0, 255);
        if (!preg_match('~\.xlsx$~i', $name)) fail(400, 'Use an Excel workbook (.xlsx).');
        $data = base64_decode((string)($in['data'] ?? ''), true);
        if ($data === false || strlen($data) < 100) fail(400, 'The workbook could not be read.');
        if (strlen($data) > (int)cfg('max_template_mb', 10) * 1024 * 1024) fail(413, 'That workbook is larger than the ' . (int)cfg('max_template_mb', 10) . ' MB limit.');
        if (substr($data, 0, 4) !== "PK\x03\x04") fail(400, 'That file is not an .xlsx workbook.');
        $sheets = array_values(array_map(fn($s) => mb_substr((string)$s, 0, 64), array_slice((array)($in['sheets'] ?? []), 0, 100)));

        $pdo = db();
        $pdo->beginTransaction();
        if ($scope === 'company') $pdo->exec("DELETE FROM bom_templates WHERE scope = 'company'");
        else $pdo->prepare("DELETE FROM bom_templates WHERE scope = 'user' AND user_id = ?")->execute([$u['id']]);
        $st = $pdo->prepare('INSERT INTO bom_templates (scope, user_id, filename, size_bytes, sheets, data, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $st->bindValue(1, $scope);
        $st->bindValue(2, $scope === 'company' ? null : $u['id'], $scope === 'company' ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->bindValue(3, $name);
        $st->bindValue(4, strlen($data), PDO::PARAM_INT);
        $st->bindValue(5, json_encode($sheets, JSON_UNESCAPED_UNICODE));
        $st->bindValue(6, $data, PDO::PARAM_LOB);
        $st->bindValue(7, $u['id'], PDO::PARAM_INT);
        $st->execute();
        $pdo->commit();

        $t = effective_template((int)$u['id'], false);
        out(200, ['ok' => true, 'tpl' => template_meta($t)]);
    }

    /* Remove this user's template (or the company one, for admins); returns what applies now. */
    case 'template_delete': {
        $scope = ($in['scope'] ?? 'user') === 'company' ? 'company' : 'user';
        if ($scope === 'company') {
            if ($u['role'] !== 'admin') fail(403, 'Only administrators can remove the company template.');
            db()->exec("DELETE FROM bom_templates WHERE scope = 'company'");
        } else {
            db()->prepare("DELETE FROM bom_templates WHERE scope = 'user' AND user_id = ?")->execute([$u['id']]);
        }
        $t = effective_template((int)$u['id'], true);
        out(200, ['ok' => true, 'tpl' => template_meta($t), 'data' => $t ? base64_encode($t['data']) : null]);
    }

    default:
        fail(400, 'Unknown action.');
    }
} catch (PDOException $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('[solution-builder] ' . $e->getMessage());
    fail(500, cfg('debug') ? $e->getMessage() : 'The database could not save that. Try again.');
}
