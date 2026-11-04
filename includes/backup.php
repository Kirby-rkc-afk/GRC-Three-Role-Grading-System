<?php
declare(strict_types=1);
const CB_BACKUP_TABLES=['professors','students','subjects','enrollments','grades','classbook_admins','classbook_access','classbook_account_meta','classbook_settings','classbook_members','classbook_criteria','classbook_activities','classbook_scores','classbook_meetings','classbook_attendance','classbook_imports','classbook_releases','classbook_audit','classbook_policies'];
function cbBackup(PDO $db): array {
    $db->beginTransaction();try{$tables=[];foreach(CB_BACKUP_TABLES as $table)$tables[$table]=$db->query("SELECT * FROM ".$table)->fetchAll(PDO::FETCH_ASSOC);$db->commit();}
    catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    return ['format'=>'grc-classbook-backup','version'=>2,'created_at'=>gmdate('c'),'tables'=>$tables];
}
function cbRestore(PDO $db,array $backup): void {
    if(($backup['format']??'')!=='grc-classbook-backup'||($backup['version']??0)!==2||!is_array($backup['tables']??null)||array_diff(array_keys($backup['tables']),CB_BACKUP_TABLES)||array_diff(CB_BACKUP_TABLES,array_keys($backup['tables'])))throw new RuntimeException('Unsupported or incomplete backup.');
    foreach(CB_BACKUP_TABLES as $table)if($db->query("SELECT COUNT(*) FROM ".$table)->fetchColumn())throw new RuntimeException('Restore requires an empty target database. Existing data will not be overwritten.');
    $db->beginTransaction();try{
        foreach(CB_BACKUP_TABLES as $table){$allowed=$db->query("SHOW COLUMNS FROM ".$table)->fetchAll(PDO::FETCH_COLUMN);$rows=$backup['tables'][$table];if(!is_array($rows))throw new RuntimeException('Invalid backup table.');
            foreach($rows as $row){if(!is_array($row)||!$row||array_diff(array_keys($row),$allowed))throw new RuntimeException('Invalid backup columns.');
                $cols=array_keys($row);$quoted=implode(',',array_map(fn($c)=>chr(96).$c.chr(96),$cols));cbQuery($db,"INSERT INTO $table ($quoted) VALUES (".implode(',',array_fill(0,count($cols),'?')).')',array_values($row));}
        }
        $db->commit();cbBackfillAccountIds($db);
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
