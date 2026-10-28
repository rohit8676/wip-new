<?php
require "C:/xampp/htdocs/wip-with-chield-part/config/db.php";
global $pdo;
$r = $pdo->query("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'production_stage_log'");
foreach($r->fetchAll() as $col) {
    echo $col["COLUMN_NAME"] . " (" . $col["DATA_TYPE"] . ")\n";
}
