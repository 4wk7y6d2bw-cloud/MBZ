<?php
// Podróże: cena zależy od bieżącego respektu i odległości w linii prostej.
$travelCities = [
 'Warszawa'=>[52.2297,21.0122], 'Kraków'=>[50.0647,19.9450],
 'Wrocław'=>[51.1079,17.0385], 'Łódź'=>[51.7592,19.4560],
 'Poznań'=>[52.4064,16.9252], 'Gdańsk'=>[54.3520,18.6466],
 'Szczecin'=>[53.4285,14.5528], 'Rzeszów'=>[50.0412,21.9991],
 'Katowice'=>[50.2649,19.0238], 'Bydgoszcz'=>[53.1235,18.0084],
 'Olsztyn'=>[53.7784,20.4801], 'Białystok'=>[53.1325,23.1688],
 'Lublin'=>[51.2465,22.5684], 'Kielce'=>[50.8661,20.6286],
];
// Każdy gracz zaczyna z odblokowanym wyłącznie swoim miastem.
$db->exec('CREATE TABLE IF NOT EXISTS player_unlocked_cities (
 user_id INT NOT NULL,
 city VARCHAR(64) NOT NULL,
 unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (user_id,city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$seedCity=$db->prepare('INSERT IGNORE INTO player_unlocked_cities(user_id,city) VALUES (?,?)');
$seedCity->execute([(int)$user['id'],(string)$stats['current_city']]);
$cityAccessQuery=$db->prepare('SELECT city FROM player_unlocked_cities WHERE user_id=?');
$cityAccessQuery->execute([(int)$user['id']]);
$unlockedCities=array_fill_keys($cityAccessQuery->fetchAll(PDO::FETCH_COLUMN),true);
function travel_distance(array $from, array $to): int {
 $lat1=deg2rad($from[0]); $lat2=deg2rad($to[0]);
 $dlat=$lat2-$lat1; $dlon=deg2rad($to[1]-$from[1]);
 $a=sin($dlat/2)**2+cos($lat1)*cos($lat2)*sin($dlon/2)**2;
 return (int)round(6371*2*atan2(sqrt($a),sqrt(max(0,1-$a))));
}
function travel_extra_rate(int $km): int {
 if ($km<100) return 0;
 if ($km<200) return 1;
 if ($km<300) return 2;
 if ($km<400) return 3;
 if ($km<500) return 4;
 return 5;
}
function travel_quote(array $cities,string $from,string $to,int $respect): ?array {
 if (!isset($cities[$from],$cities[$to]) || $from===$to) return null;
 $km=travel_distance($cities[$from],$cities[$to]);
 $extra=travel_extra_rate($km);
 // Dystans podnosi cenę płynnie, a minimalna dopłata różnicuje także początkujących graczy.
 $distanceFee=max((int)ceil($km/65)*3,(int)ceil(max(0,$respect)*$km/10000));
 return ['km'=>$km,'extra'=>$extra,'rate'=>5+$extra,
   'price'=>max(1,(int)ceil(max(0,$respect)*0.05)+$distanceFee),
   'seconds'=>(int)round(min(1200,300+(int)round($km*1.5))*0.7*0.8)];
}
$db->exec('CREATE TABLE IF NOT EXISTS player_travel (
 user_id INT NOT NULL PRIMARY KEY,
 destination VARCHAR(64) NOT NULL,
 arrives_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$travelMessage='';
$travelStmt=$db->prepare('SELECT destination,arrives_at FROM player_travel WHERE user_id=?');
$travelStmt->execute([(int)$user['id']]);
$activeTravel=$travelStmt->fetch() ?: null;
if ($activeTravel && strtotime($activeTravel['arrives_at'])<=time()) {
 $db->beginTransaction();
 try {
  $lock=$db->prepare('SELECT destination,arrives_at FROM player_travel WHERE user_id=? FOR UPDATE');
  $lock->execute([(int)$user['id']]); $arriving=$lock->fetch();
  if ($arriving && strtotime($arriving['arrives_at'])<=time() && isset($travelCities[$arriving['destination']])) {
   $update=$db->prepare('UPDATE player_stats SET current_city=? WHERE user_id=?');
   $update->execute([$arriving['destination'],(int)$user['id']]);
   $delete=$db->prepare('DELETE FROM player_travel WHERE user_id=?');
   $delete->execute([(int)$user['id']]);
   $stats['current_city']=$arriving['destination'];
  }
  $db->commit();
 } catch (Throwable $e) {if($db->inTransaction())$db->rollBack();throw $e;}
 $travelStmt->execute([(int)$user['id']]);
 $activeTravel=$travelStmt->fetch() ?: null;
}
if ($showTravel && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='start_travel') {
 if (!csrf_valid($_POST['csrf_token']??null)) $travelMessage='Sesja wygasła. Odśwież stronę.';
 elseif ($activeTravel) $travelMessage='Jesteś już w podróży.';
 elseif ((int)($gameState['is_break']??0)===1) $travelMessage='Podróże są niedostępne podczas przerwy między sezonami.';
 else {
  $destination=is_string($_POST['destination']??null)?$_POST['destination']:'';
  $db->beginTransaction();
  try {
   $lock=$db->prepare('SELECT cash,respect,current_city FROM player_stats WHERE user_id=? FOR UPDATE');
   $lock->execute([(int)$user['id']]); $fresh=$lock->fetch();
   $quote=$fresh?travel_quote($travelCities,(string)$fresh['current_city'],$destination,(int)$fresh['respect']):null;
   $existing=$db->prepare('SELECT 1 FROM player_travel WHERE user_id=? FOR UPDATE');
   $existing->execute([(int)$user['id']]);
   if (!$quote) $travelMessage='Wybierz inne dostępne miasto.';
   elseif (!isset($unlockedCities[$destination])) $travelMessage='Najpierw odblokuj to miasto w misjach.';
   elseif ($existing->fetchColumn()) $travelMessage='Jesteś już w podróży.';
   elseif ((int)$fresh['cash']<$quote['price']) $travelMessage='Nie masz wystarczająco pieniędzy.';
   else {
    $pay=$db->prepare('UPDATE player_stats SET cash=cash-? WHERE user_id=? AND cash>=?');
    $pay->execute([$quote['price'],(int)$user['id'],$quote['price']]);
    if ($pay->rowCount()!==1) $travelMessage='Nie udało się pobrać opłaty.';
    else {
     $insert=$db->prepare('INSERT INTO player_travel(user_id,destination,arrives_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL ? SECOND))');
     $insert->execute([(int)$user['id'],$destination,$quote['seconds']]);
     $travelMessage='Podróż rozpoczęta.';
     $stats['cash']=(int)$fresh['cash']-$quote['price'];
    }
   }
   $db->commit();
  } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  $travelStmt->execute([(int)$user['id']]);
  $activeTravel=$travelStmt->fetch() ?: null;
 }
}
