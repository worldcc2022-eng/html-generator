<?php
require __DIR__.'/../app/Generator.php';
require __DIR__.'/../app/TitleBank.php';
require __DIR__.'/../app/IdBank.php';
function verifyKeyword($actual,$expected,$label){if($actual!==$expected)throw new RuntimeException($label."\nExpected: $expected\nActual: $actual");echo "PASS $label\n";}
$d=new DateTimeImmutable('2026-11-02');
verifyKeyword(TitleBank::render('{event_name} Free Stream Online','Chelsea vs Arsenal Free',$d),'Chelsea vs Arsenal Free Stream Online','keep input Free and skip only matching template Free');
verifyKeyword(IdBank::render('video-free-{event_name}-stream-online','Chelsea vs Arsenal Free',$d),'video-chelsea-vs-arsenal-free-stream-online','skip template ID Free before inserting original event slug');
verifyKeyword(TitleBank::render('[ＦＲＥＥ] {event_name} 𝐒𝐓𝐑𝐄𝐀𝐌 Online LIVE','NFL Free Stream',$d),'NFL Free Stream Online LIVE','styled duplicate words removed without changing other Unicode');
verifyKeyword(TitleBank::render('{event_name} FREE STREAM online live','NFL free stream online live',$d),'NFL free stream online live','multiple keywords matched ignoring case');
verifyKeyword(IdBank::render('video-{event_name}-free-stream-online-live','NFL Free Stream Online Live',$d),'video-nfl-free-stream-online-live','multiple ID duplicates removed with clean separators');
verifyKeyword(TitleBank::render('{event_name} Free Freeport Streamer Online','Freeport Streamers',$d),'Freeport Streamers Free Freeport Streamer Online','whole word matches do not strip parts of names');
verifyKeyword(TitleBank::render('[𝐋𝐈𝐕𝐄] {event_name} Free','PSG vs Barcelona',$d),'[𝐋𝐈𝐕𝐄] PSG vs Barcelona Free','no relevant keyword keeps original template');
verifyKeyword(TitleBank::render('{event_name} Free {date}','NFL Free 2026',$d),'NFL Free 2026 2 November','existing date-year behavior preserved');
verifyKeyword(TitleBank::render('{event_name} FREE Online','NFL Ｆｒｅｅ',$d),'NFL Ｆｒｅｅ Online','styled event word remains intact');
verifyKeyword(IdBank::render('video-watch-{event_name}-watch-onlin','NFL Watch',$d),'video-nfl-watch-onlin','watch duplicate removed while different spelling remains');
echo "ALL KEYWORD TEMPLATE TESTS PASSED\n";
