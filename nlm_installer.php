<?php
/*
 * File: nlm_installer.php
 * Created on Mon Nov 02 2026
 * Last Updated: Mon Nov 02 2026 5:12:21 PM
 * Author: Erwan Setyo Budi
 * Email: erwans818@gmail.com
 * License: The GNU General Public License, Version 3 (GPL-3.0) - Copyright (C) 2026 Erwan Setyo Budi. This program is free software.
 */

/** Bundled NLM 2026 installer. Non-destructive, resumable, and admin-only via index.php. */
function msm_nlm_manifest(): array {
    $m=json_decode(file_get_contents(__DIR__.'/nlm_manifest.json'),true);
    if (!is_array($m) || !isset($m['chunks'],$m['counts'],$m['ddl'])) throw new RuntimeException('Manifest NLM tidak valid.');
    return $m;
}
function msm_nlm_schema(mysqli $dbs): void {
    $m=msm_nlm_manifest();
    foreach ($m['ddl'] as $ddl) {
        if (!$dbs->query($ddl)) throw new RuntimeException('Gagal membuat tabel NLM: '.$dbs->error);
    }
    if (!$dbs->query("CREATE TABLE IF NOT EXISTS msm_nlm_install_state (dataset_key VARCHAR(40) PRIMARY KEY, next_chunk INT NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) throw new RuntimeException($dbs->error);
}
function msm_nlm_install_state(mysqli $dbs): array {
    msm_nlm_schema($dbs);
    $m=msm_nlm_manifest(); $total=count($m['chunks']);
    $r=$dbs->query("SELECT next_chunk FROM msm_nlm_install_state WHERE dataset_key='nlm-2026-v05'");
    $row=$r?$r->fetch_assoc():null;
    $next=$row?(int)$row['next_chunk']:0;
    $actual=[]; $complete=true;
    foreach ($m['counts'] as $table=>$expected) {
        if (!preg_match('/^nlm_(entries|classes|references|pages|mapping)$/',$table)) throw new RuntimeException('Nama tabel tidak valid.');
        $q=$dbs->query('SELECT COUNT(*) AS n FROM `'.$table.'`');
        if (!$q) throw new RuntimeException($dbs->error);
        $actual[$table]=(int)$q->fetch_assoc()['n'];
        if ($actual[$table] < (int)$expected) $complete=false;
    }
    return ['complete'=>$complete,'next'=>$next,'total'=>$total,'actual'=>$actual,'expected'=>$m['counts']];
}
function msm_nlm_install_next(mysqli $dbs): array {
    msm_nlm_schema($dbs);
    $m=msm_nlm_manifest(); $total=count($m['chunks']);
    $dbs->begin_transaction();
    try {
        if (!$dbs->query("INSERT IGNORE INTO msm_nlm_install_state (dataset_key,next_chunk) VALUES ('nlm-2026-v05',0)")) throw new RuntimeException($dbs->error);
        $r=$dbs->query("SELECT next_chunk FROM msm_nlm_install_state WHERE dataset_key='nlm-2026-v05' FOR UPDATE");
        if (!$r) throw new RuntimeException($dbs->error);
        $next=(int)$r->fetch_assoc()['next_chunk'];
        if ($next < $total) {
            $info=$m['chunks'][$next];
            if (!preg_match('~^data/nlm/[0-9]{4}_nlm_(entries|classes|references|pages|mapping)\.sql\.gz$~',$info['file'])) throw new RuntimeException('Nama batch NLM tidak valid.');
            $path=__DIR__.'/'.$info['file'];
            if (!is_file($path)) throw new RuntimeException('Batch NLM tidak ditemukan.');
            $sql=gzdecode(file_get_contents($path));
            if ($sql===false || !hash_equals($info['sha256'],hash('sha256',$sql)) || !str_starts_with($sql,'INSERT IGNORE INTO `'.$info['table'].'` ')) throw new RuntimeException('Checksum atau SQL NLM tidak valid.');
            if (!$dbs->query($sql)) throw new RuntimeException('SQL batch NLM '.($next+1).': '.$dbs->error);
            $new=$next+1;
            if (!$dbs->query("UPDATE msm_nlm_install_state SET next_chunk=$new WHERE dataset_key='nlm-2026-v05'")) throw new RuntimeException($dbs->error);
        } else $new=$next;
        $dbs->commit();
        return ['next'=>$new,'total'=>$total];
    } catch (Throwable $e) { $dbs->rollback(); throw $e; }
}
