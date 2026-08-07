<?php
http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
die("403 Forbidden - Direct storage access is disabled.");
