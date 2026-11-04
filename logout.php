<?php
require_once __DIR__.'/includes/portal.php';if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: index.php');exit;}try{portalCsrf();logoutUser();header('Location: index.php');}catch(Throwable $e){http_response_code(403);echo 'Reload the portal before signing out.';}
