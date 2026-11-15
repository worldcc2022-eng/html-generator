<?php
// Standalone CONN router. Copied to the hosting directory as eventforge-conn.php.
declare(strict_types=1);
function connRoute(string $uri,string $scriptName,array $query,string $httpHost):?array {
 $base=rtrim(str_replace('\\','/',dirname($scriptName)),'/.');$path=parse_url($uri,PHP_URL_PATH);if(!is_string($path))return null;
 if(preg_match('~^'.preg_quote($base,'~').'/video/posts/([1-9][0-9]{14})/?$~D',$path,$m))return ['id'=>$m[1],'extension'=>'html'];
 if(preg_match('~^'.preg_quote($base,'~').'/video/&GetFoldersAndFiles=([1-9][0-9]{14})/?$~D',$path,$m))return ['id'=>$m[1],'extension'=>'xml'];
 if($path!==$base.'/'&&$path!==$base)return null;
 $connector=$query['CONNECTOR']??null;if(!is_string($connector)||strlen($connector)>4096||($query['Type']??null)!=='File')return null;
 $connector=str_replace('\\','/',$connector);
 // Exact local authority + base path; never fetch a URL or accept traversal.
 if(!preg_match('~^//+([^/]+)'.preg_quote($base,'~').'/video/&GetFoldersAndFiles=([1-9][0-9]{14})$~D',$connector,$m))return null;
 $authority=static fn(string $host):string=>preg_replace('/:(?:80|443)$/D','',strtolower($host));
 if($authority($m[1])!==$authority($httpHost))return null;
 return ['id'=>$m[2],'extension'=>'xml'];
}
if(defined('EVENTFORGE_CONN_ROUTER_TEST'))return;
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache');
if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
$route=connRoute($_SERVER['REQUEST_URI']??'',$_SERVER['SCRIPT_NAME']??'/eventforge-conn.php',$_GET,$_SERVER['HTTP_HOST']??'');
if($route===null){http_response_code(404);exit('CONN file not found.');}
$directory=__DIR__.'/.eventforge-conn';$file=$directory.'/'.$route['id'].'.'.$route['extension'];
if(is_link($directory)||!is_dir($directory)||is_link($file)||!is_file($file)){http_response_code(404);exit('CONN file not found.');}
$stream=@fopen($file,'rb');if(!$stream){http_response_code(404);exit;}$stat=fstat($stream);
header('Content-Type: '.($route['extension']==='xml'?'application/xml':'text/html').'; charset=utf-8');if($stat!==false)header('Content-Length: '.$stat['size']);
if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')fpassthru($stream);fclose($stream);
