<?php
require __DIR__.'/../app/GitHubConnect.php';
$root=sys_get_temp_dir().'/eg-git-probe-'.bin2hex(random_bytes(8));mkdir($root,0700);
$git=is_executable('/usr/bin/git')?'/usr/bin/git':'/usr/local/bin/git';
function command(array $args):array{global $git;$r=GitHubConnect::run(array_merge([$git],$args));if($r['code']!==0)throw new RuntimeException($r['err']);return $r;}
function removeTree($path){foreach(scandir($path) as $p){if($p==='.'||$p==='..')continue;$f=$path.'/'.$p;if(is_dir($f)&&!is_link($f))removeTree($f);else unlink($f);}rmdir($path);}
try{
 command(['init','--bare','--template=',$root.'/remote.git']);command(['init','--template=',$root.'/source']);
 command(['-C',$root.'/source','-c','user.email=test@example.com','-c','user.name=Test','commit','--allow-empty','-m','Initial fixture']);
 command(['-C',$root.'/source','push',$root.'/remote.git','HEAD:refs/heads/main']);
 $sha=trim(command(['-C',$root.'/source','rev-parse','HEAD'])['out']);
 command(['init','--bare','--template=',$root.'/probe.git']);
 $before=command(['--git-dir='.$root.'/remote.git','show-ref'])['out'];
 $probe=command(['--git-dir='.$root.'/probe.git','push','--dry-run','--porcelain',$root.'/remote.git',$sha.':refs/heads/main']);
 if(!str_contains($probe['out'],'[up to date]'))throw new RuntimeException('Existing-SHA probe did not recognize unchanged remote');
 if(command(['--git-dir='.$root.'/remote.git','show-ref'])['out']!==$before)throw new RuntimeException('Probe changed remote refs');
 if(glob($root.'/probe.git/objects/??/*'))throw new RuntimeException('Probe unexpectedly downloaded objects');
 echo "PASS real Git existing-SHA push dry run succeeds without a clone and preserves remote refs\n";
}finally{removeTree($root);}
