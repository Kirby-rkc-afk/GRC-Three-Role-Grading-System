<?php
/** Local defaults; configure production credentials through GRC_DB_* environment variables. */
declare(strict_types=1);
foreach (['HOST'=>'127.0.0.1','PORT'=>'3306','NAME'=>'grc_grading','USER'=>'root','PASS'=>''] as $key=>$default) {
    if (!defined('DB_'.$key)) define('DB_'.$key, getenv('GRC_DB_'.$key) !== false ? getenv('GRC_DB_'.$key) : $default);
}
function getDbConnection(): PDO {
    static $pdo=null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3]);
    return $pdo;
}
