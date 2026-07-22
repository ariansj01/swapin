<?php

require_once __DIR__.'/../includes/config.php';

require_admin();


$id=(int)($_GET['id'] ?? 0);


if($id){

DB::query(
"DELETE FROM content_pages WHERE id=?",
[$id]
);

}


header(
"Location: ".APP_URL."/admin/pages.php"
);

exit;

