<?php
/** Optional read-only NLM recommendations; no changes to SLiMS core. */
function msm_nlm_available($dbs) {
 static $exists=null;
 if ($exists===null) {
  $r=$dbs->query("SHOW TABLES LIKE 'nlm_mapping'");
  $exists=($r && $r->num_rows>0);
 }
 return $exists;
}
function msm_nlm_by_mesh($dbs,$meshId) {
 if (!msm_nlm_available($dbs) || !$meshId) return [];
 $stmt=$dbs->prepare('SELECT number,term,source_url,match_method FROM nlm_mapping WHERE mesh_id=? ORDER BY number LIMIT 12');
 if (!$stmt) return [];
 $stmt->bind_param('s',$meshId);$stmt->execute();$res=$stmt->get_result();$rows=[];
 while($r=$res->fetch_assoc()) $rows[]=$r;
 $stmt->close();return $rows;
}
function msm_nlm_render($dbs,$meshId) {
 $rows=msm_nlm_by_mesh($dbs,$meshId);
 if (!$rows) return '';
 $out='<div class="msm-nlm-result" style="margin:7px 0;padding:9px;background:#eef6ff;border-left:3px solid #3478c0"><strong>Rekomendasi NLM:</strong> ';
 foreach($rows as $i=>$r){
  if($i) $out.='; ';
  $url=(string)$r['source_url'];
  $safe=(bool)preg_match('~^https://classification\.nlm\.nih\.gov/~i',$url);
  $num=htmlspecialchars($r['number'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  $out.=$safe?'<a target="_blank" rel="noopener noreferrer" href="'.htmlspecialchars($url,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'">'.$num.'</a>':$num;
 }
 return $out.'<div style="font-size:12px;color:#46556a">Pilih nomor setelah verifikasi NLM Schedules; bukan nomor panggil otomatis.</div></div>';
}
