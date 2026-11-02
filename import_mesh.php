<?php
/** CLI: php import_mesh.php /absolute/path/desc2026.xml [year] */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
if (!extension_loaded('xmlreader') || !extension_loaded('mysqli')) exit("PHP XMLReader dan mysqli diperlukan.\n");
$file=$argv[1]??''; $year=(int)($argv[2]??2026);
if (!is_file($file) || !is_readable($file) || $year<2000 || $year>2100) exit("Usage: php import_mesh.php /path/desc2026.xml [2026]\n");
$sysconfig=realpath(__DIR__.'/../../sysconfig.inc.php');
if (!$sysconfig) exit("Letakkan folder plugin di plugins/mesh_subject_manager, kemudian jalankan kembali.\n");
define('INDEX_AUTH', '1'); require $sysconfig; require __DIR__.'/schema.php';
msm_schema($dbs);
$dbs->set_charset('utf8mb4');
$reader=new XMLReader();
if (!$reader->open($file,null,LIBXML_NONET|LIBXML_COMPACT)) exit("Gagal membuka XML.\n");
$stD=$dbs->prepare('INSERT INTO mesh_descriptors(mesh_id,descriptor_name,scope_note,descriptor_class,dataset_year) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE descriptor_name=VALUES(descriptor_name),scope_note=VALUES(scope_note),descriptor_class=VALUES(descriptor_class),dataset_year=VALUES(dataset_year)');
$stDelT=$dbs->prepare('DELETE FROM mesh_terms WHERE mesh_id=?');
$stDelTree=$dbs->prepare('DELETE FROM mesh_tree_numbers WHERE mesh_id=?');
$stT=$dbs->prepare('INSERT IGNORE INTO mesh_terms(mesh_id,term) VALUES(?,?)');
$stTree=$dbs->prepare('INSERT IGNORE INTO mesh_tree_numbers(mesh_id,tree_number) VALUES(?,?)');
$count=0; $dbs->begin_transaction();
try {
 while ($reader->read()) {
  if ($reader->nodeType!==XMLReader::ELEMENT || $reader->name!=='DescriptorRecord') continue;
  $xml=$reader->readOuterXML();
  $node=simplexml_load_string($xml,'SimpleXMLElement',LIBXML_NONET);
  if (!$node) continue;
  $id=trim((string)$node->DescriptorUI); $name=trim((string)$node->DescriptorName->String);
  if (!preg_match('/^D[0-9]+$/',$id) || $name==='') continue;
  $scope=trim((string)$node->ConceptList->Concept[0]->ScopeNote);
  foreach ($node->ConceptList->Concept as $concept) if ((string)$concept['PreferredConceptYN']==='Y') { $scope=trim((string)$concept->ScopeNote); break; }
  $class=(string)$node['DescriptorClass'];
  $stD->bind_param('ssssi',$id,$name,$scope,$class,$year); if (!$stD->execute()) throw new RuntimeException($stD->error);
  $stDelT->bind_param('s',$id); if(!$stDelT->execute()) throw new RuntimeException($stDelT->error);
  $stDelTree->bind_param('s',$id); if(!$stDelTree->execute()) throw new RuntimeException($stDelTree->error);
  $terms=[$name=>true];
  foreach($node->ConceptList->Concept as $concept) foreach($concept->TermList->Term as $term) { $t=trim((string)$term->String); if($t!=='') $terms[$t]=true; }
  foreach(array_keys($terms) as $t) { $stT->bind_param('ss',$id,$t); if(!$stT->execute()) throw new RuntimeException($stT->error); }
  foreach($node->TreeNumberList->TreeNumber as $tree) { $t=trim((string)$tree); if($t!=='') { $stTree->bind_param('ss',$id,$t); if(!$stTree->execute()) throw new RuntimeException($stTree->error); } }
  $count++;
  if($count%100===0) { $dbs->commit(); $dbs->begin_transaction(); if($count%1000===0) echo "Imported: $count\n"; }
 }
 $dbs->commit();
 $st=$dbs->prepare('INSERT INTO mesh_import_logs(dataset_year,records_count) VALUES(?,?)'); $st->bind_param('ii',$year,$count); $st->execute(); $st->close();
 echo "SUCCESS: $count descriptors imported for $year\n";
} catch(Throwable $e) { $dbs->rollback(); fwrite(STDERR,"ERROR after $count: ".$e->getMessage()."\n"); exit(1); }
finally { $reader->close(); }
