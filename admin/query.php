<?php
/**
 * Builds the filtered registrations query shared by the list and the CSV
 * export, so the file you download is always exactly what you were looking at.
 */

declare(strict_types=1);

/**
 * @return array{0:string, 1:array} [whereSql, params]
 */
function reg_filters(array $q): array
{
    $where  = [];
    $params = [];

    $search = trim((string) ($q['q'] ?? ''));
    if ($search !== '') {
        // Name, email, mobile or registration id.
        $where[]  = '(full_name LIKE ? OR email LIKE ? OR mobile LIKE ? OR registration_id LIKE ?)';
        $like     = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $cat = (string) ($q['category'] ?? '');
    if ($cat !== '' && category_exists($cat)) {
        $where[]  = 'category = ?';
        $params[] = $cat;
    }

    $status = (string) ($q['status'] ?? '');
    if (in_array($status, ['pending', 'awaiting', 'paid', 'free', 'failed'], true)) {
        $where[]  = 'status = ?';
        $params[] = $status;
    }

    $from = (string) ($q['from'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[]  = 'created_at >= ?';
        $params[] = $from . ' 00:00:00';
    }

    $to = (string) ($q['to'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where[]  = 'created_at <= ?';
        $params[] = $to . ' 23:59:59';
    }

    $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    return [$sql, $params];
}

/** Column names can never come from the URL unchecked - that is an injection. */
function reg_sort(array $q): string
{
    $allowed = [
        'created_at' => 'created_at',
        'name'       => 'full_name',
        'category'   => 'category',
        'amount'     => 'amount_paise',
        'status'     => 'status',
    ];
    $col = $allowed[(string) ($q['sort'] ?? '')] ?? 'created_at';
    $dir = strtolower((string) ($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    return ' ORDER BY ' . $col . ' ' . $dir . ', id DESC';
}

/** Keeps the current filters when building a link. */
function link_with(array $over, string $base = 'index.php'): string
{
    $q = array_merge($_GET, $over);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null) {
            unset($q[$k]);
        }
    }
    return $base . ($q ? '?' . http_build_query($q) : '');
}

/** True when the list is showing anything other than everything. */
function has_filters(): bool
{
    foreach (['q', 'category', 'status', 'from', 'to'] as $k) {
        if (trim((string) ($_GET[$k] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}
