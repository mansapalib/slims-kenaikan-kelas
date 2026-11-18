<?php
/**
 * Plugin Name: Mass Class Rollover & Member Promotion
 * Plugin URI: https://github.com/mansapalib/slims-kenaikan-kelas
 * Description: Alat bantu batch update kenaikan kelas, perpanjangan masa aktif, dan arsip kelulusan siswa.
 * Version: 1.1.0
 * Author: MANSAPA Iqra Labs
 */

defined('INDEX_AUTH') OR die('Akses langsung tidak diizinkan.');

$plugin = \SLiMS\Plugins::getInstance();

// Daftarkan sub-menu baru di modul Membership (Keanggotaan)
$plugin->registerMenu('membership', 'Rollover Kelas Massal', __DIR__ . '/index.php');