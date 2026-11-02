<?php
/**
 * Plugin Name: MeSH Subject Manager
 * Description: MeSH 2026 + bundled NLM 2026 installer + recommendation, integrated with Add/Edit Bibliography via SLiMS hook; no core modifications.
 * Version: 1.9.3
 * Author: Erwan Setyo Budi
 * Author URI: https://github.com/erwansetyobudi/
 * Plugin URI: https://github.com/erwansetyobudi/mesh_subject_manager
 */
use SLiMS\Plugins;
$meshPlugins = Plugins::getInstance();
$meshPlugins->registerMenu('bibliography', 'MeSH Subject Manager', __DIR__ . '/index.php');
$meshPlugins->register(Plugins::BIBLIOGRAPHY_INIT, function () {
    if (!isset($_POST['detail']) && !(isset($_GET['action']) && $_GET['action'] === 'detail') && !isset($_GET['addData'])) return;
    include __DIR__ . '/mesh_biblio_button.inc.php';
});
