<?php
declare(strict_types=1);
define('EVENTGEN',true);require __DIR__.'/app/bootstrap.php';
if(file_exists(__DIR__.'/config.php')||file_exists(__DIR__.'/storage/installed.lock')){http_response_code(403);page('Installation Locked','<section class="panel"><div class="eyebrow">SETUP COMPLETE</div><h1>Installation is locked.</h1><p>Sign in to your workspace. You can delete install.php from your server.</p><a class="button" href="/login">Go to Login</a></section>',true);exit;}
$error='';$missing=[];
foreach(['pdo_mysql','curl','openssl','zip','mbstring'] as $ext)if(!extension_loaded($ext))$missing[]=$ext;
if(version_compare(PHP_VERSION,'8.2.0','<'))$missing[]='PHP 8.2+';
if(!is_writable(__DIR__)||!is_writable(__DIR__.'/storage'))$missing[]='write access to the application and storage directories';
if($_SERVER['REQUEST_METHOD']==='POST'){
 $guard=fopen(__DIR__.'/storage/install.guard','c');
 try{
  verifyCsrf();if(!$guard||!flock($guard,LOCK_EX|LOCK_NB))throw new RuntimeException('Another installation is in progress.');
  if(file_exists(__DIR__.'/config.php')||file_exists(__DIR__.'/storage/installed.lock'))throw new RuntimeException('Installation is already locked.');
  if($missing)throw new RuntimeException('Enable the missing server requirements listed below.');
  $host=trim($_POST['db_host']??'');$port=(int)($_POST['db_port']??3306);$name=trim($_POST['db_name']??'');$user=trim($_POST['db_user']??'');$pass=$_POST['db_password']??'';$admin=trim($_POST['username']??'');$password=$_POST['password']??'';
  if(!preg_match('/^[a-zA-Z0-9._-]+$/',$host)||$port<1||$port>65535||!preg_match('/^[a-zA-Z0-9_-]+$/',$name)||$user==='')throw new InvalidArgumentException('Check the database host, port, name and username.');
  if(!preg_match('/^[a-zA-Z0-9_.@-]{3,80}$/',$admin)||strlen($password)<12)throw new InvalidArgumentException('Use a 3–80 character username and a password with at least 12 characters.');
  if(!hash_equals($password,$_POST['password_confirm']??''))throw new InvalidArgumentException('Admin passwords do not match.');
  try{$pdo=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}catch(PDOException $e){throw new RuntimeException('Could not connect to the database. Check credentials and assign the database user all privileges in cPanel.');}
  $pdo->exec('CREATE TABLE IF NOT EXISTS eg_users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(80) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  if((int)$pdo->query('SELECT COUNT(*) FROM eg_users')->fetchColumn()>0)throw new RuntimeException('This database already contains an EventForge admin. Installation will not overwrite it. Restore the original config.php or choose a fresh database.');
  $pdo->exec('CREATE TABLE IF NOT EXISTS eg_settings (name VARCHAR(80) PRIMARY KEY, value LONGTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  $pdo->exec('CREATE TABLE IF NOT EXISTS eg_jobs (id CHAR(32) PRIMARY KEY, user_id INT UNSIGNED NOT NULL, event VARCHAR(180) NOT NULL, total INT NOT NULL, completed INT NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (user_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  $pdo->exec('CREATE TABLE IF NOT EXISTS eg_login_attempts (ip_hash CHAR(64) PRIMARY KEY, attempts INT NOT NULL DEFAULT 0, blocked_until BIGINT NOT NULL DEFAULT 0, touched BIGINT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  $cfg=['db_host'=>$host,'db_port'=>$port,'db_name'=>$name,'db_user'=>$user,'db_password'=>$pass,'secret'=>bin2hex(random_bytes(32))];
  $contents="<?php\nif (!defined('EVENTGEN')) { http_response_code(404); exit; }\nreturn ".var_export($cfg,true).";\n";
  $tmp=__DIR__.'/config.php.tmp';if(file_put_contents($tmp,$contents,LOCK_EX)===false)throw new RuntimeException('Unable to write configuration. Check application directory permissions.');chmod($tmp,0600);
  $pdo->beginTransaction();
  try{$q=$pdo->prepare('INSERT INTO eg_users (username,password_hash) VALUES (?,?)');$q->execute([$admin,password_hash($password,PASSWORD_DEFAULT)]);$pdo->prepare('INSERT INTO eg_settings (name,value) VALUES (?,?)')->execute(['model','gpt-4.1-mini']);$pdo->commit();}
  catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();@unlink($tmp);throw new RuntimeException('Unable to initialize the admin account. Check database permissions.');}
  if(!rename($tmp,__DIR__.'/config.php'))throw new RuntimeException('Admin created but configuration could not be finalized. Recover config.php.tmp as config.php using File Manager.');
  writeFileAtomic(__DIR__.'/storage/installed.lock',gmdate('c'));
  $_SESSION=[];session_regenerate_id(true);header('Location: /login?installed=1');exit;
 }catch(InvalidArgumentException|RuntimeException $e){$error=$e instanceof PDOException?'Database setup failed. Check database privileges.':$e->getMessage();}
 catch(Throwable $e){$error='Installation could not finish. Check server requirements and permissions.';}
 finally{if(is_resource($guard)){flock($guard,LOCK_UN);fclose($guard);}}
}
$body='<section class="panel"><div class="eyebrow">FIRST-TIME SETUP</div><h1>Your workspace<br>starts here.</h1><p>Connect your database and create your administrator account.</p>';
if($error)$body.=message($error);
if($missing)$body.=message('Missing requirements: '.implode(', ',$missing));
$body.='<form method="post" class="stack" data-busy="Installing…">'.tokenField().'<h2>Database Connection</h2><p class="hint">Create an empty database and user in cPanel first, then assign All Privileges. This installer creates the tables.</p><div class="grid">'.input('Database Host','db_host','text',$_POST['db_host']??'localhost').input('Database Port','db_port','number',$_POST['db_port']??'3306').'</div>'.input('Database Name','db_name','text',$_POST['db_name']??'').input('Database Username','db_user','text',$_POST['db_user']??'').input('Database Password','db_password','password','','',false).'<hr><h2>Administrator Account</h2>'.input('Username','username','text',$_POST['username']??'').input('Password','password','password','','At least 12 characters.').input('Confirm Password','password_confirm','password').'<button class="primary" '.($missing?'disabled':'').'>Install EventForge</button><small>The installer locks after setup. Delete install.php once you sign in.</small></form></section>';
page('Install',$body,true);
