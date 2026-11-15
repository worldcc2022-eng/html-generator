<?php
declare(strict_types=1);
require_once __DIR__ .'/SchemaTime.php';
final class EventGenerator {
    public static function optionalDate(string $value,string $order='auto',string $event=''):?DateTimeImmutable {return trim($value)===''?null:self::date($value,$order,$event);}
    public static function date(string $value,string $order='auto',string $event=''): DateTimeImmutable {
        $value=trim($value);
        if(preg_match('/^\d{1,2}$/D',$value)){
            $now=new DateTimeImmutable('now',new DateTimeZone('-05:00'));
            $year=preg_match('/\b((?:19|20)\d{2})\b/',$event,$yearMatch)?$yearMatch[1]:$now->format('Y');
            $value=sprintf('%04d-%02d-%02d',(int)$year,(int)$now->format('m'),(int)$value);
        }
        if($value!==''&&!preg_match('/\b\d{4}\b/',$value)){
            $year=preg_match('/\b((?:19|20)\d{2})\b/',$event,$yearMatch)?$yearMatch[1]:(new DateTimeImmutable('now',new DateTimeZone('Asia/Dhaka')))->format('Y');
            $value.=preg_match('~^\d{1,2}[./-]\d{1,2}$~',$value)?'/'.$year:' '.$year;
        }
        if(!in_array($order,['auto','dmy','mdy'],true)||strlen($value)>100||!preg_match('/\b\d{4}\b/',$value))throw new InvalidArgumentException('Enter an event date including a four-digit year.');
        if(preg_match('~^(\d{1,2})[./-](\d{1,2})[./-](\d{4})$~',$value,$m)){
            [$all,$a,$b,$y]=$m;$a=(int)$a;$b=(int)$b;
            if($order==='auto'){
                if($a<=12&&$b<=12&&$a!==$b)throw new InvalidArgumentException('Ambiguous numeric date. Choose day/month/year or month/day/year below.');
                $order=$a>12?'dmy':'mdy';
            }
            [$d,$mo]=$order==='dmy'?[$a,$b]:[$b,$a];
            if(!checkdate($mo,$d,(int)$y))throw new InvalidArgumentException('That calendar date does not exist.');
            return new DateTimeImmutable(sprintf('%04d-%02d-%02d',$y,$mo,$d),new DateTimeZone('UTC'));
        }
        if(preg_match('~^(\d{4})[./-](\d{1,2})[./-](\d{1,2})$~',$value,$m)){
            if(!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))throw new InvalidArgumentException('That calendar date does not exist.');
            $value=sprintf('%04d-%02d-%02d',$m[1],$m[2],$m[3]);
        } elseif(!preg_match('/\b(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\b/i',$value)){
            throw new InvalidArgumentException('Use a calendar date such as 31 October 2026, 2026-10-31 or 31/10/2026.');
        }
        try{$date=new DateTimeImmutable($value,new DateTimeZone('UTC'));$e=DateTimeImmutable::getLastErrors();}
        catch(Exception $e){throw new InvalidArgumentException('Unable to read that date. Try 31 October 2026.');}
        if($e&&($e['warning_count']||$e['error_count']))throw new InvalidArgumentException('That calendar date does not exist.');
        return $date->setTime(0,0);
    }
    public static function xmlBase64(string $path): string {
        $path=preg_replace('~^https?://~i','',trim($path));$path=ltrim($path,'/');
        if(!preg_match('~^[a-z0-9.-]+(?::[0-9]+)?/[a-z0-9_./%\~-]+\.xml$~i',$path)||str_contains($path,'..'))throw new InvalidArgumentException('Enter an XML host/path, for example spor14.github.io/mcq/cast.xml.');
        return base64_encode('<krpano><include url="/\\/'.$path.'"/></krpano>');
    }
    public static function slug(string $value): string {
        if(function_exists('iconv'))$value=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value)?:$value;
        return trim(preg_replace('/[^a-z0-9]+/','-',strtolower($value)),'-');
    }
    public static function shortName(string $event): string {
        $aliases=['washington wizards'=>'Wizards','charlotte hornets'=>'Hornets','los angeles lakers'=>'Lakers','golden state warriors'=>'Warriors','boston celtics'=>'Celtics','new york knicks'=>'Knicks','los angeles clippers'=>'Clippers','oklahoma city thunder'=>'Thunder','philadelphia 76ers'=>'76ers','paris saint-germain'=>'PSG','paris saint germain'=>'PSG','fc barcelona'=>'Barcelona','real madrid cf'=>'Real Madrid','manchester united'=>'Man United','manchester city'=>'Man City','tottenham hotspur'=>'Tottenham','new england patriots'=>'Patriots','kansas city chiefs'=>'Chiefs','san francisco 49ers'=>'49ers','tampa bay buccaneers'=>'Buccaneers','los angeles dodgers'=>'Dodgers','new york yankees'=>'Yankees'];
        $parts=preg_split('/\s+(?:vs\.?|v\.?|versus)\s+/i',trim($event));
        if(count($parts)!==2)return trim(preg_replace('/\s+(?:19|20)\d{2}\s*$/','',$event));
        return implode(' vs ',array_map(function($p)use($aliases){$p=trim($p);if(isset($aliases[strtolower($p)]))return $aliases[strtolower($p)];$words=preg_split('/\s+/',$p);return count($words)>1?end($words):$p;},$parts));
    }
    public static function rows(string $event,string $short,DateTimeImmutable $date,int $count,string $sport='general',string $stem=''): array {
        if($count<1||$count>1000)throw new InvalidArgumentException('Enter a whole-number count from 1 to 1000.');
        if(trim($event)===''||mb_strlen($event)>180)throw new InvalidArgumentException('Enter an event name of at most 180 characters.');
        $short=trim($short)?:self::shortName($event);
        $stem=$stem?:'video-'.self::slug($event).'-live';
        if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,179}$/',$stem))throw new InvalidArgumentException('ID prefix must use letters, numbers, hyphens or underscores (maximum 180 characters).');
        $word=match($sport){'boxing','mma'=>'Fight','soccer'=>'Match','tennis'=>'Tennis','basketball','football','baseball','hockey'=>'Game',default=>'Event'};
        $badges=['++[Watch Live]TV!]','[LIVE STREAM]','++[LIVE-TV]','[Watch!!]','!![LIVE@TV]','++[STREAMING]','[LIVE!!]','++[WATCH-TV]','[WATCH]','++[LIVE STREAM]','[LIVE-TV!!]','++[WATCH LIVE]','[STREAM!!]','[LIVE@FREE]','++[WATCH@TV]','++[LIVE TODAY]','[Watch@LIVE]','++[STREAMS]','++[TV@LIVE]','++[LIVE!!TV]','[LIVE NOW]','[STREAM-TV]','[WATCH FREE]','[LIVE@STREAM]','[WATCH!!LIVE]','[STREAMS@LIVE]','['.strtoupper($word).'@LIVE]'];
        $tails=['Live Stream','LIVE STREAMS ON TV','Watch Online','TV Channel','Stream Online','LIVE TV Coverage','Watch Live Today','Online Streams','Live Broadcast','Watch Now','Stream on TV','Live Viewing Guide',$word.' Day Live','Live Online TV','Event Streams'];
        $layouts=[
            '{b} {e} {t}','{b} Watch {e} — {t}','{e} {b} {t}','{b} {t}: {e}','{e} — {t} {b}',
            '{b} {e} | {w} Live Online','{b} {w} Night: {e} — {t}','{t} {b} {e}','{e}: Watch the {w} {b} {t}',
            '{b} On TV: {e} | {t}','{b} Follow {e} — {w} Stream','{w} Live {b} {e} — {t}',
            '{b} {e} | Watch the {w} Now','{e} {t} — {b} {w} Coverage','{b} Online {w}: {e} | {t}',
            '{b} {e} — Stream the {w} on TV','{t} for {e} {b}','{e} | {b} Today’s {w} Stream',
            '{b} {w} Center — {e}: {t}','Watch {e} {b} | {t}','{e} — {b} Online Coverage: {t}',
            '{b} Live {w} TV | {e} | {t}','{t}: {b} Follow the {w} — {e}','{b} {e}: {w} Broadcast Online',
            '{e} | {w} Live — {t} {b}'
        ];
        $pool=[];foreach($layouts as $layout)foreach($badges as $badge)foreach($tails as $tail)$pool[]=[$layout,$badge,$tail];shuffle($pool);
        $full=array_fill(0,$count,false);$dated=$full;for($i=0;$i<(int)round($count*.3);$i++){$full[$i]=true;$dated[$i]=true;}shuffle($full);shuffle($dated);
        $unicode=array_fill(0,$count,false);for($i=0;$i<(int)round($count*.5);$i++)$unicode[$i]=true;shuffle($unicode);
        $formats=['j F Y','F j, Y','M j Y','d-m-Y','F j Y'];$rows=[];$seen=[];$structures=[];$ids=self::ids([$stem],$count,$event);
        for($i=0;$i<$count;$i++){
            do{
                [$layout,$badge,$tail]=array_pop($pool);
                // Distinct wording/order, not punctuation-only variants.
                $key=$layout.'|'.$badge.'|'.$tail;
                $title=strtr($layout,['{b}'=>$badge,'{e}'=>$full[$i]?$event:$short,'{t}'=>$tail,'{w}'=>$word]);
                if($unicode[$i])$title=strtr($title,['LIVE'=>'𝐋𝐈𝐕𝐄','Live'=>'ＬＩＶＥ','Stream'=>'Ｓｔｒｅａｍ','STREAM'=>'ＳＴＲＥＡＭ','TV'=>'ＴＶ','Watch'=>'𝐖𝐀𝐓𝐂𝐇','WATCH'=>'𝐖𝐀𝐓𝐂𝐇','Online'=>'Ｏｎｌｉｎｅ']);
                if($dated[$i])$title.=' — '.$date->format($formats[array_rand($formats)]);
            }while(isset($seen[$title])||isset($structures[$key]));
            $seen[$title]=true;$structures[$key]=true;
            $rows[]=['id'=>$ids[$i],'title'=>$title,'full'=>$full[$i],'dated'=>$dated[$i]];
        }return $rows;
    }

    public static function ids(array $seeds,int $count,string $event,array $excludedIds=[]): array {
        $seeds=array_values(array_filter(array_map('trim',$seeds),fn($s)=>$s!==''));
        if(count($seeds)>1000)throw new InvalidArgumentException('Use up to 1,000 ID seeds, one per line.');
        if(!$seeds)$seeds=['video-'.self::slug($event).'-live'];
        $eventSlug=self::slug($event);if($eventSlug==='')throw new InvalidArgumentException('The event name must contain letters or numbers usable in an ID.');
        if($count<1||$count>1000)throw new InvalidArgumentException('Enter a whole-number count from 1 to 1000.');
        $stems=[];
        foreach($seeds as $seed){
            if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,179}$/',$seed))throw new InvalidArgumentException('IDs must use letters, numbers, hyphens or underscores only (maximum 180 characters). Do not include .html.');
            // Replace old streaming suffixes such as _ustv1 rather than accumulating counters.
            $seed=preg_replace('/_(?:ustv|us|liv|live|onlinn|online|tv|stream|streams)\d*$/i','',$seed)?:$seed;
            $stem=self::slug($seed);
            if(str_starts_with($stem,'video-'))$stem=substr($stem,6);
            $stem=trim(preg_replace('/(^|-)'.preg_quote($eventSlug,'/').'(?=-|$)/','$1',$stem),'-');
            $stem='video-'.$eventSlug.($stem!==''?'-'.self::slug($stem):'');
            if(strlen($stem)>230)throw new InvalidArgumentException('The ID seed and event name together are too long. Shorten the ID seed (maximum combined length 230 characters).');
            $stems[]=$stem;
        }
        $families=['liv','onlinn','us','ustv','online','tv','watch','stream','live','hd','onair','cast'];
        $slots=[];$semantic=[];
        for($i=0;$i<$count;$i++){$slots[]=$i%count($stems);$semantic[]=$i<(int)round($count*.4);}
        shuffle($slots);shuffle($semantic);$out=[];$used=array_fill_keys($excludedIds,true);$usedSuffixes=[];
        for($i=0;$i<$count;$i++){
            do{
                if($semantic[$i]){$suffix=$families[random_int(0,count($families)-1)];if(random_int(0,1))$suffix.=random_int(1,max(99,$count*5));}
                else{$suffix='';for($j=0,$length=random_int(3,8);$j<$length;$j++)$suffix.=chr(random_int(97,122));}
                $id=$stems[$slots[$i]].'-'.$suffix;
            }while(isset($used[$id])||isset($usedSuffixes[$suffix]));
            $out[]=$id;$used[$id]=true;$usedSuffixes[$suffix]=true;
        }
        return $out;
    }
    public static function eventUrl(string $source,string $url): string {
        if(!filter_var($url,FILTER_VALIDATE_URL)||!in_array(strtolower(parse_url($url,PHP_URL_SCHEME)?:''),['https','http'],true)||parse_url($url,PHP_URL_USER)!==null||preg_match('/[\x00-\x20]/',$url))throw new InvalidArgumentException('Enter a complete http:// or https:// Event URL.');
        $literal=json_encode($url,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
        $changed=0;
        $result=preg_replace_callback('~<!--[\s\S]*?-->|<script\b[^>]*>[\s\S]*?</script\s*>~i',function($script)use($literal,&$changed){
            if(!preg_match('~^<script\b~i',$script[0]))return $script[0];
            $condition='~(if\s*\(\s*!\s*navigator\.userAgent\.includes\(\s*["\x27]Googlebot["\x27]\s*\)\s*\)\s*\{)([^}]*)(\})~s';
            return preg_replace_callback($condition,function($block)use($literal,&$changed){
                $pattern='~(\b(?:window\.)?location\.href\s*=\s*)(?:"(?:\\\\.|[^"\\\\])*"|\x27(?:\\\\.|[^\x27\\\\])*\x27)~s';
                $body=preg_replace_callback($pattern,fn($m)=>$m[1].$literal,$block[2],1,$n);$changed+=$n;
                return $block[1].$body.$block[3];
            },$script[0]);
        },$source);
        if($changed!==1)throw new InvalidArgumentException('The template must contain exactly one Googlebot bypass redirect using navigator.userAgent.includes and location.href. Upload a matching template in Settings.');
        return $result;
    }
    public static function descriptionWords(string $description):int {
        return count(preg_split('/\s+/u',trim($description),-1,PREG_SPLIT_NO_EMPTY));
    }
    public static function validateDescription(string $description):string {
        $description=trim($description);
        if($description===''||self::descriptionWords($description)>100||mb_strlen($description)>10000||preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f]/u',$description))throw new InvalidArgumentException('Description must be plain text with 1–100 words. Regenerate to try again.');
        return $description;
    }
    public static function metadata(string $source,string $title,?string $description): string {
        $escape=fn($v)=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8');
        // Tokenize source without DOM reserialization; skip raw text and comments.
        $pattern='~<!--[\s\S]*?-->|<(script|style|textarea|xmp)\b[^>]*>[\s\S]*?</\1\s*>|<title\b[^>]*>[\s\S]*?</title\s*>|<meta\b(?:[^>"\x27]|"[^"]*"|\x27[^\x27]*\x27)*?\bog:description["\x27]\s*/?>|<meta\b(?:[^>"\x27]|"[^"]*"|\x27[^\x27]*\x27)*>~i';
        preg_match('~<title\b[^>]*>([\s\S]*?)</title\s*>~i',$source,$old);
        $oldTitle=trim(html_entity_decode($old[1]??'',ENT_QUOTES|ENT_HTML5,'UTF-8'));
        return preg_replace_callback($pattern,function($m)use($title,$description,$escape,$oldTitle){
            $tag=$m[0];
            if(preg_match('~^(<title\b[^>]*>)([\s\S]*?)(</title\s*>)$~i',$tag,$t)){
                preg_match('/^\s*/',$t[2],$lead);preg_match('/\s*$/',$t[2],$trail);
                return $t[1].$lead[0].$escape($title).$trail[0].$t[3];
            }
            if(!preg_match('~^<meta\b~i',$tag))return $tag;
            $kind=null;
            if(preg_match('~\b(?:name|property)\s*=\s*(["\x27])\s*(.*?)\s*\1~is',$tag,$a)){
                $v=strtolower(trim($a[2]));
                if(in_array($v,['title','og:title','twitter:title','x:title'],true))$kind='title';
                if(in_array($v,['description','og:description','twitter:description','x:description'],true))$kind='description';
            }elseif(preg_match('~\bog:description["\x27]~i',$tag))$kind='description';
            elseif($oldTitle!==''&&preg_match('~\bcontent\s*=\s*(["\x27])(.*?)\1~is',$tag,$a)){
                $v=html_entity_decode($a[2],ENT_QUOTES|ENT_HTML5,'UTF-8');
                // Source template contains unnamed description meta tags; identify by exact old-title prefix.
                if((str_starts_with($v,$oldTitle.',')&&strlen($v)>strlen($oldTitle)+50)||preg_match('~^<meta\s+content\s*=\s*(["\x27])[\s\S]*?\1\s*/?>$~i',$tag))$kind='description';
            }
            if(!$kind||($kind==='description'&&$description===null))return $tag;
            return preg_replace_callback('~(\bcontent\s*=\s*)(["\x27])(.*?)\2~is',fn($a)=>$a[1].$a[2].$escape($kind==='title'?$title:$description).$a[2],$tag,1);
        },$source);
    }
    public static function urlDomain(string $domain):string {
        $domain=trim($domain);if($domain==='')return '';
        if(!preg_match('~^[a-z][a-z0-9+.-]*://~i',$domain))$domain='https://'.$domain;
        $parts=parse_url($domain);
        if(strlen($domain)>2048||!filter_var($domain,FILTER_VALIDATE_URL)||!is_array($parts)||!in_array(strtolower($parts['scheme']??''),['https','http'],true)||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||preg_match('/[\x00-\x20\x7f]/',$domain))throw new InvalidArgumentException('Enter a valid domain or http(s) URL without a query string, fragment or login details.');
        return $domain;
    }
    public static function urlDomains(string $text):array {
        if(strlen($text)>2*1024*1024)throw new InvalidArgumentException('Domain list exceeds 2 MB.');
        $domains=[];
        foreach(preg_split('/\R/u',$text)?:[] as $line){$domain=self::urlDomain($line);if($domain!=='')$domains[$domain]=true;}
        if(count($domains)>1000)throw new InvalidArgumentException('Use up to 1,000 domains, one per line.');
        return array_keys($domains);
    }
    public static function package(string $dir,string $template,array $rows,array $descriptions,string $base64,string|array $domain='',array $options=[],bool $randomizeDomains=false): void {
        $domains=self::urlDomains(is_array($domain)?implode("\n",$domain):$domain);if(!$domains)$domains=[''];
        if(count($rows)!==count($descriptions))throw new RuntimeException('Descriptions are incomplete.');
        // Fix a base time once; the optional spread derives each file from it.
        if($options)$options['generated_at']=$options['generated_at']??(new DateTimeImmutable('now',new DateTimeZone('-05:00')))->format(DATE_ATOM);
        $zip=new ZipArchive;
        if($zip->open($dir.'/html.zip.tmp',ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Unable to create ZIP. Check storage permissions.');
        $urls=[];$parts=[];$keepKeywordOrder=!empty($options['preserve_keyword_order'])||!empty($options['spread_schema_time']);$domainCycle=$domains;
        try{foreach($rows as $i=>$row){
            $part=$dir.'/part-'.$i.'.html.tmp';$parts[]=$part;
            $fileOptions=$options;if(array_key_exists('broadcast_enabled',$row))$fileOptions['broadcast_enabled']=($options['broadcast_enabled']??true)&&$row['broadcast_enabled'];if(!empty($options['spread_schema_time']))$fileOptions['schema_timestamp']=SchemaTime::spreadTimestamp($options['schema_timestamp']??$options['generated_at'],count($urls),count($rows),(int)($options['spread_seconds']??3600));
            if(file_put_contents($part,($options?TemplateRenderer::render($template,$row['title'],$descriptions[$i],$fileOptions,array_column($rows,'title')):self::metadata($template,$row['title'],$descriptions[$i])))===false||!$zip->addFile($part,$row['id'].'.html'))throw new RuntimeException('Unable to write HTML ZIP.');
            $domainIndex=$randomizeDomains?count($urls):intdiv(count($urls),5);
            if($randomizeDomains&&$keepKeywordOrder&&$i%count($domains)===0){$domainCycle=$domains;shuffle($domainCycle);}
            $selectedDomain=($randomizeDomains&&$keepKeywordOrder?$domainCycle:$domains)[$domainIndex%count($domains)];
            $urls[]=$selectedDomain.'?&xml=data:video/mp4;base64,'.$base64.'&id='.$row['id'];
        }}finally{$closed=$zip->close();foreach($parts as $part)@unlink($part);}
        if(!$closed)throw new RuntimeException('Unable to finalize HTML ZIP. Check free disk space.');
        $groups=array_chunk($urls,$randomizeDomains?count($domains):5);
        if($randomizeDomains&&count($domains)>1&&!$keepKeywordOrder){
            // Each round has one URL per domain; shuffle URLs within that round.
            foreach($groups as &$round){
                for($i=count($round)-1;$i>0;$i--){$j=random_int(0,$i);[$round[$i],$round[$j]]=[$round[$j],$round[$i]];}
            }
            unset($round);
        }
        $urlText=count($domains)>1?implode("\n\n\n\n",array_map(fn($group)=>implode("\n",$group),$groups)):implode("\n",$urls);
        if(file_put_contents($dir.'/urls.txt.tmp',$urlText."\n")===false)throw new RuntimeException('Unable to write URL file.');
        if(!rename($dir.'/html.zip.tmp',$dir.'/html.zip')||!rename($dir.'/urls.txt.tmp',$dir.'/urls.txt'))throw new RuntimeException('Unable to finish downloads.');
    }
}
