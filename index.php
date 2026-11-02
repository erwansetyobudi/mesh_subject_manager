<?php
/** MeSH Subject Manager SLiMS 9.8 */
defined('INDEX_AUTH') OR die('No direct access');
if (isset($_GET['msm_tree']) && $_GET['msm_tree'] === '1') {
    require __DIR__ . '/mesh_tree_api.php';
    return;
}
// Picker is dispatched through SLiMS plugin_container, never served as a raw plugin PHP file.
if (isset($_GET['msm_picker']) && $_GET['msm_picker'] === '1') {
    require __DIR__ . '/mesh_picker.php';
    return;
}

require_once SB.'admin/default/session.inc.php';
require_once __DIR__.'/schema.php';
require_once __DIR__.'/installer.php';
require_once __DIR__.'/nlm_helper.php';
require_once __DIR__.'/nlm_installer.php';
if (!utility::havePrivilege('bibliography','r')) die('<div class="errorBox">Akses bibliografi ditolak.</div>');
function msm_h($s) { return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function msm_route($params=[]) { return $_SERVER['PHP_SELF'].'?'.http_build_query(array_merge(['mod'=>$_GET['mod']??$_POST['mod']??'bibliography','id'=>$_GET['id']??$_POST['id']??''], $params)); }
$error=''; $success='';
try { msm_schema($dbs); } catch(Throwable $e) { $error='Gagal menyiapkan tabel: '.$e->getMessage(); }
$canWrite=utility::havePrivilege('bibliography','w');
$install=null;
if (!$error) {
 try {
  $install=msm_install_state($dbs);
  if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['msm_install_batch'])) {
   if (!$canWrite || (int)($_SESSION['uid']??0)!==1) throw new RuntimeException('Instalasi dataset hanya untuk administrator utama.');
   if (empty($_SESSION['msm_csrf']) || !hash_equals($_SESSION['msm_csrf'], (string)($_POST['csrf']??''))) throw new RuntimeException('Token instalasi tidak valid.');
   if (!$install['complete']) msm_install_next($dbs);
   $install=msm_install_state($dbs);
  }
 } catch(Throwable $e) {$error='Instalasi: '.$e->getMessage();}
}

// NLM bundled installer: same session CSRF and primary administrator requirement.
$nlmInstall=null;
if (!$error) {
 try {
  $nlmInstall=msm_nlm_install_state($dbs);
  if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['msm_nlm_install_batch'])) {
   if (!$canWrite || (int)($_SESSION['uid']??0)!==1) throw new RuntimeException('Instalasi NLM hanya untuk administrator utama.');
   if (empty($_SESSION['msm_csrf']) || !hash_equals($_SESSION['msm_csrf'],(string)($_POST['csrf']??''))) throw new RuntimeException('Token instalasi NLM tidak valid.');
   if (!$nlmInstall['complete']) msm_nlm_install_next($dbs);
   $nlmInstall=msm_nlm_install_state($dbs);
  }
 } catch (Throwable $e) { $error='Instalasi NLM: '.$e->getMessage(); }
}

// Mutasi hanya melalui POST + CSRF + hak tulis. Semua aksi menjaga mod/id.
if (!$error && $_SERVER['REQUEST_METHOD']==='POST' && (isset($_POST['attach_mesh']) || isset($_POST['attach_mesh_multi']) || isset($_POST['detach_mesh']))) {
 if (!$canWrite) $error='Tidak memiliki izin mengubah bibliografi.';
 elseif (empty($_SESSION['msm_csrf']) || !hash_equals($_SESSION['msm_csrf'],(string)($_POST['csrf']??''))) $error='Token keamanan tidak cocok. Muat ulang halaman.';
 else {
  $bid=(int)($_POST['biblio_id']??0);
  try {
   if ($bid<1) throw new RuntimeException('ID bibliografi tidak valid.');
   $st=$dbs->prepare('SELECT title FROM biblio WHERE biblio_id=?'); $st->bind_param('i',$bid); $st->execute(); $b=$st->get_result()->fetch_assoc(); $st->close();
   if (!$b) throw new RuntimeException('Bibliografi tidak ditemukan.');
   $dbs->begin_transaction();
   if (isset($_POST['detach_mesh'])) {
    $mesh=(string)($_POST['mesh_id']??'');
    if (!preg_match('/^D[0-9]{6,}$/',$mesh)) throw new RuntimeException('Kode MeSH tidak valid.');
    $st=$dbs->prepare('SELECT topic_id FROM mesh_biblio_map WHERE biblio_id=? AND mesh_id=?'); $st->bind_param('is',$bid,$mesh); $st->execute(); $mapped=$st->get_result()->fetch_assoc(); $st->close();
    if (!$mapped) throw new RuntimeException('Relasi MeSH tidak ditemukan.');
    $tid=(int)$mapped['topic_id'];
    $st=$dbs->prepare('DELETE FROM mesh_biblio_map WHERE biblio_id=? AND mesh_id=?'); $st->bind_param('is',$bid,$mesh); if (!$st->execute()) throw new RuntimeException($st->error); $st->close();
    // Jangan hapus biblio_topic bila masih digunakan oleh pemetaan MeSH lain.
    $st=$dbs->prepare('SELECT COUNT(*) AS n FROM mesh_biblio_map WHERE biblio_id=? AND topic_id=?'); $st->bind_param('ii',$bid,$tid); $st->execute(); $remain=(int)$st->get_result()->fetch_assoc()['n']; $st->close();
    if (!$remain) { $st=$dbs->prepare('DELETE FROM biblio_topic WHERE biblio_id=? AND topic_id=?'); $st->bind_param('ii',$bid,$tid); if (!$st->execute()) throw new RuntimeException($st->error); $st->close(); }
    $success='Relasi MeSH dilepas dari bibliografi #'.$bid.'. Kamus dan mst_topic tidak dihapus.';
   } else {
    $ids=isset($_POST['attach_mesh_multi']) ? ($_POST['mesh_ids']??[]) : [($_POST['mesh_id']??'')];
    if (!is_array($ids)) throw new RuntimeException('Pilihan MeSH tidak valid.');
    $ids=array_values(array_unique(array_map('strval',$ids)));
    if (!$ids || count($ids)>60) throw new RuntimeException('Pilih 1 sampai 60 descriptor.');
    $added=0;
    foreach ($ids as $mesh) {
     if (!preg_match('/^D[0-9]{6,}$/',$mesh)) throw new RuntimeException('Kode MeSH tidak valid.');
     $st=$dbs->prepare('SELECT descriptor_name FROM mesh_descriptors WHERE mesh_id=?'); $st->bind_param('s',$mesh); $st->execute(); $d=$st->get_result()->fetch_assoc(); $st->close();
     if (!$d) throw new RuntimeException('Descriptor MeSH tidak ditemukan: '.$mesh);
     $name=$d['descriptor_name'];
     $st=$dbs->prepare('SELECT topic_id FROM mst_topic WHERE topic=? ORDER BY topic_id ASC LIMIT 1'); $st->bind_param('s',$name); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close();
     if ($row) $tid=(int)$row['topic_id'];
     else { $type='t'; $st=$dbs->prepare('INSERT INTO mst_topic (topic,topic_type) VALUES (?,?)'); $st->bind_param('ss',$name,$type); if (!$st->execute()) throw new RuntimeException($st->error); $tid=(int)$dbs->insert_id; $st->close(); }
     $st=$dbs->prepare('SELECT 1 FROM biblio_topic WHERE biblio_id=? AND topic_id=? LIMIT 1'); $st->bind_param('ii',$bid,$tid); $st->execute(); $exists=$st->get_result()->fetch_row(); $st->close();
     if (!$exists) { $level=1; $st=$dbs->prepare('INSERT INTO biblio_topic (biblio_id,topic_id,level) VALUES (?,?,?)'); $st->bind_param('iii',$bid,$tid,$level); if (!$st->execute()) throw new RuntimeException($st->error); $st->close(); }
     $st=$dbs->prepare('INSERT INTO mesh_biblio_map (biblio_id,mesh_id,topic_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE topic_id=VALUES(topic_id)'); $st->bind_param('isi',$bid,$mesh,$tid); if (!$st->execute()) throw new RuntimeException($st->error); $st->close();
     $added++;
    }
    $success=$added.' descriptor MeSH berhasil diproses untuk bibliografi #'.$bid.'.';
   }
   $dbs->commit();
  } catch(Throwable $e) { $dbs->rollback(); $error=$e->getMessage(); }
 }
}
if (empty($_SESSION['msm_csrf'])) $_SESSION['msm_csrf']=bin2hex(random_bytes(24));
$q=trim((string)($_POST['mesh_q']??$_GET['mesh_q']??'')); $bid=max(0,(int)($_POST['biblio_id']??$_GET['biblio_id']??0));
$count=0; $logs=[]; $results=[]; $biblioResults=[];
$bq=trim((string)($_POST['biblio_q']??$_GET['biblio_q']??''));
if ($bq!=='' && mb_strlen($bq)<=120 && !$error) {
 $blike='%'.$bq.'%';
 $st=$dbs->prepare('SELECT biblio_id,title FROM biblio WHERE title LIKE ? ORDER BY last_update DESC LIMIT 25');
 $st->bind_param('s',$blike); $st->execute(); $rr=$st->get_result();
 while($r=$rr->fetch_assoc()) $biblioResults[]=$r;
 $st->close();
}

$activeBiblio=null; $assignedTopics=[]; $suggested=[];
if ($bid && !$error) {
 $st=$dbs->prepare('SELECT biblio_id,title FROM biblio WHERE biblio_id=?'); $st->bind_param('i',$bid); $st->execute(); $activeBiblio=$st->get_result()->fetch_assoc(); $st->close();
 if ($activeBiblio) {
  $st=$dbs->prepare('SELECT t.topic_id,t.topic,m.mesh_id FROM biblio_topic bt JOIN mst_topic t ON t.topic_id=bt.topic_id LEFT JOIN mesh_biblio_map m ON m.biblio_id=bt.biblio_id AND m.topic_id=bt.topic_id WHERE bt.biblio_id=? ORDER BY t.topic');
  $st->bind_param('i',$bid); $st->execute(); $rr=$st->get_result(); while($row=$rr->fetch_assoc()) $assignedTopics[]=$row; $st->close();
  // Rekomendasi berbasis kata judul; bukan rekomendasi AI atau pemetaan bahasa otomatis.
  $words=preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower($activeBiblio['title']));
  $words=array_values(array_unique(array_filter($words,static function($w){return mb_strlen($w)>=5;})));
  usort($words,static function($a,$b){return mb_strlen($b)-mb_strlen($a);});
  $words=array_slice($words,0,6);
  if ($words) {
   $conditions=[]; $params=[]; $types='';
   foreach($words as $w){$conditions[]='descriptor_name LIKE ?';$params[]='%'.$w.'%';$types.='s';}
   $st=$dbs->prepare('SELECT mesh_id,descriptor_name FROM mesh_descriptors WHERE '.implode(' OR ',$conditions).' ORDER BY descriptor_name LIMIT 12');
   $st->bind_param($types,...$params);$st->execute();$rr=$st->get_result();while($row=$rr->fetch_assoc())$suggested[]=$row;$st->close();
  }
 }
}
if (!$error) {
 $r=$dbs->query('SELECT COUNT(*) AS n FROM mesh_descriptors'); if($r) $count=(int)$r->fetch_assoc()['n'];
 $r=$dbs->query('SELECT dataset_year,records_count,imported_at FROM mesh_import_logs ORDER BY id DESC LIMIT 3'); if($r) while($x=$r->fetch_assoc()) $logs[]=$x;
 if ($q!=='' && mb_strlen($q)<=120) {
  $like='%'.$q.'%';
  $st=$dbs->prepare('SELECT d.mesh_id,d.descriptor_name,d.scope_note FROM mesh_descriptors d WHERE d.descriptor_name LIKE ? OR EXISTS (SELECT 1 FROM mesh_terms t WHERE t.mesh_id=d.mesh_id AND t.term LIKE ?) ORDER BY CASE WHEN d.descriptor_name=? THEN 0 WHEN d.descriptor_name LIKE ? THEN 1 ELSE 2 END,d.descriptor_name LIMIT 60');
  $prefix=$q.'%'; $st->bind_param('ssss',$like,$like,$q,$prefix); $st->execute(); $res=$st->get_result(); while($row=$res->fetch_assoc()) $results[]=$row; $st->close();
 }
}
?>
<div id="msm-root"><div class="menuBox"><div class="menuBoxInner"><div class="per_title"><h2>MeSH Subject Manager</h2></div><div class="infoBox">Kamus MeSH lokal — <?php echo number_format($count,0,',','.'); ?> descriptor tersedia.</div></div></div>
<div style="padding:15px;max-width:1100px">
<?php if($error): ?><div class="alert alert-danger"><?php echo msm_h($error); ?></div><?php endif; ?>
<?php if($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if ($install && !$install['complete']): ?>
<div class="alert alert-info"><b>Instalasi otomatis MeSH 2026</b> — batch <?php echo (int)$install['next']; ?> dari <?php echo (int)$install['total']; ?> (<?php echo round(100*$install['next']/max(1,$install['total']),1); ?>%). Jangan tutup tab selama instalasi.</div>
<?php if($canWrite && (int)($_SESSION['uid']??0)===1): ?>
<form method="post" action="<?php echo msm_h(msm_route()); ?>" data-msm-install="1">
<input type="hidden" name="id" value="<?php echo msm_h($_GET['id']??$_POST['id']??''); ?>"/>
<input type="hidden" name="mod" value="<?php echo msm_h($_GET['mod']??$_POST['mod']??'bibliography'); ?>"/>
<input type="hidden" name="csrf" value="<?php echo msm_h($_SESSION['msm_csrf']); ?>"/>
<button type="submit" name="msm_install_batch" value="1" class="btn btn-primary btn-sm">Mulai / lanjutkan instalasi otomatis</button>
</form>
<?php else: ?><div class="alert alert-warning">Administrator utama perlu membuka plugin ini untuk memasang dataset.</div><?php endif; ?>
<?php endif; ?>
<?php if($nlmInstall): ?>
<div class="alert alert-<?php echo $nlmInstall['complete']?'success':'info'; ?>" style="margin:12px 0">
<b>Dataset NLM Classification 2026</b> — <?php echo $nlmInstall['complete']?'Terpasang':'Instalasi otomatis'; ?>.
<?php foreach($nlmInstall['expected'] as $t=>$n): ?>
<span style="margin-right:10px"><?php echo msm_h($t); ?>: <?php echo number_format($nlmInstall['actual'][$t],0,',','.'); ?>/<?php echo number_format($n,0,',','.'); ?></span>
<?php endforeach; ?>
<?php if(!$nlmInstall['complete']): ?>
<div>Batch <?php echo (int)$nlmInstall['next']; ?> dari <?php echo (int)$nlmInstall['total']; ?>. Jangan tutup tab selama instalasi.</div>
<?php if($canWrite && (int)($_SESSION['uid']??0)===1): ?>
<form method="post" action="<?php echo msm_h(msm_route()); ?>" data-msm-install="1">
<input type="hidden" name="id" value="<?php echo msm_h($_GET['id']??$_POST['id']??''); ?>"/>
<input type="hidden" name="mod" value="<?php echo msm_h($_GET['mod']??$_POST['mod']??'bibliography'); ?>"/>
<input type="hidden" name="csrf" value="<?php echo msm_h($_SESSION['msm_csrf']); ?>"/>
<button type="submit" name="msm_nlm_install_batch" value="1" class="btn btn-primary btn-sm">Mulai / lanjutkan instalasi NLM</button>
</form>
<?php else: ?>Hanya administrator utama dapat memasang dataset NLM.<?php endif; ?>
<?php endif; ?>
</div>
<?php endif; ?>
<form method="get" action="<?php echo msm_h(msm_route()); ?>" class="mb-3" style="margin:14px 0">
<input type="hidden" name="id" value="<?php echo msm_h($_GET['id']??$_POST['id']??''); ?>"/>
<input type="hidden" name="mod" value="<?php echo msm_h($_GET['mod']??$_POST['mod']??'bibliography'); ?>"/>
<label>Cari bibliografi berdasarkan judul</label>
<div style="display:flex;gap:8px"><input class="form-control" name="biblio_q" value="<?php echo msm_h($bq); ?>" placeholder="Judul buku / karya ilmiah" maxlength="120"><button class="btn btn-secondary" type="submit">Cari Bibliografi</button></div>
</form>
<?php foreach($biblioResults as $br): ?><div style="padding:7px 10px;border-bottom:1px solid #ddd"><b><?php echo msm_h($br['title']); ?></b> <small>#<?php echo (int)$br['biblio_id']; ?></small>
<form method="get" action="<?php echo msm_h(msm_route()); ?>" style="display:inline">
<input type="hidden" name="id" value="<?php echo msm_h($_GET['id']??$_POST['id']??''); ?>"/><input type="hidden" name="mod" value="<?php echo msm_h($_GET['mod']??$_POST['mod']??'bibliography'); ?>"/>
<input type="hidden" name="biblio_id" value="<?php echo (int)$br['biblio_id']; ?>"/><input type="hidden" name="mesh_q" value="<?php echo msm_h($q); ?>"/>
<button class="btn btn-sm btn-outline-primary" type="submit">Pilih bibliografi</button></form></div><?php endforeach; ?>
<?php if($bid): ?><div class="alert alert-info"><b>Bibliografi aktif: #<?php echo $bid; ?></b><?php if($activeBiblio): ?> — <strong><?php echo msm_h($activeBiblio['title']); ?></strong><?php else: ?> — <span class="text-danger">Judul tidak ditemukan; periksa ID bibliografi.</span><?php endif; ?></div><?php endif; ?>
<?php if($activeBiblio): ?>
<h4>Subjek terpasang (<?php echo count($assignedTopics); ?>)</h4>
<?php if(!$assignedTopics): ?><p>Belum ada subjek pada bibliografi ini.</p><?php endif; ?>
<?php foreach($assignedTopics as $at): ?><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:7px;border-bottom:1px solid #eee"><strong><?php echo msm_h($at['topic']); ?></strong><small><?php echo $at['mesh_id'] ? 'MeSH '.msm_h($at['mesh_id']) : 'Subjek biasa'; ?></small>
<?php if($at['mesh_id']) echo msm_nlm_render($dbs,$at['mesh_id']); ?>
<?php if($at['mesh_id'] && $canWrite): ?><form method="post" action="<?php echo msm_h(msm_route(['biblio_id'=>$bid,'mesh_q'=>$q])); ?>" style="display:inline" onsubmit="return confirm('Lepas relasi MeSH dari bibliografi ini?');"><input type="hidden" name="mod" value="<?php echo msm_h($_GET['mod']??$_POST['mod']??'bibliography'); ?>"><input type="hidden" name="id" value="<?php echo msm_h($_GET['id']??$_POST['id']??''); ?>"><input type="hidden" name="csrf" value="<?php echo msm_h($_SESSION['msm_csrf']); ?>"><input type="hidden" name="biblio_id" value="<?php echo $bid; ?>"><input type="hidden" name="mesh_id" value="<?php echo msm_h($at['mesh_id']); ?>"><button class="btn btn-sm btn-outline-danger" name="detach_mesh" value="1">Lepas MeSH</button></form><?php endif; ?></div><?php endforeach; ?>
<?php if($suggested): ?><h4 style="margin-top:16px">Kandidat MeSH dari kata judul</h4><small>Hasil pencocokan kata, perlu validasi pustakawan.</small><div style="display:flex;flex-wrap:wrap;gap:6px;margin:8px 0"><?php foreach($suggested as $sg): ?><a class="btn btn-sm btn-outline-secondary msm-suggest" href="<?php echo msm_h(msm_route(['biblio_id'=>$bid,'mesh_q'=>$sg['descriptor_name']])); ?>"><?php echo msm_h($sg['descriptor_name']); ?></a><?php endforeach; ?></div><?php endif; ?>
<?php endif; ?>
<form method="get" action="<?php echo msm_h(msm_route()); ?>" class="mb-3" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
<input type="hidden" name="mod" value="<?php echo msm_h($_GET['mod']??$_POST['mod']??'bibliography'); ?>"><input type="hidden" name="id" value="<?php echo msm_h($_GET['id']??$_POST['id']??''); ?>">
<div><label>Cari descriptor / sinonim</label><input class="form-control" style="min-width:320px" name="mesh_q" value="<?php echo msm_h($q); ?>" placeholder="contoh: Hypertension" maxlength="120"></div>
<div><label>ID Bibliografi</label><input class="form-control" type="number" name="biblio_id" min="1" value="<?php echo $bid?:''; ?>" placeholder="biblio_id"></div>
<button class="btn btn-primary" type="submit">Cari MeSH</button></form>
<?php if($q!==''): ?><h4>Hasil pencarian: <?php echo msm_h($q); ?> (maksimal 60)</h4><?php endif; ?>
<?php if($results && $canWrite && $activeBiblio): ?><form id="msm-multi-form" method="post" action="<?php echo msm_h(msm_route(['mesh_q'=>$q,'biblio_id'=>$bid])); ?>"><input type="hidden" name="mod" value="<?php echo msm_h($_GET['mod']??$_POST['mod']??'bibliography'); ?>"><input type="hidden" name="id" value="<?php echo msm_h($_GET['id']??$_POST['id']??''); ?>"><input type="hidden" name="csrf" value="<?php echo msm_h($_SESSION['msm_csrf']); ?>"><input type="hidden" name="biblio_id" value="<?php echo $bid; ?>"><button type="submit" name="attach_mesh_multi" value="1" class="btn btn-success btn-sm">Simpan semua descriptor terpilih</button><small style="margin-left:8px">Centang satu atau beberapa descriptor di bawah.</small></form><?php endif; ?>
<?php foreach($results as $r): ?>
<div class="card mb-2" style="padding:12px;border:1px solid #ddd;border-radius:7px;margin-bottom:10px">
<?php if($canWrite && $activeBiblio): ?><label style="display:inline-flex;align-items:center;gap:6px;margin-right:8px"><input type="checkbox" form="msm-multi-form" name="mesh_ids[]" value="<?php echo msm_h($r['mesh_id']); ?>"> Pilih</label><?php endif; ?><strong><?php echo msm_h($r['descriptor_name']); ?></strong> <small><?php echo msm_h($r['mesh_id']); ?></small>
<p style="margin:6px 0"><?php echo msm_h(mb_strimwidth((string)$r['scope_note'],0,650,'…','UTF-8')); ?></p>
<?php $st=$dbs->prepare('SELECT term FROM mesh_terms WHERE mesh_id=? AND term<>? ORDER BY term LIMIT 12'); $st->bind_param('ss',$r['mesh_id'],$r['descriptor_name']); $st->execute(); $rr=$st->get_result(); $terms=[]; while($t=$rr->fetch_assoc()) $terms[]=$t['term']; $st->close(); ?>
<?php echo msm_nlm_render($dbs,$r['mesh_id']); ?>
<?php if($terms): ?><div><small><b>Sinonim:</b> <?php echo msm_h(implode('; ',$terms)); ?></small></div><?php endif; ?>
<?php $st=$dbs->prepare('SELECT tree_number FROM mesh_tree_numbers WHERE mesh_id=? ORDER BY tree_number LIMIT 12'); $st->bind_param('s',$r['mesh_id']); $st->execute(); $rr=$st->get_result(); $trees=[]; while($t=$rr->fetch_assoc()) $trees[]=$t['tree_number']; $st->close(); ?>
<?php if($trees): ?><div><small><b>Tree:</b> <?php echo msm_h(implode(', ',$trees)); ?></small></div><?php endif; ?>
<?php if($canWrite && $bid): ?><form method="post" action="<?php echo msm_h(msm_route(['mesh_q'=>$q,'biblio_id'=>$bid])); ?>" style="margin-top:8px">
<input type="hidden" name="mod" value="<?php echo msm_h($_GET['mod']??$_POST['mod']??'bibliography'); ?>"><input type="hidden" name="id" value="<?php echo msm_h($_GET['id']??$_POST['id']??''); ?>">
<input type="hidden" name="csrf" value="<?php echo msm_h($_SESSION['msm_csrf']); ?>"><input type="hidden" name="mesh_id" value="<?php echo msm_h($r['mesh_id']); ?>"><input type="hidden" name="biblio_id" value="<?php echo $bid; ?>">
<button class="btn btn-success btn-sm" type="submit" name="attach_mesh" value="1">Tambahkan ke Bibliografi #<?php echo $bid; ?></button></form><?php endif; ?>
</div><?php endforeach; ?>
<?php if($logs): ?><hr><h4>Riwayat impor</h4><?php foreach($logs as $log): ?><p>MeSH <?php echo (int)$log['dataset_year']; ?> — <?php echo number_format($log['records_count'],0,',','.'); ?> descriptor — <?php echo msm_h($log['imported_at']); ?></p><?php endforeach; ?><?php endif; ?>
</div></div>
<script>
/* Keep SLiMS admin chrome: intercept GET search and POST attach, refresh only plugin content. */
(function () {
  if (window.__msmSubmitBound) return;
  window.__msmSubmitBound = true;
  document.addEventListener('click', function(event) {
    var link=event.target.closest && event.target.closest('#msm-root a.msm-suggest');
    if(!link) return;
    event.preventDefault();
    fetch(link.href,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.text();}).then(function(html){var doc=new DOMParser().parseFromString(html,'text/html');var incoming=doc.querySelector('#msm-root');var current=document.querySelector('#msm-root');if(!incoming||!current)throw new Error('Respons plugin tidak valid');current.innerHTML=incoming.innerHTML;}).catch(function(e){alert('Pencarian gagal: '+e.message);});
  },true);
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form.closest || !form.closest('#msm-root')) return;
    event.preventDefault();
    var button = event.submitter || form.querySelector('button[type="submit"]');
    var data = new FormData(form);
    if (button && button.name) data.append(button.name, button.value);
    var method = (form.method || 'GET').toUpperCase();
    var url = new URL(form.action || window.location.href, window.location.href);
    var options = {method: method, credentials: 'same-origin', headers: {'X-Requested-With':'XMLHttpRequest'}};
    if (method === 'GET') {
      for (var pair of data.entries()) url.searchParams.set(pair[0], pair[1]);
    } else {
      options.body = data;
    }
    if (button) button.disabled = true;
    fetch(url.toString(), options).then(function (response) {
      if (!response.ok) throw new Error('HTTP ' + response.status);
      return response.text();
    }).then(function (html) {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      var incoming = doc.querySelector('#msm-root');
      var current = document.querySelector('#msm-root');
      if (!incoming || !current) throw new Error('Respons plugin tidak valid.');
      current.innerHTML = incoming.innerHTML;
      if (current.querySelector('form[data-msm-install]')) setTimeout(msmAutoInstall, 120);
    }).catch(function (err) {
      if (button) button.disabled = false;
      alert('Permintaan gagal: ' + err.message);
    });
  }, true);
  function msmAutoInstall() {
    var form=document.querySelector('#msm-root form[data-msm-install]');
    if (form && !form.querySelector('button').disabled) form.requestSubmit(form.querySelector('button'));
  }
  setTimeout(msmAutoInstall, 500);
})();
</script>
