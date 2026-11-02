<?php
/** MeSH picker for SLiMS 9.8.0 Add/Edit Bibliography. */
defined('INDEX_AUTH') OR die('No direct access');
require_once SB.'admin/default/session.inc.php';
if (!utility::havePrivilege('bibliography','w')) die('Akses ditolak.');
require_once __DIR__.'/schema.php';
require_once __DIR__.'/nlm_helper.php';
function mp_h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$bid=max(0,(int)($_POST['biblioID']??$_GET['biblioID']??0));
$q=trim((string)($_POST['q']??$_GET['q']??''));
if (mb_strlen($q)>120) $q=mb_substr($q,0,120);
$error=''; $success=''; $results=[]; $title='';
if ($bid) {
 $st=$dbs->prepare('SELECT title FROM biblio WHERE biblio_id=?');$st->bind_param('i',$bid);$st->execute();$r=$st->get_result()->fetch_assoc();$st->close();
 if (!$r) die('Bibliografi tidak ditemukan.');$title=$r['title'];
}
if (empty($_SESSION['msm_picker_csrf'])) $_SESSION['msm_picker_csrf']=bin2hex(random_bytes(24));
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_mesh'])) {
 if (!hash_equals($_SESSION['msm_picker_csrf'],(string)($_POST['csrf']??''))) $error='Token tidak valid.';
 else {
  $ids=$_POST['mesh_ids']??[];
  if (!is_array($ids) || !$ids || count($ids)>60) $error='Pilih 1 sampai 60 descriptor.';
  else {
   $ids=array_unique(array_map('strval',$ids));
   try {
    $dbs->begin_transaction();$added=0;$savedNlm=[];
    foreach($ids as $mesh){
     if(!preg_match('/^D[0-9]{6,}$/',$mesh)) throw new RuntimeException('Kode descriptor tidak valid.');
     $st=$dbs->prepare('SELECT descriptor_name FROM mesh_descriptors WHERE mesh_id=?');$st->bind_param('s',$mesh);$st->execute();$r=$st->get_result()->fetch_assoc();$st->close();
     if(!$r) throw new RuntimeException('Descriptor tidak ditemukan.');
     $name=$r['descriptor_name'];$type='t';
     $nlmRows=msm_nlm_by_mesh($dbs,$mesh);
     $nlmNumber=isset($nlmRows[0]['number'])?trim((string)$nlmRows[0]['number']):'';
     if ($nlmNumber!=='' && mb_strlen($nlmNumber)>50) throw new RuntimeException('Nomor NLM terlalu panjang.');
     $st=$dbs->prepare('SELECT topic_id FROM mst_topic WHERE topic=? AND topic_type=? LIMIT 1');$st->bind_param('ss',$name,$type);$st->execute();$t=$st->get_result()->fetch_assoc();$st->close();
     if($t) {
      $tid=(int)$t['topic_id'];
      if ($nlmNumber!=='') {
       // Preserve existing non-empty classification, which may have been curated manually.
       $st=$dbs->prepare("UPDATE mst_topic SET classification=?,last_update=CURDATE() WHERE topic_id=? AND (classification IS NULL OR TRIM(classification)='')");
       $st->bind_param('si',$nlmNumber,$tid);
       if(!$st->execute())throw new RuntimeException($st->error);$st->close();
      }
     }
     else {$st=$dbs->prepare('INSERT INTO mst_topic(topic,topic_type,classification,input_date,last_update) VALUES (?, ?, ?, CURDATE(), CURDATE())');$st->bind_param('sss',$name,$type,$nlmNumber);if(!$st->execute())throw new RuntimeException($st->error);$tid=(int)$dbs->insert_id;$st->close();}
     if($bid){
      $st=$dbs->prepare('SELECT 1 FROM biblio_topic WHERE biblio_id=? AND topic_id=?');$st->bind_param('ii',$bid,$tid);$st->execute();$exists=$st->get_result()->fetch_row();$st->close();
      if(!$exists){$level=2;$st=$dbs->prepare('INSERT INTO biblio_topic(biblio_id,topic_id,level) VALUES (?,?,?)');$st->bind_param('iii',$bid,$tid,$level);if(!$st->execute())throw new RuntimeException($st->error);$st->close();}
      $st=$dbs->prepare('INSERT INTO mesh_biblio_map(biblio_id,mesh_id,topic_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE topic_id=VALUES(topic_id)');$st->bind_param('isi',$bid,$mesh,$tid);if(!$st->execute())throw new RuntimeException($st->error);$st->close();
     }else{$_SESSION['biblioTopic'][$tid]=[$tid,2];}
     if($nlmNumber!=='')$savedNlm[$mesh]=$nlmNumber;
     $added++;
    }
    $dbs->commit();$success=$added.' istilah MeSH berhasil ditambahkan.'; $successNlm=array_values(array_unique($savedNlm));
   }catch(Throwable $e){$dbs->rollback();$error=$e->getMessage();}
  }
 }
}
if($q!==''){
 $like='%'.$q.'%';$prefix=$q.'%';
 $st=$dbs->prepare('SELECT d.mesh_id,d.descriptor_name,d.scope_note FROM mesh_descriptors d WHERE d.descriptor_name LIKE ? OR EXISTS(SELECT 1 FROM mesh_terms t WHERE t.mesh_id=d.mesh_id AND t.term LIKE ?) ORDER BY CASE WHEN d.descriptor_name=? THEN 0 WHEN d.descriptor_name LIKE ? THEN 1 ELSE 2 END,d.descriptor_name LIMIT 60');
 $st->bind_param('ssss',$like,$like,$q,$prefix);$st->execute();$rr=$st->get_result();while($row=$rr->fetch_assoc())$results[]=$row;$st->close();
}
?>
<!doctype html><html><head><meta charset="utf-8"><title>Cari MeSH</title><style>
body{font:14px Arial,sans-serif;margin:14px 18px;color:#273244}h2{margin:0 0 12px}input[type=text]{padding:10px;border:1px solid #ccd4dd;border-radius:5px;width:65%;box-sizing:border-box}button{border:0;background:#0758bc;color:#fff;padding:10px 15px;border-radius:5px;cursor:pointer}.item{border:1px solid #dce2e8;border-radius:6px;padding:11px;margin:9px 0}.item label{font-weight:bold;cursor:pointer}.note{font-size:12px;color:#536579;line-height:1.5;margin:6px 0}.ok{padding:10px;background:#e4f7e8;color:#16612a}.err{padding:10px;background:#ffe9e9;color:#a40000}.bar{position:sticky;top:0;z-index:10;background:#fff;border:1px solid #dce2e8;border-radius:6px;padding:10px;margin:10px 0;box-shadow:0 2px 6px rgba(0,0,0,.08)}

.msm-tabs{display:flex;gap:8px;margin:12px 0}.msm-tabs button{background:#e9eef5;color:#273244}.msm-tabs button.active{background:#0758bc;color:#fff}.msm-tree-node{padding:5px 0;border-bottom:1px solid #f1f3f6}.msm-tree-line{display:flex;align-items:center;gap:7px}.msm-tree-line button{padding:2px 8px;background:#edf1f6;color:#26374c}.msm-tree-children{margin-left:22px;border-left:1px solid #dce2e8;padding-left:10px}.msm-tree-label{cursor:pointer}.msm-tree-code{font-size:11px;color:#667}.msm-tree-status{font-size:12px;color:#536579}.msm-hidden{display:none}.msm-tree-scroll{max-height:48vh;overflow:auto;border:1px solid #dce2e8;padding:8px;border-radius:6px}
</style></head><body>
<h2>Cari MeSH — Subjek Bibliografi</h2><p><?= $bid?'Edit bibliografi #'.(int)$bid.' — '.mp_h($title):'Bibliografi baru — subjek disimpan sementara sampai formulir bibliografi disimpan.' ?></p>
<?php if($error):?><div class="err"><?=mp_h($error)?></div><?php endif;?>
<?php if($success):?><div class="ok"><?=mp_h($success)?></div><script>
try{if(window.parent && window.parent!==window)window.parent.postMessage({type:'msm:subjects-saved',nlmNumbers:<?=json_encode($successNlm??[],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,refreshUrl:<?=json_encode(MWB.'bibliography/iframe_topic.php'.($bid?'?biblioID='.$bid:''))?>},window.location.origin);var target=window.opener||window.parent;if(target&&typeof target.setIframeContent==='function'){target.setIframeContent('topicIframe',<?=json_encode(MWB.'bibliography/iframe_topic.php'.($bid?'?biblioID='.$bid:''))?>);}else if(target&&target.document){var f=target.document.getElementById('topicIframe');if(f){f.src=<?=json_encode(MWB.'bibliography/iframe_topic.php'.($bid?'?biblioID='.$bid:''))?>+'&refresh='+Date.now();}}}catch(e){}
</script><?php endif;?>
<div class="msm-tabs"><button type="button" id="msm-tab-search" class="active" onclick="msmSwitch('search')">Pencarian MeSH</button><button type="button" id="msm-tab-tree" onclick="msmSwitch('tree')">Jelajahi Tree MeSH</button></div>
<div id="msm-search-panel">
<form method="get"><input type="hidden" name="mod" value="<?=mp_h($_GET['mod']??'bibliography')?>"><input type="hidden" name="id" value="<?=mp_h($_GET['id']??'')?>"><input type="hidden" name="msm_picker" value="1"><input type="hidden" name="biblioID" value="<?=$bid?>"><input type="text" name="q" value="<?=mp_h($q)?>" placeholder="Cari descriptor atau sinonim MeSH" autofocus><button type="submit">Cari MeSH</button></form>
<?php if($q!==''):?><p>Ditemukan <?=count($results)?> hasil (maksimal 60).</p><?php endif;?>
<?php if($results):?><form method="post"><input type="hidden" name="mod" value="<?=mp_h($_GET['mod']??$_POST['mod']??'bibliography')?>"><input type="hidden" name="id" value="<?=mp_h($_GET['id']??$_POST['id']??'')?>"><input type="hidden" name="msm_picker" value="1"><input type="hidden" name="biblioID" value="<?=$bid?>"><input type="hidden" name="q" value="<?=mp_h($q)?>"><input type="hidden" name="csrf" value="<?=mp_h($_SESSION['msm_picker_csrf'])?>">
<div class="bar"><button type="submit" name="save_mesh" value="1">Simpan subjek terpilih</button> <span class="note">Centang istilah, lalu simpan. Data tersimpan pada subjek SLiMS.</span></div>
<?php foreach($results as $r):?><div class="item"><label><input type="checkbox" name="mesh_ids[]" value="<?=mp_h($r['mesh_id'])?>"> <?=mp_h($r['descriptor_name'])?> <small>(<?=mp_h($r['mesh_id'])?>)</small></label><div class="note"><?=mp_h(mb_strimwidth((string)$r['scope_note'],0,450,'...'))?></div><?=msm_nlm_render($dbs,$r['mesh_id'])?></div><?php endforeach;?>
</form><?php endif;?>
</div>
<div id="msm-tree-panel" class="msm-hidden">
<p class="note">Klik tanda + untuk membuka cabang. Descriptor yang sama dapat muncul pada beberapa jalur. Pilihan disimpan berdasarkan kode MeSH agar tidak duplikat.</p>
<form method="post" id="msm-tree-save">
<input type="hidden" name="mod" value="<?=mp_h($_GET['mod']??$_POST['mod']??'bibliography')?>">
<input type="hidden" name="id" value="<?=mp_h($_GET['id']??$_POST['id']??'')?>">
<input type="hidden" name="msm_picker" value="1">
<input type="hidden" name="biblioID" value="<?=$bid?>">
<input type="hidden" name="q" value="<?=mp_h($q)?>">
<input type="hidden" name="csrf" value="<?=mp_h($_SESSION['msm_picker_csrf'])?>">
<div class="bar"><button type="submit" name="save_mesh" value="1">Simpan descriptor terpilih</button> <span class="msm-tree-status" id="msm-tree-count">0 descriptor dipilih</span></div>
<div id="msm-tree-selected"></div>
<div id="msm-tree-root" class="msm-tree-scroll"><span class="msm-tree-status">Memuat kategori...</span></div>
</form></div>
<script>
(function(){
 var endpoint=<?=json_encode($_SERVER['PHP_SELF'].'?'.http_build_query(['mod'=>$_GET['mod']??$_POST['mod']??'bibliography','id'=>$_GET['id']??$_POST['id']??'','msm_tree'=>'1']),JSON_HEX_TAG|JSON_HEX_AMP)?>;
 var selected=new Map(), loaded=false;
 window.msmSwitch=function(tab){
  document.getElementById('msm-search-panel').classList.toggle('msm-hidden',tab!=='search');
  document.getElementById('msm-tree-panel').classList.toggle('msm-hidden',tab!=='tree');
  document.getElementById('msm-tab-search').classList.toggle('active',tab==='search');
  document.getElementById('msm-tab-tree').classList.toggle('active',tab==='tree');
  if(tab==='tree'&&!loaded){loaded=true;loadTree('',document.getElementById('msm-tree-root'));}
 };
 function updateSelected(){
  var wrap=document.getElementById('msm-tree-selected');wrap.replaceChildren();
  selected.forEach(function(name,id){var inp=document.createElement('input');inp.type='hidden';inp.name='mesh_ids[]';inp.value=id;wrap.appendChild(inp);});
  document.getElementById('msm-tree-count').textContent=selected.size+' descriptor dipilih';
  document.querySelectorAll('.msm-tree-checkbox').forEach(function(c){c.checked=selected.has(c.value);});
 }
 function loadTree(parent,target){
  target.textContent='Memuat cabang...';
  fetch(endpoint+'&parent='+encodeURIComponent(parent),{credentials:'same-origin',headers:{'Accept':'application/json'}}).then(function(r){if(!r.ok)throw Error('HTTP '+r.status);return r.json();}).then(function(data){
   if(data.error)throw Error(data.error);target.replaceChildren();
   if(!data.nodes.length){target.textContent='Tidak ada cabang lanjutan.';return;}
   data.nodes.forEach(function(node){
    var block=document.createElement('div');block.className='msm-tree-node';
    var line=document.createElement('div');line.className='msm-tree-line';
    var children=document.createElement('div');children.className='msm-tree-children msm-hidden';
    if(node.has_children){var exp=document.createElement('button');exp.type='button';exp.textContent='+';exp.setAttribute('aria-label','Buka cabang '+node.code);exp.onclick=function(){
     if(children.classList.contains('msm-hidden')){children.classList.remove('msm-hidden');exp.textContent='−';if(!children.dataset.loaded){children.dataset.loaded='1';loadTree(node.code,children);}}else{children.classList.add('msm-hidden');exp.textContent='+';}
    };line.appendChild(exp);}else{var pad=document.createElement('span');pad.textContent='•';pad.style.padding='0 10px';line.appendChild(pad);}
    if(node.mesh_id){var cb=document.createElement('input');cb.type='checkbox';cb.className='msm-tree-checkbox';cb.value=node.mesh_id;cb.checked=selected.has(node.mesh_id);cb.onchange=function(){if(cb.checked){if(selected.size>=60){cb.checked=false;alert('Maksimal 60 descriptor.');return;}selected.set(node.mesh_id,node.name);}else selected.delete(node.mesh_id);updateSelected();};line.appendChild(cb);}
    var label=document.createElement('span');label.className='msm-tree-label';label.textContent=node.name+' ';line.appendChild(label);
    var code=document.createElement('span');code.className='msm-tree-code';code.textContent=node.code+(node.mesh_id?' · '+node.mesh_id:'');line.appendChild(code);
    block.appendChild(line);block.appendChild(children);target.appendChild(block);
   });
  }).catch(function(e){target.textContent='Gagal memuat hierarki: '+e.message;});
 }
})();
</script>
</body></html>
