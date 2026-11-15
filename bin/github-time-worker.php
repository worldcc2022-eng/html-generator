<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// Compatibility stub: an old installed service cannot start another Git push.
fwrite(STDOUT,"The automatic GitHub timestamp worker has been removed. Disable eventforge-github-time.service.\n");
exit(0);
