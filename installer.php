<?php
/** Safe, resumable SQL-batch installer for the bundled MeSH dataset. */
function msm_install_manifest(): array {
    $raw = file_get_contents(__DIR__.'/manifest.json');
    $m = json_decode($raw, true);
    if (!is_array($m) || !isset($m['chunks'], $m['counts'])) throw new RuntimeException('Manifest dataset tidak valid.');
    return $m;
}
function msm_install_state(mysqli $dbs): array {
    $dbs->query("CREATE TABLE IF NOT EXISTS mesh_install_state (dataset_year SMALLINT NOT NULL PRIMARY KEY, next_chunk INT NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $m = msm_install_manifest();
    $r = $dbs->query('SELECT next_chunk FROM mesh_install_state WHERE dataset_year=2026');
    $row = $r->fetch_assoc();
    $next = $row ? (int)$row['next_chunk'] : 0;
    if ($next > 0) return ['complete'=>$next>=count($m['chunks']), 'next'=>$next, 'total'=>count($m['chunks']), 'actual'=>[]];
    $actual=[];
    foreach (['mesh_descriptors','mesh_terms','mesh_tree_numbers'] as $table) {
        $q=$dbs->query('SELECT COUNT(*) AS n FROM '.$table);
        $actual[$table]=(int)$q->fetch_assoc()['n'];
    }
    $complete=true;
    foreach ($actual as $table=>$n) if ($n < (int)$m['counts'][$table]) $complete=false;
    return ['complete'=>$complete, 'next'=>$next, 'total'=>count($m['chunks']), 'actual'=>$actual];
}
function msm_install_next(mysqli $dbs): array {
    $m=msm_install_manifest(); $total=count($m['chunks']);
    $dbs->begin_transaction();
    try {
        $dbs->query('INSERT IGNORE INTO mesh_install_state (dataset_year,next_chunk) VALUES (2026,0)');
        $q=$dbs->query('SELECT next_chunk FROM mesh_install_state WHERE dataset_year=2026 FOR UPDATE');
        $next=(int)$q->fetch_assoc()['next_chunk'];
        if ($next < $total) {
            $info=$m['chunks'][$next];
            if (!preg_match('~^data/[0-9]{4}_mesh_(descriptors|terms|tree_numbers)\.sql\.gz$~', $info['file'])) throw new RuntimeException('Nama batch tidak valid.');
            $file=__DIR__.'/'.$info['file'];
            $sql=gzdecode(file_get_contents($file));
            if ($sql===false || !str_starts_with($sql,'INSERT IGNORE INTO `'.$info['table'].'` ')) throw new RuntimeException('Batch SQL tidak valid.');
            if (!$dbs->query($sql)) throw new RuntimeException('SQL batch '.($next+1).': '.$dbs->error);
            $new=$next+1;
            if (!$dbs->query('UPDATE mesh_install_state SET next_chunk='.$new.' WHERE dataset_year=2026')) throw new RuntimeException($dbs->error);
        } else $new=$next;
        if ($new===$total && $next<$total) {
            $dbs->query('INSERT INTO mesh_import_logs (dataset_year,records_count) VALUES (2026,31110)');
        }
        $dbs->commit();
        return ['next'=>$new,'total'=>$total];
    } catch (Throwable $e) { $dbs->rollback(); throw $e; }
}
