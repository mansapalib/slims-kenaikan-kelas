<?php
/**
 * Plugin Name: Mass Class Rollover & Member Promotion
 * File: index.php
 * Module: Membership
 * Author: MANSAPA Iqra Labs
 */

defined('INDEX_AUTH') OR die('Akses langsung tidak diizinkan.');

// IP based access limitation
require_once LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-membership');

// Start the session & session checking
require_once SB . 'admin/default/session.inc.php';
require_once SB . 'admin/default/session_check.inc.php';

// Privileges checking
$can_read  = utility::havePrivilege('membership', 'r');
$can_write = utility::havePrivilege('membership', 'w');

if (!$can_read) {
    die('<div class="alert alert-danger font-weight-bold m-3">' . __('You don\'t have enough privileges to view this section!') . '</div>');
}

// Keep $dbs as SLiMS mysqli instance for utility::writeLogs, use $pdo for PDO transactions
$mysqli_dbs = $dbs ?? \SLiMS\DB::getInstance('mysqli');
$pdo        = \SLiMS\DB::getInstance();

if (empty($_SESSION['mass_rollover_csrf'])) {
    $_SESSION['mass_rollover_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['mass_rollover_csrf'];

$is_valid_date = static function (string $date): bool {
    if ($date === '') {
        return false;
    }
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
};

$write_audit_log = static function (string $msg) use ($mysqli_dbs): void {
    try {
        if ($mysqli_dbs && class_exists('utility') && method_exists('utility', 'writeLogs')) {
            utility::writeLogs($mysqli_dbs, 'staff', $_SESSION['uid'] ?? 1, 'Membership', $msg, 'Rollover', 'Update');
        }
    } catch (\Throwable $e) {
        // Jangan gagalkan respons sukses jika pencatatan log mengalami kendala
    }
};

// =========================================================================
// DETEKSI DINAMIS TIPE ANGGOTA (SISWA & ALUMNI) UNTUK PLUG-AND-PLAY
// =========================================================================
$mtypes_stmt  = $pdo->query("SELECT member_type_id, member_type_name FROM mst_member_type ORDER BY member_type_id ASC");
$member_types = $mtypes_stmt ? $mtypes_stmt->fetchAll(PDO::FETCH_ASSOC) : [];

$default_student_type_id   = null;
$default_student_type_name = 'Siswa';
$alumni_type_id            = null;

foreach ($member_types as $mt) {
    $mt_id   = (int)$mt['member_type_id'];
    $mt_name = trim((string)$mt['member_type_name']);

    if ($alumni_type_id === null && preg_match('/(alumni|lulusan|tamatan)/i', $mt_name)) {
        $alumni_type_id = $mt_id;
    }
    if ($default_student_type_id === null && preg_match('/(siswa|student|pelajar|santri|murid|mahasiswa)/i', $mt_name)) {
        $default_student_type_id   = $mt_id;
        $default_student_type_name = $mt_name;
    }
}

// Fallback jika dipasang di SLiMS yang belum memiliki tipe bernama "Siswa"
if ($default_student_type_id === null) {
    foreach ($member_types as $mt) {
        $mt_id = (int)$mt['member_type_id'];
        if ($mt_id !== $alumni_type_id) {
            $default_student_type_id   = $mt_id;
            $default_student_type_name = trim((string)$mt['member_type_name']);
            break;
        }
    }
}

$default_filter_mtype = $default_student_type_id !== null ? (string)$default_student_type_id : 'all';

$message     = '';
$plugin_id   = $_GET['id'] ?? md5(realpath(__FILE__));
$form_action = AWB . 'plugin_container.php?mod=membership&id=' . urlencode($plugin_id);

// =========================================================================
// AJAX ENDPOINT: LIVE MEMBER PREVIEW PER CLASS
// =========================================================================
if (isset($_GET['ajax_preview']) && $_GET['ajax_preview'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    $cls   = trim((string)($_GET['class'] ?? ''));
    $mtype = trim((string)($_GET['mtype'] ?? $default_filter_mtype));

    if ($cls === '') {
        echo json_encode(['status' => false, 'message' => 'Kelas tidak ditentukan']);
        exit;
    }

    $where  = "m.inst_name = :cls";
    $params = [':cls' => $cls];
    if ($mtype !== 'all' && $mtype !== '') {
        $where .= " AND m.member_type_id = :mtype";
        $params[':mtype'] = (int)$mtype;
    }

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM member m WHERE {$where}");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT m.member_id, m.member_name, m.inst_name, m.expire_date, m.is_pending, mt.member_type_name 
                           FROM member m 
                           LEFT JOIN mst_member_type mt ON m.member_type_id = mt.member_type_id 
                           WHERE {$where} 
                           ORDER BY m.member_name ASC LIMIT 25");
    $stmt->execute($params);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => true, 'total' => $total, 'members' => $members]);
    exit;
}

// =========================================================================
// POST EXECUTION: ROLLOVER, GRADUATION, OR RENEWAL
// =========================================================================
if (isset($_POST['execute_rollover'])) {
    $posted_csrf = (string)($_POST['csrf_token'] ?? '');

    if (!$can_write) {
        $message = '<div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>Akses Ditolak:</strong> Anda tidak memiliki hak akses tulis (write) untuk modul keanggotaan.
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>';
    } elseif ($posted_csrf === '' || !hash_equals($csrf_token, $posted_csrf)) {
        $message = '<div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>Token Keamanan Tidak Valid:</strong> Sesi formulir kadaluarsa. Silakan muat ulang halaman dan coba lagi.
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>';
    } else {
        $action_type  = trim((string)($_POST['action_type'] ?? 'promote'));
        $source_class = trim((string)($_POST['source_class'] ?? ''));
        $filter_mtype = trim((string)($_POST['filter_member_type'] ?? $default_filter_mtype));

        if ($source_class === '') {
            $message = '<div class="alert alert-warning alert-dismissible fade show" role="alert">
                <strong>Perhatian:</strong> Silakan pilih kelas asal terlebih dahulu.
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>';
        } elseif (!in_array($action_type, ['promote', 'graduate', 'renew'], true)) {
            $message = '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                <strong>Kesalahan:</strong> Jenis operasi tidak dikenali.
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>';
        } else {
            try {
                $pdo->beginTransaction();

                // WHERE condition filter
                $where  = "inst_name = :source";
                $params = [':source' => $source_class];
                if ($filter_mtype !== 'all' && $filter_mtype !== '') {
                    $where .= " AND member_type_id = :mtype";
                    $params[':mtype'] = (int)$filter_mtype;
                }

                if ($action_type === 'promote') {
                    // MODE 1: NAIK KELAS
                    $target_class    = mb_substr(trim((string)($_POST['target_class'] ?? '')), 0, 100, 'UTF-8');
                    $expire_mode     = (string)($_POST['expire_mode'] ?? 'keep');
                    $new_expire_date = trim((string)($_POST['new_expire'] ?? ''));
                    $new_mtype       = trim((string)($_POST['new_member_type_id'] ?? 'keep'));

                    if ($target_class === '') {
                        throw new \RuntimeException('Nama kelas tujuan baru wajib diisi untuk proses kenaikan kelas.');
                    }
                    if (strcasecmp($source_class, $target_class) === 0) {
                        throw new \RuntimeException('Nama kelas tujuan tidak boleh sama dengan kelas asal.');
                    }
                    if ($expire_mode === 'custom' && !$is_valid_date($new_expire_date)) {
                        throw new \RuntimeException('Format tanggal kadaluarsa baru tidak valid (gunakan YYYY-MM-DD).');
                    }

                    $updates = ["inst_name = :target", "last_update = CURRENT_DATE"];
                    $params[':target'] = $target_class;

                    if ($expire_mode === 'custom') {
                        $updates[] = "expire_date = :expire";
                        $params[':expire'] = $new_expire_date;
                    } elseif ($expire_mode === 'add_year') {
                        $updates[] = "expire_date = DATE_ADD(CASE WHEN expire_date IS NULL OR expire_date < CURRENT_DATE THEN CURRENT_DATE ELSE expire_date END, INTERVAL 1 YEAR)";
                    }

                    if ($new_mtype !== '' && $new_mtype !== 'keep') {
                        $updates[] = "member_type_id = :new_mtype";
                        $params[':new_mtype'] = (int)$new_mtype;
                    }

                    $sql  = "UPDATE member SET " . implode(', ', $updates) . " WHERE {$where}";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $affected = $stmt->rowCount();

                    $pdo->commit();

                    $log_msg = sprintf(
                        'Rollover Kenaikan Kelas: %d anggota dari kelas "%s" berhasil dipindahkan ke kelas "%s"',
                        $affected,
                        $source_class,
                        $target_class
                    );
                    $write_audit_log($log_msg);

                    $message = '<div class="alert alert-success alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
                        <div class="mr-3 fs-3">🎉</div>
                        <div>
                            <h5 class="alert-heading mb-1 font-weight-bold">Kenaikan Kelas Berhasil!</h5>
                            <div>Sebanyak <strong>' . $affected . '</strong> anggota dari kelas <strong>' . htmlspecialchars($source_class, ENT_QUOTES, 'UTF-8') . '</strong> berhasil dinaikkan ke kelas <strong>' . htmlspecialchars($target_class, ENT_QUOTES, 'UTF-8') . '</strong>.</div>
                        </div>
                        <button type="button" class="close ml-auto" data-dismiss="alert">&times;</button>
                    </div>';

                } elseif ($action_type === 'graduate') {
                    // MODE 2: KELULUSAN / ALUMNI
                    $grad_class_action  = (string)($_POST['grad_class_action'] ?? 'prefix');
                    $grad_target_class  = mb_substr(trim((string)($_POST['grad_target_class'] ?? '')), 0, 100, 'UTF-8');
                    $grad_mtype         = trim((string)($_POST['grad_member_type'] ?? ($alumni_type_id !== null ? (string)$alumni_type_id : 'create_alumni')));
                    $grad_status        = trim((string)($_POST['grad_status'] ?? 'pending'));
                    $grad_expire_mode   = (string)($_POST['grad_expire_mode'] ?? 'today');
                    $grad_custom_expire = trim((string)($_POST['grad_custom_expire'] ?? ''));

                    if ($grad_class_action === 'custom' && $grad_target_class === '') {
                        throw new \RuntimeException('Nama kelas/label kustom wajib diisi jika memilih opsi nama kelas kustom.');
                    }
                    if ($grad_expire_mode === 'custom' && !$is_valid_date($grad_custom_expire)) {
                        throw new \RuntimeException('Format tanggal kadaluarsa kustom tidak valid (gunakan YYYY-MM-DD).');
                    }

                    // Buat tipe anggota Alumni otomatis jika dipilih & belum ada di database
                    if ($grad_mtype === 'create_alumni') {
                        if ($alumni_type_id !== null) {
                            $grad_mtype = (string)$alumni_type_id;
                        } else {
                            $ins_mt = $pdo->prepare("INSERT INTO mst_member_type (member_type_name, loan_limit, loan_periode, enable_reserve, reserve_limit, member_periode, reborrow_limit, fine_each_day, grace_periode, input_date, last_update) VALUES ('Alumni', 0, 0, 0, 0, 0, 0, 0, 0, CURRENT_DATE, CURRENT_DATE)");
                            $ins_mt->execute();
                            $alumni_type_id = (int)$pdo->lastInsertId();
                            $grad_mtype     = (string)$alumni_type_id;
                        }
                    }

                    $updates = ["last_update = CURRENT_DATE"];

                    if ($grad_status === 'pending') {
                        $updates[] = "is_pending = 1";
                    }

                    if ($grad_expire_mode === 'today') {
                        $updates[] = "expire_date = CURRENT_DATE";
                    } elseif ($grad_expire_mode === 'custom') {
                        $updates[] = "expire_date = :grad_expire";
                        $params[':grad_expire'] = $grad_custom_expire;
                    }

                    if ($grad_class_action === 'prefix') {
                        $prefixed = (stripos($source_class, 'Alumni - ') === 0)
                            ? $source_class
                            : mb_substr('Alumni - ' . $source_class, 0, 100, 'UTF-8');
                        $updates[] = "inst_name = :grad_prefixed";
                        $params[':grad_prefixed'] = $prefixed;
                    } elseif ($grad_class_action === 'custom') {
                        $updates[] = "inst_name = :grad_target";
                        $params[':grad_target'] = $grad_target_class;
                    } elseif ($grad_class_action === 'clear') {
                        $updates[] = "inst_name = ''";
                    }

                    if ($grad_mtype !== '' && $grad_mtype !== 'keep') {
                        $updates[] = "member_type_id = :grad_mtype";
                        $params[':grad_mtype'] = (int)$grad_mtype;
                    }

                    $sql  = "UPDATE member SET " . implode(', ', $updates) . " WHERE {$where}";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $affected = $stmt->rowCount();

                    $pdo->commit();

                    $log_msg = sprintf(
                        'Kelulusan Anggota: %d anggota dari kelas "%s" berhasil diproses status kelulusannya',
                        $affected,
                        $source_class
                    );
                    $write_audit_log($log_msg);

                    $message = '<div class="alert alert-info alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
                        <div class="mr-3 fs-3">🎓</div>
                        <div>
                            <h5 class="alert-heading mb-1 font-weight-bold">Proses Kelulusan Selesai!</h5>
                            <div>Sebanyak <strong>' . $affected . '</strong> anggota dari kelas <strong>' . htmlspecialchars($source_class, ENT_QUOTES, 'UTF-8') . '</strong> berhasil diarsipkan dan statusnya diperbarui.</div>
                        </div>
                        <button type="button" class="close ml-auto" data-dismiss="alert">&times;</button>
                    </div>';

                } elseif ($action_type === 'renew') {
                    // MODE 3: PERPANJANG MASA AKTIF SAJA
                    $renew_mode        = (string)($_POST['renew_mode'] ?? 'add_year');
                    $renew_custom_date = trim((string)($_POST['renew_custom_date'] ?? ''));

                    if ($renew_mode === 'custom' && !$is_valid_date($renew_custom_date)) {
                        throw new \RuntimeException('Format tanggal perpanjangan kustom tidak valid (gunakan YYYY-MM-DD).');
                    }

                    $updates = ["last_update = CURRENT_DATE"];
                    if ($renew_mode === 'add_year') {
                        $updates[] = "expire_date = DATE_ADD(CASE WHEN expire_date IS NULL OR expire_date < CURRENT_DATE THEN CURRENT_DATE ELSE expire_date END, INTERVAL 1 YEAR)";
                    } elseif ($renew_mode === 'custom') {
                        $updates[] = "expire_date = :renew_date";
                        $params[':renew_date'] = $renew_custom_date;
                    }

                    $sql  = "UPDATE member SET " . implode(', ', $updates) . " WHERE {$where}";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $affected = $stmt->rowCount();

                    $pdo->commit();

                    $log_msg = sprintf(
                        'Perpanjangan Masa Aktif: %d anggota di kelas "%s" diperpanjang masa aktifnya',
                        $affected,
                        $source_class
                    );
                    $write_audit_log($log_msg);

                    $message = '<div class="alert alert-success alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
                        <div class="mr-3 fs-3">📅</div>
                        <div>
                            <h5 class="alert-heading mb-1 font-weight-bold">Perpanjangan Berhasil!</h5>
                            <div>Masa berlaku kartu anggota sebanyak <strong>' . $affected . '</strong> anggota kelas <strong>' . htmlspecialchars($source_class, ENT_QUOTES, 'UTF-8') . '</strong> berhasil diperpanjang.</div>
                        </div>
                        <button type="button" class="close ml-auto" data-dismiss="alert">&times;</button>
                    </div>';
                }

            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <strong>Gagal Memperbarui Data:</strong> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>';
            }
        }
    }
}

// =========================================================================
// REFRESH DATA FOR VIEW: CLASSES BY MEMBER TYPE & STATS
// =========================================================================
$mtypes_stmt  = $pdo->query("SELECT member_type_id, member_type_name FROM mst_member_type ORDER BY member_type_id ASC");
$member_types = $mtypes_stmt ? $mtypes_stmt->fetchAll(PDO::FETCH_ASSOC) : [];

$classes_by_type = ['all' => []];
$all_map         = [];

$cls_stmt = $pdo->query("SELECT member_type_id, inst_name, COUNT(*) AS total 
                         FROM member 
                         WHERE inst_name IS NOT NULL AND TRIM(inst_name) != '' 
                         GROUP BY member_type_id, inst_name 
                         ORDER BY inst_name ASC");
if ($cls_stmt) {
    while ($row = $cls_stmt->fetch(PDO::FETCH_ASSOC)) {
        $tid   = (string)$row['member_type_id'];
        $iname = (string)$row['inst_name'];
        $cnt   = (int)$row['total'];

        if (!isset($classes_by_type[$tid])) {
            $classes_by_type[$tid] = [];
        }
        $classes_by_type[$tid][] = ['inst_name' => $iname, 'total' => $cnt];

        if (!isset($all_map[$iname])) {
            $all_map[$iname] = 0;
        }
        $all_map[$iname] += $cnt;
    }
}

ksort($all_map, SORT_NATURAL | SORT_FLAG_CASE);
foreach ($all_map as $iname => $cnt) {
    $classes_by_type['all'][] = ['inst_name' => $iname, 'total' => $cnt];
}

$initial_classes = $classes_by_type[$default_filter_mtype] ?? $classes_by_type['all'];

if ($default_student_type_id !== null) {
    $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM member WHERE member_type_id = :tid");
    $total_stmt->execute([':tid' => $default_student_type_id]);
    $total_siswa_count = (int)$total_stmt->fetchColumn();
} else {
    $total_stmt        = $pdo->query("SELECT COUNT(*) FROM member");
    $total_siswa_count = $total_stmt ? (int)$total_stmt->fetchColumn() : 0;
}
?>

<div class="menuBox">
    <div class="menuBoxInner memberIcon">
        <div class="per_title">
            <h2 class="d-flex align-items-center">
                <span>Rollover & Kenaikan Kelas Massal</span>
                <span class="badge badge-primary ml-3" style="font-size: 0.8rem; font-weight: normal; border-radius: 999px; padding: 4px 12px;">v1.1.0</span>
            </h2>
        </div>
        <div class="sub_section">
            <p class="text-muted mb-0">Alat bantu otomatis untuk menaikkan tingkatan kelas siswa, mengarsipkan kelulusan ke status Alumni, atau memperpanjang masa aktif kartu anggota secara massal dalam hitungan detik.</p>
        </div>
    </div>
</div>

<div class="contentBox p-4" id="rolloverContentArea">
    <?= $message; ?>

    <!-- STATS OVERVIEW CARDS -->
    <div class="row mb-4">
        <div class="col-md-4 mb-2">
            <div class="card bg-light border-0 shadow-sm">
                <div class="card-body py-3 d-flex align-items-center">
                    <div class="bg-primary text-white rounded p-3 mr-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-size: 1.3rem;">
                        👥
                    </div>
                    <div>
                        <div class="text-muted small text-uppercase font-weight-bold">Total <?= htmlspecialchars($default_student_type_name, ENT_QUOTES, 'UTF-8'); ?> Terdaftar</div>
                        <div class="h4 font-weight-bold mb-0 text-dark"><?= number_format($total_siswa_count); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-2">
            <div class="card bg-light border-0 shadow-sm">
                <div class="card-body py-3 d-flex align-items-center">
                    <div class="bg-info text-white rounded p-3 mr-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-size: 1.3rem;">
                        🏫
                    </div>
                    <div>
                        <div class="text-muted small text-uppercase font-weight-bold">Jumlah Rombel / Kelas</div>
                        <div class="h4 font-weight-bold mb-0 text-dark"><?= count($initial_classes); ?> <span class="small font-weight-normal text-muted">Kelas <?= htmlspecialchars($default_student_type_name, ENT_QUOTES, 'UTF-8'); ?></span></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-2">
            <div class="card bg-light border-0 shadow-sm">
                <div class="card-body py-3 d-flex align-items-center">
                    <div class="bg-success text-white rounded p-3 mr-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-size: 1.3rem;">
                        🛡️
                    </div>
                    <div>
                        <div class="text-muted small text-uppercase font-weight-bold">Keamanan Transaksi</div>
                        <div class="h6 font-weight-bold mb-0 text-success">SQL Transaction & Logs Active</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MAIN FORM -->
    <div class="card border shadow-sm">
        <div class="card-header bg-white border-bottom py-3">
            <h5 class="mb-0 font-weight-bold text-dark d-flex align-items-center">
                <span class="mr-2">⚙️</span> Konfigurasi Pembaruan Data Massal
            </h5>
        </div>
        <div class="card-body p-4">
            <form id="rolloverForm" method="post" action="<?= htmlspecialchars($form_action, ENT_QUOTES, 'UTF-8'); ?>" class="form-horizontal">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                
                <!-- PILIH AKSI UTAMA -->
                <div class="form-group row mb-4">
                    <label class="col-md-3 col-form-label font-weight-bold text-dark pt-0">Jenis Operasi</label>
                    <div class="col-md-9">
                        <div class="row">
                            <div class="col-md-4 mb-2">
                                <label class="card h-100 border p-3 cursor-pointer text-left mode-card active border-primary bg-light" for="act_promote" style="cursor: pointer; transition: all .2s;">
                                    <div class="custom-control custom-radio">
                                        <input type="radio" class="custom-control-input" id="act_promote" name="action_type" value="promote" checked onchange="onActionTypeChange('promote')">
                                        <label class="custom-control-label font-weight-bold" for="act_promote">Naik Kelas</label>
                                    </div>
                                    <div class="small text-muted mt-2">Menaikkan nama kelas siswa ke tingkatan berikutnya (mis. X ke XI).</div>
                                </label>
                            </div>
                            <div class="col-md-4 mb-2">
                                <label class="card h-100 border p-3 cursor-pointer text-left mode-card" for="act_graduate" style="cursor: pointer; transition: all .2s;">
                                    <div class="custom-control custom-radio">
                                        <input type="radio" class="custom-control-input" id="act_graduate" name="action_type" value="graduate" onchange="onActionTypeChange('graduate')">
                                        <label class="custom-control-label font-weight-bold" for="act_graduate">Kelulusan / Alumni</label>
                                    </div>
                                    <div class="small text-muted mt-2">Arsipkan siswa kelas akhir, set nonaktif (pending), dan ubah ke tipe Alumni.</div>
                                </label>
                            </div>
                            <div class="col-md-4 mb-2">
                                <label class="card h-100 border p-3 cursor-pointer text-left mode-card" for="act_renew" style="cursor: pointer; transition: all .2s;">
                                    <div class="custom-control custom-radio">
                                        <input type="radio" class="custom-control-input" id="act_renew" name="action_type" value="renew" onchange="onActionTypeChange('renew')">
                                        <label class="custom-control-label font-weight-bold" for="act_renew">Perpanjang Masa Aktif</label>
                                    </div>
                                    <div class="small text-muted mt-2">Hanya perpanjang masa berlaku kartu 1 kelas tanpa mengubah nama kelas.</div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <hr class="my-4">

                <!-- FILTER TIPE ANGGOTA -->
                <div class="form-group row mb-3">
                    <label class="col-md-3 col-form-label font-weight-bold text-dark" for="filter_member_type">
                        Filter Tipe Anggota
                    </label>
                    <div class="col-md-6">
                        <select id="filter_member_type" name="filter_member_type" class="form-control" onchange="onMemberTypeFilterChange()">
                            <?php if ($default_student_type_id !== null): ?>
                                <option value="<?= (int)$default_student_type_id; ?>" selected><?= htmlspecialchars($default_student_type_name, ENT_QUOTES, 'UTF-8'); ?> (Direkomendasikan)</option>
                            <?php endif; ?>
                            <option value="all" <?= ($default_student_type_id === null) ? 'selected' : ''; ?>>Semua Tipe Anggota</option>
                            <?php foreach ($member_types as $mt): ?>
                                <?php if ((int)$mt['member_type_id'] !== (int)$default_student_type_id): ?>
                                    <option value="<?= (int)$mt['member_type_id']; ?>"><?= htmlspecialchars($mt['member_type_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">Secara default memproses anggota bertipe <strong><?= htmlspecialchars($default_student_type_name, ENT_QUOTES, 'UTF-8'); ?></strong> guna melindungi akun guru/staf.</small>
                    </div>
                </div>

                <!-- PILIH KELAS ASAL -->
                <div class="form-group row mb-3">
                    <label class="col-md-3 col-form-label font-weight-bold text-dark" for="source_class">
                        Pilih Kelas Asal <span class="text-danger">*</span>
                    </label>
                    <div class="col-md-6">
                        <select id="source_class" name="source_class" class="form-control" required onchange="onSourceClassChange()">
                            <option value="">-- Pilih Kelas yang Ingin Diperbarui --</option>
                            <?php foreach ($initial_classes as $cls): ?>
                                <option value="<?= htmlspecialchars($cls['inst_name'], ENT_QUOTES, 'UTF-8'); ?>" data-count="<?= (int)$cls['total']; ?>">
                                    <?= htmlspecialchars($cls['inst_name'], ENT_QUOTES, 'UTF-8'); ?> (<?= (int)$cls['total']; ?> anggota)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">Nilai diambil otomatis dari kolom Institusi/Kelas (<code>inst_name</code>) anggota sesuai tipe yang dipilih.</small>
                    </div>
                </div>

                <!-- LIVE MEMBER PREVIEW ACCORDION -->
                <div class="form-group row mb-4" id="member_preview_wrapper" style="display: none;">
                    <div class="col-md-9 offset-md-3">
                        <div class="card border-info bg-light">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="font-weight-bold text-info d-flex align-items-center">
                                        <span class="mr-2">📋</span>
                                        <span id="preview_summary_text">Memuat anggota kelas...</span>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-info" id="btnTogglePreview" onclick="togglePreviewTable()">
                                        Lihat Rincian Anggota
                                    </button>
                                </div>
                                <div id="preview_table_container" class="mt-3" style="display: none; max-height: 250px; overflow-y: auto;">
                                    <table class="table table-sm table-striped table-bordered bg-white mb-0" style="font-size: 0.85rem;">
                                        <thead class="thead-light">
                                            <tr>
                                                <th width="120">ID Anggota</th>
                                                <th>Nama Anggota</th>
                                                <th width="110">Masa Berlaku</th>
                                                <th width="100">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody id="preview_table_body">
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION MODE 1: NAIK KELAS (PROMOTE) -->
                <div id="section_promote">
                    <div class="form-group row mb-3">
                        <label class="col-md-3 col-form-label font-weight-bold text-dark" for="target_class">
                            Nama Kelas Tujuan <span class="text-danger">*</span>
                        </label>
                        <div class="col-md-6">
                            <input type="text" id="target_class" name="target_class" class="form-control" maxlength="100" required placeholder="Contoh: XI A, XI MIPA 1, atau XII IPS 2" list="targetClassSuggestions">
                            <datalist id="targetClassSuggestions">
                                <?php foreach ($classes_by_type['all'] as $cls): ?>
                                    <option value="<?= htmlspecialchars($cls['inst_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php endforeach; ?>
                            </datalist>
                            <small class="form-text text-muted">Ketik nama kelas baru untuk seluruh anggota di kelas asal (maks. 100 karakter).</small>
                        </div>
                    </div>

                    <div class="form-group row mb-3">
                        <label class="col-md-3 col-form-label font-weight-bold text-dark" for="expire_mode">
                            Pembaruan Masa Berlaku
                        </label>
                        <div class="col-md-6">
                            <select name="expire_mode" id="expire_mode" class="form-control" onchange="onExpireModeChange(this.value)">
                                <option value="add_year" selected>Tambah Otomatis 1 Tahun (+12 Bulan dari masa berlaku)</option>
                                <option value="keep">Pertahankan Masa Berlaku Saat Ini</option>
                                <option value="custom">Tetapkan Tanggal Tertentu...</option>
                            </select>
                            <div id="custom_expire_container" class="mt-2" style="display: none;">
                                <input type="date" name="new_expire" id="new_expire" class="form-control" value="<?= date('Y-m-d', strtotime('+1 year')); ?>">
                                <small class="form-text text-muted">Pilih tanggal kadaluarsa kartu anggota baru.</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-group row mb-3">
                        <label class="col-md-3 col-form-label font-weight-bold text-dark" for="new_member_type_id">
                            Tipe Anggota Baru (Opsional)
                        </label>
                        <div class="col-md-6">
                            <select name="new_member_type_id" id="new_member_type_id" class="form-control">
                                <option value="keep">-- Pertahankan Tipe Anggota Saat Ini --</option>
                                <?php foreach ($member_types as $mt): ?>
                                    <option value="<?= (int)$mt['member_type_id']; ?>"><?= htmlspecialchars($mt['member_type_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- SECTION MODE 2: KELULUSAN (GRADUATE) -->
                <div id="section_graduate" style="display: none;">
                    <div class="form-group row mb-3">
                        <label class="col-md-3 col-form-label font-weight-bold text-dark" for="grad_class_action">
                            Penanganan Nama Kelas
                        </label>
                        <div class="col-md-6">
                            <select name="grad_class_action" id="grad_class_action" class="form-control" onchange="onGradClassActionChange(this.value)">
                                <option value="prefix" selected>Ubah jadi "Alumni - [Nama Kelas]" (Contoh: Alumni - XII A)</option>
                                <option value="custom">Tetapkan Nama Kelas Kustom (Contoh: Alumni <?= date('Y'); ?>)</option>
                                <option value="clear">Kosongkan Kolom Kelas</option>
                                <option value="keep">Pertahankan Nama Kelas Lama</option>
                            </select>
                            <div id="grad_target_class_container" class="mt-2" style="display: none;">
                                <input type="text" id="grad_target_class" name="grad_target_class" class="form-control" maxlength="100" placeholder="Contoh: Alumni <?= date('Y'); ?> atau Lulusan <?= date('Y'); ?>">
                            </div>
                            <small class="form-text text-muted">Mengubah nama kelas siswa yang lulus akan mengosongkan nama kelas aktif untuk siswa tahun ajaran baru.</small>
                        </div>
                    </div>

                    <div class="form-group row mb-3">
                        <label class="col-md-3 col-form-label font-weight-bold text-dark" for="grad_member_type">
                            Ubah ke Tipe Anggota
                        </label>
                        <div class="col-md-6">
                            <select name="grad_member_type" id="grad_member_type" class="form-control">
                                <?php if ($alumni_type_id === null): ?>
                                    <option value="create_alumni" selected>+ Buat Otomatis Tipe Baru: "Alumni" (Direkomendasikan)</option>
                                <?php endif; ?>
                                <?php foreach ($member_types as $mt): ?>
                                    <option value="<?= (int)$mt['member_type_id']; ?>" <?= ($alumni_type_id !== null && (int)$mt['member_type_id'] === $alumni_type_id) ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($mt['member_type_name'], ENT_QUOTES, 'UTF-8'); ?> <?= ($alumni_type_id !== null && (int)$mt['member_type_id'] === $alumni_type_id) ? '(Direkomendasikan)' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="keep">-- Pertahankan Tipe Lama --</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group row mb-3">
                        <label class="col-md-3 col-form-label font-weight-bold text-dark">
                            Status Akun
                        </label>
                        <div class="col-md-6">
                            <div class="custom-control custom-radio mb-2">
                                <input type="radio" id="grad_status_pending" name="grad_status" class="custom-control-input" value="pending" checked>
                                <label class="custom-control-label" for="grad_status_pending">Set Nonaktif / Pending (Anggota tidak dapat meminjam buku lagi)</label>
                            </div>
                            <div class="custom-control custom-radio">
                                <input type="radio" id="grad_status_keep" name="grad_status" class="custom-control-input" value="keep">
                                <label class="custom-control-label" for="grad_status_keep">Pertahankan Status Saat Ini</label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group row mb-3">
                        <label class="col-md-3 col-form-label font-weight-bold text-dark" for="grad_expire_mode">
                            Masa Berlaku Kartu
                        </label>
                        <div class="col-md-6">
                            <select name="grad_expire_mode" id="grad_expire_mode" class="form-control" onchange="onGradExpireModeChange(this.value)">
                                <option value="today" selected>Kadaluarsakan Hari Ini (<?= date('d M Y'); ?>)</option>
                                <option value="keep">Pertahankan Tanggal Saat Ini</option>
                                <option value="custom">Tentukan Tanggal Lain...</option>
                            </select>
                            <div id="grad_custom_expire_container" class="mt-2" style="display: none;">
                                <input type="date" name="grad_custom_expire" id="grad_custom_expire" class="form-control" value="<?= date('Y-m-d'); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION MODE 3: PERPANJANG SAJA (RENEW) -->
                <div id="section_renew" style="display: none;">
                    <div class="form-group row mb-3">
                        <label class="col-md-3 col-form-label font-weight-bold text-dark" for="renew_mode">
                            Opsi Perpanjangan Masa Aktif
                        </label>
                        <div class="col-md-6">
                            <select name="renew_mode" id="renew_mode" class="form-control" onchange="onRenewModeChange(this.value)">
                                <option value="add_year" selected>Tambah 1 Tahun (+12 Bulan dari masa aktif saat ini)</option>
                                <option value="custom">Tetapkan Tanggal Kadaluarsa Baru...</option>
                            </select>
                            <div id="renew_custom_container" class="mt-2" style="display: none;">
                                <input type="date" name="renew_custom_date" id="renew_custom_date" class="form-control" value="<?= date('Y-m-d', strtotime('+1 year')); ?>">
                            </div>
                            <small class="form-text text-muted">Nama kelas dan data lainnya tidak akan diubah.</small>
                        </div>
                    </div>
                </div>

                <hr class="my-4">

                <!-- SUBMIT BUTTON -->
                <div class="form-group row">
                    <div class="col-md-9 offset-md-3">
                        <button type="submit" name="execute_rollover" id="btnSubmitRollover" class="btn btn-primary px-4 py-2 font-weight-bold shadow-sm">
                            <span class="mr-1">⚡</span> Eksekusi Perubahan Massal
                        </button>
                        <button type="button" class="btn btn-outline-secondary ml-2 py-2" onclick="resetRolloverForm()">
                            Reset Form
                        </button>
                    </div>
                </div>

            </form>
        </div>
    </div>
</div>

<!-- CLIENT JAVASCRIPT LOGIC -->
<script type="text/javascript">
(function() {
    var classesByType = <?= json_encode($classes_by_type, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    var ajaxPreviewUrl = <?= json_encode($form_action . '&ajax_preview=1'); ?>;

    window.onActionTypeChange = function(mode) {
        document.querySelectorAll('.mode-card').forEach(function(card) {
            card.classList.remove('active', 'border-primary', 'bg-light');
        });
        
        var secPromote = document.getElementById('section_promote');
        var secGraduate = document.getElementById('section_graduate');
        var secRenew = document.getElementById('section_renew');
        var targetClassInput = document.getElementById('target_class');

        if (mode === 'promote') {
            document.querySelector('label[for="act_promote"]').classList.add('active', 'border-primary', 'bg-light');
            secPromote.style.display = 'block';
            secGraduate.style.display = 'none';
            secRenew.style.display = 'none';
            targetClassInput.required = true;
        } else if (mode === 'graduate') {
            document.querySelector('label[for="act_graduate"]').classList.add('active', 'border-primary', 'bg-light');
            secPromote.style.display = 'none';
            secGraduate.style.display = 'block';
            secRenew.style.display = 'none';
            targetClassInput.required = false;
        } else if (mode === 'renew') {
            document.querySelector('label[for="act_renew"]').classList.add('active', 'border-primary', 'bg-light');
            secPromote.style.display = 'none';
            secGraduate.style.display = 'none';
            secRenew.style.display = 'block';
            targetClassInput.required = false;
        }
    };

    window.onMemberTypeFilterChange = function() {
        var mtype = document.getElementById('filter_member_type').value;
        var sourceSelect = document.getElementById('source_class');
        var list = classesByType[mtype] || [];

        sourceSelect.textContent = '';
        var defaultOpt = document.createElement('option');
        defaultOpt.value = '';
        defaultOpt.textContent = '-- Pilih Kelas yang Ingin Diperbarui --';
        sourceSelect.appendChild(defaultOpt);

        list.forEach(function(item) {
            var opt = document.createElement('option');
            opt.value = item.inst_name;
            opt.setAttribute('data-count', item.total);
            opt.textContent = item.inst_name + ' (' + item.total + ' anggota)';
            sourceSelect.appendChild(opt);
        });

        document.getElementById('member_preview_wrapper').style.display = 'none';
    };

    function setSummaryMessage(container, prefixText, badgeText, middleText, classText, suffixText) {
        container.textContent = '';
        container.appendChild(document.createTextNode(prefixText));
        if (badgeText) {
            var badge = document.createElement('strong');
            badge.className = 'badge badge-info px-2 py-1 mx-1';
            badge.style.fontSize = '0.9rem';
            badge.textContent = badgeText;
            container.appendChild(badge);
        }
        if (middleText) {
            container.appendChild(document.createTextNode(middleText));
        }
        if (classText) {
            var clsStrong = document.createElement('strong');
            clsStrong.textContent = classText;
            container.appendChild(clsStrong);
        }
        if (suffixText) {
            container.appendChild(document.createTextNode(suffixText));
        }
    }

    function renderEmptyPreviewRow(tbody, text) {
        tbody.textContent = '';
        var tr = document.createElement('tr');
        var td = document.createElement('td');
        td.colSpan = 4;
        td.className = 'text-center py-2 text-muted';
        td.textContent = text;
        tr.appendChild(td);
        tbody.appendChild(tr);
    }

    window.onSourceClassChange = function() {
        var select = document.getElementById('source_class');
        var selectedVal = select.value;
        var previewWrap = document.getElementById('member_preview_wrapper');
        var summaryText = document.getElementById('preview_summary_text');
        var tableBody = document.getElementById('preview_table_body');
        var mtype = document.getElementById('filter_member_type').value;

        if (!selectedVal) {
            previewWrap.style.display = 'none';
            return;
        }

        previewWrap.style.display = 'flex';
        summaryText.textContent = 'Memuat rincian anggota kelas ' + selectedVal + '...';
        renderEmptyPreviewRow(tableBody, 'Memuat data...');

        var xhr = new XMLHttpRequest();
        xhr.open('GET', ajaxPreviewUrl + '&class=' + encodeURIComponent(selectedVal) + '&mtype=' + encodeURIComponent(mtype));
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.status) {
                        setSummaryMessage(
                            summaryText,
                            'Ditemukan ',
                            res.total + ' Anggota',
                            ' pada kelas ',
                            selectedVal,
                            ' yang akan diproses.'
                        );

                        tableBody.textContent = '';
                        if (res.members && res.members.length > 0) {
                            res.members.forEach(function(m) {
                                var tr = document.createElement('tr');

                                var tdId = document.createElement('td');
                                var codeEl = document.createElement('code');
                                codeEl.textContent = m.member_id || '-';
                                tdId.appendChild(codeEl);

                                var tdName = document.createElement('td');
                                tdName.textContent = m.member_name || '-';

                                var tdExp = document.createElement('td');
                                tdExp.textContent = m.expire_date || '-';

                                var tdStatus = document.createElement('td');
                                var badge = document.createElement('span');
                                if (Number(m.is_pending) === 1) {
                                    badge.className = 'badge badge-warning';
                                    badge.textContent = 'Nonaktif';
                                } else {
                                    badge.className = 'badge badge-success';
                                    badge.textContent = 'Aktif';
                                }
                                tdStatus.appendChild(badge);

                                tr.appendChild(tdId);
                                tr.appendChild(tdName);
                                tr.appendChild(tdExp);
                                tr.appendChild(tdStatus);
                                tableBody.appendChild(tr);
                            });

                            if (res.total > res.members.length) {
                                var trMore = document.createElement('tr');
                                var tdMore = document.createElement('td');
                                tdMore.colSpan = 4;
                                tdMore.className = 'text-center text-muted small py-1 font-italic';
                                tdMore.textContent = 'Dan ' + (res.total - res.members.length) + ' anggota lainnya...';
                                trMore.appendChild(tdMore);
                                tableBody.appendChild(trMore);
                            }
                        } else {
                            renderEmptyPreviewRow(tableBody, 'Tidak ada anggota ditemukan pada kelas ini.');
                        }
                    }
                } catch(e) {
                    summaryText.textContent = 'Kelas ' + selectedVal + ' dipilih.';
                }
            }
        };
        xhr.send();
    };

    window.togglePreviewTable = function() {
        var container = document.getElementById('preview_table_container');
        var btn = document.getElementById('btnTogglePreview');
        if (container.style.display === 'none') {
            container.style.display = 'block';
            btn.textContent = 'Sembunyikan Rincian';
        } else {
            container.style.display = 'none';
            btn.textContent = 'Lihat Rincian Anggota';
        }
    };

    window.onExpireModeChange = function(val) {
        document.getElementById('custom_expire_container').style.display = (val === 'custom') ? 'block' : 'none';
    };

    window.onGradClassActionChange = function(val) {
        var container = document.getElementById('grad_target_class_container');
        var input = document.getElementById('grad_target_class');
        container.style.display = (val === 'custom') ? 'block' : 'none';
        if (input) {
            input.required = (val === 'custom');
        }
    };

    window.onGradExpireModeChange = function(val) {
        document.getElementById('grad_custom_expire_container').style.display = (val === 'custom') ? 'block' : 'none';
    };

    window.onRenewModeChange = function(val) {
        document.getElementById('renew_custom_container').style.display = (val === 'custom') ? 'block' : 'none';
    };

    window.resetRolloverForm = function() {
        var form = document.getElementById('rolloverForm');
        if (!form) return;
        form.reset();
        window.onActionTypeChange('promote');
        window.onMemberTypeFilterChange();
        window.onExpireModeChange('add_year');
        window.onGradClassActionChange('prefix');
        window.onGradExpireModeChange('today');
        window.onRenewModeChange('add_year');
        document.getElementById('member_preview_wrapper').style.display = 'none';
    };

    // AJAX Form Submission within SLiMS Admin MainContent
    var form = document.getElementById('rolloverForm');
    if (form && window.jQuery) {
        jQuery(form).on('submit', function(e) {
            e.preventDefault();
            var $form = jQuery(this);
            var mode = $form.find('input[name="action_type"]:checked').val();
            var source = ($form.find('#source_class').val() || '').trim();
            var target = ($form.find('#target_class').val() || '').trim();

            if (!source) {
                alert('Silakan pilih kelas asal terlebih dahulu!');
                return false;
            }

            var confirmMsg = 'Konfirmasi Eksekusi Massal:\n\n';
            if (mode === 'promote') {
                if (!target) {
                    alert('Silakan masukkan nama kelas tujuan baru!');
                    return false;
                }
                if (source.toLowerCase() === target.toLowerCase()) {
                    alert('Nama kelas tujuan tidak boleh sama dengan kelas asal!');
                    return false;
                }
                confirmMsg += '• Aksi: Naik Kelas\n• Kelas Asal: ' + source + '\n• Kelas Tujuan: ' + target + '\n\nLanjutkan pembaruan seluruh anggota ini?';
            } else if (mode === 'graduate') {
                if ($form.find('#grad_class_action').val() === 'custom' && !($form.find('#grad_target_class').val() || '').trim()) {
                    alert('Silakan isi nama kelas/label alumni kustom terlebih dahulu!');
                    return false;
                }
                confirmMsg += '• Aksi: Kelulusan / Arsip Alumni\n• Kelas Asal: ' + source + '\n• Status: Anggota akan diarsipkan sesuai opsi yang dipilih.\n\nLanjutkan proses kelulusan ini?';
            } else {
                confirmMsg += '• Aksi: Perpanjang Masa Aktif Kartu\n• Kelas: ' + source + '\n\nLanjutkan perpanjangan masa berlaku ini?';
            }

            if (!confirm(confirmMsg)) {
                return false;
            }

            var $btn = jQuery('#btnSubmitRollover');
            var origBtnHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span>Memproses Pembaruan...');

            jQuery.ajax({
                url: $form.attr('action'),
                type: 'POST',
                data: $form.serialize() + '&execute_rollover=1',
                success: function(resp) {
                    if (jQuery('#mainContent').length) {
                        jQuery('#mainContent').html(resp);
                    } else {
                        jQuery('#rolloverContentArea').replaceWith(jQuery(resp).find('#rolloverContentArea'));
                    }

                    if (window.toastr) {
                        if (jQuery(resp).find('.alert-success').length) {
                            toastr.success('Operasi rollover kelas massal berhasil dijalankan.');
                        } else if (jQuery(resp).find('.alert-info').length) {
                            toastr.info('Data kelulusan anggota berhasil diproses.');
                        }
                    }
                    window.scrollTo({top: 0, behavior: 'smooth'});
                },
                error: function(xhr, status, error) {
                    $btn.prop('disabled', false).html(origBtnHtml);
                    alert('Terjadi kesalahan saat memproses data: ' + error);
                }
            });
        });
    }

})();
</script>
