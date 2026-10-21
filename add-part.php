<?php
/**
 * Redirect alias to part-add.php
 */
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: part-add.php' . $qs);
exit;
