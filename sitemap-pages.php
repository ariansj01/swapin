<?php

http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
echo "This sitemap has been retired.\n";
