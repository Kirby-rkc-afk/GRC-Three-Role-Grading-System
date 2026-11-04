<?php
declare(strict_types=1);
/** Strict local reader for the supplied one-page, unencrypted Tracker jsPDF layout. */
function cbDuration(string $text): float {
    $text=trim($text);
    if(is_numeric($text))return cbNumber($text,1000000)*60;
    if(preg_match('/^(\d+):([0-5]\d)(?::([0-5]\d))?$/',$text,$m))return (float)$m[1]*3600+(float)$m[2]*60+(float)($m[3]??0);
    if(preg_match('/^(?:(\d+)\s*(?:h|hr|hours?)\s*)?(?:(\d+)\s*(?:min|mins|minutes?)\s*)?(?:(\d+)\s*(?:s|sec|seconds?))?$/i',$text,$m)&&strlen(preg_replace('/\D/','',$text))>0)return (float)($m[1]??0)*3600+(float)($m[2]??0)*60+(float)($m[3]??0);
    throw new InvalidArgumentException('Unsupported duration value. Use minutes, hours:minutes:seconds, or “12 min 49s”.');
}
function cbPdfLiteral(string $text,int $start): array {
    $out='';$depth=1;$n=strlen($text);
    for($i=$start+1;$i<$n;$i++){
        $c=$text[$i];
        if($c==="\\"){
            $c=$text[++$i]??'';$esc=['n'=>"\n",'r'=>"\r",'t'=>"\t",'b'=>chr(8),'f'=>chr(12)];
            if(isset($esc[$c])){$out.=$esc[$c];continue;}
            if($c==="\n")continue;if($c==="\r"){if(($text[$i+1]??'')==="\n")$i++;continue;}
            if(preg_match('/^[0-7]$/',$c)){$oct=$c;while(strlen($oct)<3&&preg_match('/^[0-7]$/',$text[$i+1]??''))$oct.=$text[++$i];$out.=chr(octdec($oct));continue;}
            $out.=$c;continue;
        }
        if($c==='(')$depth++;
        if($c===')'&&--$depth===0)return [iconv('Windows-1252','UTF-8',$out),$i+1];
        $out.=$c;
    }
    throw new InvalidArgumentException('Incomplete PDF text string.');
}
function cbReadTrackerPdf(string $bytes): array {
    if(strlen($bytes)>4*1024*1024||!str_starts_with($bytes,'%PDF-')||!str_contains($bytes,'jsPDF')||str_contains($bytes,'/Encrypt')||str_contains($bytes,'/ToUnicode')||preg_match_all('~/Type\s*/Page\b~',$bytes)!==1)throw new InvalidArgumentException('Only the supplied one-page, unencrypted text-based Tracker jsPDF layout is supported. Scanned or other PDFs need a CSV export.');
    $tokens=[];$offset=0;
    while(preg_match('/\bstream\r?\n/',$bytes,$m,PREG_OFFSET_CAPTURE,$offset)){
        $pos=$m[0][1];$bodyStart=$pos+strlen($m[0][0]);$prefix=substr($bytes,0,$pos);$obj=strrpos($prefix,'obj');$dict=substr($bytes,$obj===false?0:$obj+3,$pos-($obj===false?0:$obj+3));
        if(!preg_match('~/Length\s+(\d+)~',$dict,$length))throw new InvalidArgumentException('Unsupported PDF stream.');
        $offset=$bodyStart+(int)$length[1];if($offset>strlen($bytes))throw new InvalidArgumentException('Truncated PDF.');if(str_contains($dict,'/Filter'))continue;
        $body=substr($bytes,$bodyStart,(int)$length[1]);preg_match_all('/\bBT\b(.*?)\bET\b/s',$body,$blocks);
        foreach($blocks[1] as $block){if(!preg_match('/\bTf\b/',$block))continue;for($i=0;$i<strlen($block);$i++){if($block[$i]!=='(')continue;[$text,$end]=cbPdfLiteral($block,$i);if(preg_match('/^\s*Tj\b/',substr($block,$end)))$tokens[]=trim($text);$i=$end-1;}}
    }
    $index=static fn($label)=>array_search($label,$tokens,true);
    $marker=$index("Participant's List");if(($tokens[0]??'')!=='Meet Attendance Tracker'||$marker===false)throw new InvalidArgumentException('The report headings do not match the supported PDF layout.');
    $headers=['S.No','Participant name','First Seen At','Attended duration','Attended percentage'];if(array_slice($tokens,$marker+1,5)!==$headers)throw new InvalidArgumentException('Unsupported report table headings.');
    foreach(['Total Participants','Meeting Duration','Meeting Date','Meeting Name','Report Accuracy'] as $label)if($index($label)===false)throw new InvalidArgumentException('Incomplete report summary.');
    $count=0;foreach(array_slice($tokens,$index('Total Participants')+1,4) as $v)if(ctype_digit($v)){$count=(int)$v;break;}
    if($count<1||$count>500)throw new InvalidArgumentException('Invalid participant count.');$start=$marker+6;
    if(count($tokens)!==$start+$count*5)throw new InvalidArgumentException('The entire participant list must parse successfully. No partial import.');
    $duration=cbDuration($tokens[$index('Meeting Duration')+3]);$date=DateTimeImmutable::createFromFormat('!d-M-Y',$tokens[$index('Meeting Date')+3]);if(!$date)throw new InvalidArgumentException('Invalid report date.');$rows=[];
    for($i=0;$i<$count;$i++){$r=array_slice($tokens,$start+$i*5,5);if((int)$r[0]!==$i+1||!preg_match('/^\d{1,2}:\d{2}:\d{2} [AP]M$/',$r[2])||!preg_match('/^\d{1,3}%$/',$r[4]))throw new InvalidArgumentException('A participant row could not be read.');$seconds=cbDuration($r[3]);if($seconds>$duration)throw new InvalidArgumentException('A participant duration exceeds the meeting duration.');$rows[]=['identity'=>$r[1],'kind'=>'name','seconds'=>$seconds,'first_seen'=>$r[2],'reported_percentage'=>$r[4]];}
    return ['rows'=>$rows,'date'=>$date->format('Y-m-d'),'meeting'=>$tokens[$index('Meeting Name')+3],'duration_seconds'=>$duration,'accuracy'=>$tokens[$index('Report Accuracy')+1],'format'=>'tracker_pdf'];
}
function cbReadReport(string $bytes,string $filename): array {
    if(strtolower(pathinfo($filename,PATHINFO_EXTENSION))==='pdf')return cbReadTrackerPdf($bytes);
    if(strtolower(pathinfo($filename,PATHINFO_EXTENSION))!=='csv'||strlen($bytes)>1024*1024||!mb_check_encoding($bytes,'UTF-8'))throw new InvalidArgumentException('Choose a UTF-8 CSV (up to 1 MB) or the supported Tracker PDF (up to 4 MB).');
    $stream=fopen('php://temp','r+');fwrite($stream,preg_replace('/^\xEF\xBB\xBF/','',$bytes));rewind($stream);$headers=fgetcsv($stream,0,',','"','');
    if(!$headers)throw new InvalidArgumentException('CSV column headings are required.');$headers=array_map(fn($h)=>trim(preg_replace('/[^a-z0-9]+/','_',strtolower(trim($h))),'_'),$headers);
    if(count($headers)!==count(array_unique($headers)))throw new InvalidArgumentException('CSV headings must be unique.');
    $identity=null;$kind='';foreach(['student_no'=>'number','student_number'=>'number','email'=>'email','participant_name'=>'name','name'=>'name'] as $h=>$k)if(($at=array_search($h,$headers,true))!==false){$identity=$at;$kind=$k;break;}
    $duration=null;$unit='';foreach(['duration_seconds'=>'seconds','duration_minutes'=>'minutes','duration'=>'text','attended_duration'=>'text'] as $h=>$u)if(($at=array_search($h,$headers,true))!==false){$duration=$at;$unit=$u;break;}
    if($identity===null||$duration===null)throw new InvalidArgumentException('CSV needs student_no, email or participant_name, plus duration_minutes, duration_seconds or attended_duration.');$rows=[];
    while(($r=fgetcsv($stream,0,',','"',''))!==false){if(count($r)===1&&trim((string)$r[0])==='')continue;if(count($r)!==count($headers))throw new InvalidArgumentException('CSV row column count mismatch.');if(count($rows)>=400)throw new InvalidArgumentException('Import limit: 400 rows.');$id=cbText($r[$identity],255);$seconds=$unit==='seconds'?cbNumber($r[$duration],1000000):cbDuration($r[$duration]);$rows[]=['identity'=>$id,'kind'=>$kind,'seconds'=>$seconds];}fclose($stream);
    if(!$rows)throw new InvalidArgumentException('The attendance report has no participant rows.');return ['rows'=>$rows,'format'=>'csv','accuracy'=>null];
}
