<?php
/*
 * File: schema.php
 * Created on Mon Nov 02 2026
 * Last Updated: Mon Nov 02 2026 5:12:35 PM
 * Author: Erwan Setyo Budi
 * Email: erwans818@gmail.com
 * License: The GNU General Public License, Version 3 (GPL-3.0) - Copyright (C) 2026 Erwan Setyo Budi. This program is free software.
 */

function msm_schema(mysqli $dbs): void {
 $queries = [
 "CREATE TABLE IF NOT EXISTS mesh_descriptors (mesh_id VARCHAR(16) NOT NULL PRIMARY KEY, descriptor_name VARCHAR(512) NOT NULL, scope_note MEDIUMTEXT NULL, descriptor_class VARCHAR(8) NULL, dataset_year SMALLINT NOT NULL DEFAULT 2026, KEY idx_name (descriptor_name(190))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
 "CREATE TABLE IF NOT EXISTS mesh_terms (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, mesh_id VARCHAR(16) NOT NULL, term VARCHAR(512) NOT NULL, KEY idx_mesh (mesh_id), KEY idx_term (term(190)), UNIQUE KEY uq_mesh_term (mesh_id,term(190))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
 "CREATE TABLE IF NOT EXISTS mesh_tree_numbers (mesh_id VARCHAR(16) NOT NULL, tree_number VARCHAR(100) NOT NULL, PRIMARY KEY(mesh_id,tree_number), KEY idx_tree(tree_number)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
 "CREATE TABLE IF NOT EXISTS mesh_biblio_map (biblio_id INT NOT NULL, mesh_id VARCHAR(16) NOT NULL, topic_id INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (biblio_id,mesh_id), KEY idx_topic(topic_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
 "CREATE TABLE IF NOT EXISTS mesh_import_logs (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, dataset_year SMALLINT NOT NULL, records_count INT NOT NULL, imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
 ];
 foreach ($queries as $sql) if (!$dbs->query($sql)) throw new RuntimeException($dbs->error);
}
