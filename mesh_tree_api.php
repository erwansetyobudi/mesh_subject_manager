<?php
/** JSON endpoint routed through the SLiMS plugin container. */
defined('INDEX_AUTH') OR die('No direct access');
require_once SB.'admin/default/session.inc.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
if (!utility::havePrivilege('bibliography','r')) { http_response_code(403); echo json_encode(['error'=>'Akses ditolak']); return; }
$parent = trim((string)($_GET['parent'] ?? ''));
if (strlen($parent)>100 || ($parent!=='' && !preg_match('/^[A-Z](?:[0-9]{2})?(?:\.[0-9]{3})*$/', $parent))) { http_response_code(400); echo json_encode(['error'=>'Tree Number tidak valid']); return; }
try {
  $nodes=[];
  if ($parent==='') {
    $r=$dbs->query('SELECT DISTINCT LEFT(tree_number,1) AS code FROM mesh_tree_numbers ORDER BY code');
    while ($row=$r->fetch_assoc()) $nodes[]=['code'=>$row['code'],'name'=>['A'=>'Anatomy','B'=>'Organisms','C'=>'Diseases','D'=>'Chemicals and Drugs','E'=>'Analytical, Diagnostic and Therapeutic Techniques and Equipment','F'=>'Psychiatry and Psychology','G'=>'Phenomena and Processes','H'=>'Disciplines and Occupations','I'=>'Anthropology, Education, Sociology and Social Phenomena','J'=>'Technology, Industry, Agriculture','K'=>'Humanities','L'=>'Information Science','M'=>'Named Groups','N'=>'Health Care','V'=>'Publication Characteristics','Z'=>'Geographicals'][$row['code']]??$row['code'],'mesh_id'=>null,'has_children'=>true];
  } else {
    $depth=(strlen($parent)===1 ? 1 : substr_count($parent,'.')+2);
    $prefix=$parent;
    $like=(strlen($parent)===1 ? $parent.'%' : $parent.'.%');
    $sql='SELECT DISTINCT SUBSTRING_INDEX(t.tree_number, \'.\', ?) AS code FROM mesh_tree_numbers t WHERE t.tree_number LIKE ? ORDER BY code LIMIT 250';
    $st=$dbs->prepare($sql);$st->bind_param('is',$depth,$like);$st->execute();$res=$st->get_result();$codes=[];
    while($row=$res->fetch_assoc()) {
      $code=$row['code'];
      if ($code===$parent || !preg_match('/^[A-Z][0-9]{2}(?:\.[0-9]{3})*$/',$code)) continue;
      if (strlen($parent)>1 && strpos($code,$parent.'.')!==0) continue;
      $codes[]=$code;
    }
    $st->close();
    $find=$dbs->prepare('SELECT d.mesh_id,d.descriptor_name FROM mesh_tree_numbers t JOIN mesh_descriptors d ON d.mesh_id=t.mesh_id WHERE t.tree_number=? LIMIT 1');
    $children=$dbs->prepare('SELECT 1 FROM mesh_tree_numbers WHERE tree_number LIKE ? LIMIT 1');
    foreach($codes as $code){
      $find->bind_param('s',$code);$find->execute();$r=$find->get_result()->fetch_assoc();
      $childLike=$code.'.%';$children->bind_param('s',$childLike);$children->execute();$has=(bool)$children->get_result()->fetch_row();
      $nodes[]=['code'=>$code,'name'=>$r['descriptor_name']??$code,'mesh_id'=>$r['mesh_id']??null,'has_children'=>$has];
    }
    $find->close();$children->close();
  }
  echo json_encode(['nodes'=>$nodes],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch(Throwable $e) { http_response_code(500); error_log('MeSH tree: '.$e->getMessage());echo json_encode(['error'=>'Gagal memuat hierarki MeSH']); }
