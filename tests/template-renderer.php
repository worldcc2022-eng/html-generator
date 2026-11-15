<?php
require __DIR__.'/../app/Generator.php';require __DIR__.'/../app/TemplateRenderer.php';
function renderCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$fixture=__DIR__.'/fixtures/article-template.html';
$source=file_get_contents($fixture);
$title='[WATCH] PSG vs Barcelona';$description='Follow PSG vs Barcelona on the selected date with match details.';
$options=['date'=>'2026-11-02','images'=>['https://example.com/new.jpg?a=1&b=2']];
$out=TemplateRenderer::render($source,$title,$description,$options,[$title,'[LIVE] PSG vs Barcelona','[TV] PSG vs Barcelona']);

renderCheck(str_contains($out,'property="og:description"'),'malformed Open Graph attribute repaired');
renderCheck(preg_match('~<meta content="'.preg_quote($description,'~').'" property="og:description"~',$out)===1,'malformed OG description replaced despite >> in old content');
foreach(['sdc-article-header__long-title','ui-media-caption__caption-text'] as $c){preg_match('~class="'.preg_quote($c,'~').'"[^>]*>(.*?)</span>~s',$out,$m);renderCheck(trim($m[1]??'')===$title.', '.$description,$c.' uses title and description');}
preg_match('~class="sdc-article-date__date-time"[^>]*>(.*?)</p>~s',$out,$m);renderCheck(trim($m[1]??'')==='2026-11-02','article date normalized');
preg_match_all('~class="sdc-site-tile__headline-text"[^>]*>(.*?)</span>~s',$out,$m);renderCheck(count($m[1])>5,'all related title spans found');
foreach($m[1] as $v)renderCheck(in_array(trim($v),[$title,'[LIVE] PSG vs Barcelona','[TV] PSG vs Barcelona'],true),'related headline from generated title pool');
renderCheck(!str_contains($out,'https://sports2live.online/cdn/images1.jpg'),'all target image references including query variants replaced');
renderCheck(str_contains($out,'https://example.com/new.jpg?a=1&amp;b=2'),'HTML image URL escaped');
renderCheck(str_contains($out,'https://example.com/new.jpg?a=1\\u0026b=2'),'JSON image URL escaped safely');
renderCheck(count(TemplateRenderer::imageUrls(implode("\n",array_map(fn($n)=>'https://example.com/'.$n.'.jpg',range(1,600)))))===600,'600 image URLs supported');
renderCheck(TemplateRenderer::imageUrls("https://example.com/a.jpg\nhttps://example.com/a.jpg\n")===['https://example.com/a.jpg'],'duplicate image URLs excluded');
$off=TemplateRenderer::render($source,$title,null,['date'=>'2026-11-02','images'=>[]],[$title]);renderCheck(str_contains(html_entity_decode($off,ENT_QUOTES|ENT_HTML5,'UTF-8'),TemplateRenderer::description($source)),'template description reused with AI off');
renderCheck(str_contains($off,'https://sports2live.online/cdn/images1.jpg'),'empty image list preserves original images');
$script='<script>const keep="<span class=\"sdc-site-tile__headline-text\">UNCHANGED</span>";</script>';
$preserved=TemplateRenderer::render($script.$source,$title,$description,$options,[$title]);renderCheck(str_starts_with($preserved,$script),'executable scripts remain unchanged');
$images=[];for($i=0;$i<20;$i++){$html=TemplateRenderer::render($source,$title,$description,['date'=>'2026-11-02','images'=>['https://example.com/one.jpg','https://example.com/two.jpg','https://example.com/three.jpg']],[$title]);preg_match('~<meta content="(https://example.com/[^"]+)" name="x:image"~',$html,$image);$images[$image[1]??'']=true;}renderCheck(count($images)>1,'image choice varies between files');
echo "ALL TEMPLATE RENDERER TESTS PASSED\n";
