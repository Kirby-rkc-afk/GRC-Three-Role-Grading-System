<?php
if($_SERVER['REQUEST_METHOD']==='POST'){require __DIR__.'/includes/signin.php';exit;}
header('Location: index.php');exit;