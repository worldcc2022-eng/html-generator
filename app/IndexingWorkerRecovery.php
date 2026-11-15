<?php
declare(strict_types=1);
final class IndexingWorkerRecovery {
 public static function databaseLost(Throwable $error):bool {
  for($e=$error;$e!==null;$e=$e->getPrevious()){
   if(!$e instanceof PDOException)continue;
   $info=$e->errorInfo??[];$state=(string)($info[0]??$e->getCode());$driver=(int)($info[1]??$e->getCode());
   if(str_starts_with($state,'08')||in_array($driver,[2002,2003,2006,2013,2055,4031,1927],true))return true;
   if(preg_match('/MySQL server has gone away|Lost connection to (?:MySQL|server)|server closed the connection/i',$e->getMessage()))return true;
  }
  return false;
 }
}
