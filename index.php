<?php
//*---------------УСТАНОВКИ---------------*/

//корневая папка скрипта
define('SCR_DIR', preg_replace('/\\\/', '/', __DIR__).'/');

//загрузка установок:
// - файл перечня телеканалов;
// - настройки сервера телепрограмм;
// - шаблоны обработки и верстки телепрограмм;
// - объекты вывода результатов
include(SCR_DIR.'settings.php');

/*---------------МОДЕЛЬ-----------------*/
class Model
{
    //Дни недели, следующей после отпечатка времени, стирание старых телепрограмм
    
    static function _get_dates($day_prev_week, $month_names, $prog_dir)
    {
        $timestamp = $day_prev_week; //текущее время
        $date_time_array = getdate($timestamp);// в виде массива
        
        //определим текущий день недели
        $d_week = date('w', time()); //день недели
        
        //сколько добавить до следующего понедельника, второй случай - для воскресенья ($d_week=0) +1 день
        $to_first_day=($d_week)?(8-$d_week):(1);
        
        //называем даты дней следующей недели
        for($i=1; $i<=7; $i++)
        {
            //Добавляем дни до следующего понедельника + до нужного дня недели
            $day_step=$to_first_day+$i-1;
            $next_dates[$i] = mktime(
            $date_time_array['hours'],
            $date_time_array['minutes'],
            $date_time_array['seconds'],
            $date_time_array['mon'],
            $date_time_array['mday']+$day_step,
            $date_time_array['year']
            );
            
            //стираем устаревшие телепрограммы,
            //сохраняем дату первого дня (понедельника загружаемых телепрограмм)
            if($i==1){
                $pograms_monday_date = (file_exists(SCR_DIR.'temp/programs_moday_date.txt')) ?
                    (file_get_contents(SCR_DIR.'temp/programs_moday_date.txt')):(false);
                if($pograms_monday_date!=date('Ymd', $next_dates[$i]))
                {
                    //автоматическое создание папки для файлов телепрограмм
                    if(!is_dir(SCR_DIR.$prog_dir))mkdir(SCR_DIR.$prog_dir);
                    
                    //удаляем устаревшие телепрограммы
                    $clear_dir = opendir(SCR_DIR.$prog_dir);
                    while ($obj = readdir($clear_dir)){ 
                        if ($obj != "." && $obj != "..") unlink (SCR_DIR.$prog_dir.$obj); 
                    }
                    closedir($clear_dir);
                    
                    //автоматическое создание папки для файлов телепрограмм
                    if(!is_dir(SCR_DIR.'temp/'))mkdir(SCR_DIR.'temp/');
                    
                    //записываем новую дату начала телепрограмм
                    if(!is_dir(SCR_DIR.$prog_dir))mkdir(SCR_DIR.$prog_dir);
                    file_put_contents(SCR_DIR.'temp/programs_moday_date.txt', date('Ymd', $next_dates[$i]));
                } 
            }
            
            $dates[$i] = date('d m', $next_dates[$i]);
            
            //убираем лишние ноли, предусмотренне форматами d и m
            $dates[$i]=preg_replace('/(0(\d)|(\d\d)) (0(\d)|(\d\d))/', '$2$3 $5$6', $dates[$i]);
                
            //номер месяца
            preg_match('/ (\d|\d\d)$/', $dates[$i], $month);
            
            //переводим номер месяца в его название
            $dates[$i] = preg_replace('/ '.$month[1].'$/', ' '.$month_names[$month[1]], $dates[$i]);
        }
        
        return $dates;
    }
    
    //Вытяжка списка каналов из файла настроек телеканалов
    static function _get_list($set_file)
    {
        //вытяжка каналов и названий их файлов
        $content=file_get_contents($set_file);
        if(!preg_match_all('/(^|\n)([^$!]{1,})(?= ! ) ! ([^_]{1,})_([^\n]{1,})(\r\n|$)/', $content, $export_set))
            return array('error'=>'Ошибка распознавания файла настроек файлов телепрограмм - channels.txt');
        
        //удобная форма
        $ch_n_files['channel']=View::_channels_view($export_set[2]);
        $ch_n_files['archid']=$export_set[3];
        $ch_n_files['file']=$export_set[4];
        
        //die(print_r($export_set));
        return $ch_n_files;
    }
    
    //СЕРВЕР ТЕЛЕПРОГРАММ
    //Авторизация
    
    static function _tvserver_auth($tv_server)
    {
        //инициализация curl-запроса авторизации, настройки
        $ch = curl_init($tv_server['auth_url']);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_REFERER, $tv_server['auth_ref']);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_ENCODING, "");
        curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 120);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, "opera");
        curl_setopt($ch, CURLOPT_COOKIEJAR, SCR_DIR.'temp/cookie.txt');
        curl_setopt($ch, CURLOPT_POSTFIELDS, "lg=".$tv_server['login']."&ps=".$tv_server['pass']);
        
        $result=curl_exec($ch);
        curl_close($ch);

        //проверяем успешность авторизации через перенаправление в клиентский раздел
        if(preg_match('/system\.tvinfo\.lg\.ua\/private\.php\?mode=all/', $result)) return false;

        return true;
    }
    
    //Загрузка архивов с сервера, вытяжка текстовых файлов телепрограмм
    
    static function _get_prog($ch_n_files, $tv_server, $prog_dir)
    {
        //для каждого телеканала
        for($i=0; $i<count($ch_n_files['file']); $i++)
        {
            //если не загружен архив телепрограммы и не разархивирован файл телепрограммы
            if(!file_exists(SCR_DIR.$prog_dir.$ch_n_files['file'][$i].'.txt'))
            {
                //временный файл сохранения архива телепрограммы канала
                $fp=fopen(SCR_DIR.'temp/arh.zip', "w");
            
                //инициализация curl-запроса загрузки архива канала, настройки 
                $ch = curl_init (str_replace("FILE", $ch_n_files['archid'][$i], $tv_server['file_url']));
                
                curl_setopt ($ch, CURLOPT_FILE, $fp);
                curl_setopt ($ch, CURLOPT_REFERER, $tv_server['referrer_url']);
                curl_setopt($ch, CURLOPT_COOKIEFILE, SCR_DIR.'temp/cookie.txt');
                //curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
                curl_setopt($ch, CURLOPT_ENCODING, "");
                curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 120);
                curl_setopt($ch, CURLOPT_TIMEOUT, 120);
                curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                curl_setopt($ch, CURLOPT_USERAGENT, "opera");
                
                curl_exec ($ch);
                fclose ($fp);
                curl_close ($ch);
        
                //вытяжка телепрограммы из архива (если архив удачно загрузился и в нем есть нужная телепрограмма)
                //открытие архива
                $zip = new ZipArchive;
                $zip->open(SCR_DIR.'temp/arh.zip');
                //вытяжка файла телепрограммы из архива
                if(!$zip->extractTo(SCR_DIR.$prog_dir, $ch_n_files['file'][$i].'.txt'))
                    return array ('error'=>'<br>Ошибка загрузки архива телеканала '.$ch_n_files['channel'][$i].'
                        <br>- Скорее всего, телепрограмма на следующую неделю еще не появилась на сайте (либо появились еще не все каналы).
                        <br>- Возможно, на сайте было изменено название архива канала '.$ch_n_files['channel'][$i].', либо адрес его размещения
                            (в этом случае архив '.$ch_n_files['channel'][$i].' виден на сайте!)
                        <br>- Также возможно, было изменено название файла телепрограммы в архиве телеканала '.$ch_n_files['channel'][$i].'
                            (если архив есть на сайте - скачайте его и посмотрите, совпадает ли название файла телепрограммы канала с его названием в channels.txt)
                        <br><br><i><b> Во втором и третьем случаях обратитесь к разработчику, либо самостоятельно измените настройки канала '.$ch_n_files['channel'][$i].' в файле channels.txt</b></i>');
                $zip->close();
            }
            
            //вытяжка текста сохраненного файла телепрограммы 
            $all_ch[$i]=file_get_contents(SCR_DIR.$prog_dir.''.$ch_n_files['file'][$i].'.txt');
        }
        return $all_ch;
    }
    
    //Чистка телепрограмм
    
    static function _clean_ch($all_ch, $ch_list)
    {
        for($i=0; $i<count($ch_list['channel']); $i++)
        {
            //Убираем лишние скобки
            $all_ch[$i]=preg_replace('/\([^)]*\)/', '', $all_ch[$i]);
            
            //Убираем серии без скобок
            $all_ch[$i]=preg_replace('/(|\d{1,}-|\d{1,},( |)|- )\d{1,}( |)(серия|сс|(с|c)\.|часть)/', '', $all_ch[$i]);
            //Убираем специфические серии/части в конце строк
            $all_ch[$i]=preg_replace('/ \d{1,}\r\n/', "\r\n", $all_ch[$i]);
            
            //Убираем точки до переноса строк
            $all_ch[$i]=preg_replace('/\.\r\n/', "\n", $all_ch[$i]);
            //воскресенье - перенос строки
            $all_ch[$i]=preg_replace('/(?<=\n)$/', "\n", $all_ch[$i]);
            
            //УТ-1
            $all_ch[$i]=preg_replace('/(ПЕРВЫЙ НАЦИОНАЛЬНЫЙ(\. НОЧНОЙ КАНАЛ|)|ТРК "ЭРА")(\r\n){0,2}/i', '', $all_ch[$i]);
            
            
            //разбивка длинных строк
            $times = explode("\n", $all_ch[$i]);
            $all_ch[$i]='';
            global $delimiter;
            global $first_line;
            global $other_lines;
            for($j=0; $j<count($times); $j++){
                if(strlen($times[$j])<$first_line || preg_match('/^[^0-9]/', $times[$j])){$all_ch[$i].="\n".$times[$j];}
                else{
                    $local_result='';
                    $line=0;
                    while(strlen($times[$j])>$other_lines){
                        $is_first=($line)?(''):("\n");
                        $line=($line)?($other_lines):($first_line);
                        $space_pos=0;
                        for($k=0; $k<$line; $k++){
                            if($times[$j][$k]===' '){$space_pos=$k;}
                        }
                        if(!$space_pos){
                            $space_pos=strpos(' ', $times[$j]);
                            if(!$space_pos)$space_pos=strlen($times[$j]);
                        }
                        $add_delimiter=preg_replace('/^(.{'.$space_pos.'})(.*)/', "$2", $times[$j]);
                        //echo($add_delimiter.'->'.strlen($add_delimiter).'->');
                        $add_delimiter=(preg_match('/[A-zА-я0-9]+/', $add_delimiter))?($delimiter):('');
                        //echo($add_delimiter.'<br>');
                        $local_result.=$is_first.''.preg_replace('/^(.{'.$space_pos.'})(.*)/', "$1".$add_delimiter, $times[$j]);
                        $times[$j]=preg_replace('/^(.{'.$space_pos.'})(.*)/', "$2", $times[$j]);
                    }
                    if($times[$j]){$local_result.=$times[$j];}else{$local_result=preg_replace("/".$delimiter."$/", '', $local_result);}
                    $all_ch[$i].=$local_result;
                }
            }
            //die($all_ch[$i]);
        }
        return $all_ch;
    }
    
    //Поэлементная разбивка телепрограмм
    
    static function _arr_days_n_ch($days_borders, $all_ch, $ch_list)
    {
        for($day=1; $day<=7; $day++)
        {
            $nextday=intval($day)+1;
            for($chan=0; $chan<count($ch_list['channel']); $chan++)
            {
                //шаблон регулярки: Понедельник(текст без переноса строки)(перенос строки)(полезная инфа)(после этого должен быть Вторник, (для воскресенья - $))
                preg_match('/'.$days_borders[$day].'.{0,}\r\n([\s\S]{0,})(?='.$days_borders[$nextday].')/', $all_ch[$chan], $day_frag);
                
                //Двухмерный массив - дни и каналы
                $days_n_ch[$day][$chan]=preg_replace('/[ \n\r]+$/', '', $day_frag[1]);
                if(!isset($day_frag[1]))echo('Ошибка разбивки дня '.$days_borders[$day].', канал - '.$ch_list[$chan].'.
                    <br>Откройте файл канала и попробуйте исправить ошибку (возможно - отсутствует запятая после названия дня недели).');
                
                //В УТ-1 лишние переносы строк (оставленные после удаления надписей "ТРК ЭРА" и т.п.) проще всего убрать тут
                $days_n_ch[$day][$chan]=preg_replace('/\r\n\r\n/', "\r\n", $days_n_ch[$day][$chan]);
            }
        }
        
        return $days_n_ch;
    }
    
    //урезаем ночные телепередачи
    
    static function _good_night($days_n_ch)
    {
        for($day=1; $day<=7; $day++)
        {
            for($chan=0; $chan<count($days_n_ch[$day]); $chan++)
            {
                //Убираем телепередачи после 01.00
                $days_n_ch[$day][$chan]=preg_replace('/\n0(1|2|3|4)\.\d\d([\s\S]{0,})/', "\r\n\n", $days_n_ch[$day][$chan]);
            }
        }
        
        return $days_n_ch;
    }
}

/*---------------КОНТРОЛЛЕР-----------------*/

class Controller
{    
    //Обработка телепрограммы
    
    static function _process($set_file, $prog_dir, $days_borders, $month_names, $tv_server)
    {        
        //определяем даты дней на следующей неделе
        $dates=Model::_get_dates(time(), $month_names, $prog_dir);

        //достаем перечень телепрограмм
        $ch_list=Model::_get_list($set_file);
        if(isset($ch_list['error'])) die($ch_list['error']);
        
        //Авторизация на тв-сервере
        if(!Model::_tvserver_auth($tv_server)) die('Ошибка авторизации на сайте телепрограмм!');
        
        //загружаем телеканалы из файлов 
        $all_ch=Model::_get_prog($ch_list, $tv_server, $prog_dir);
        if(isset($all_ch['error'])) die($all_ch['error']);
        
        //чистка телепрограмм
        $all_ch=Model::_clean_ch($all_ch, $ch_list);
        
        //разбивка телепрограмм по дням
        $days_n_ch=Model::_arr_days_n_ch($days_borders, $all_ch, $ch_list);
        
        //убираем ночные телепередачи
        //$days_n_ch=Model::_good_night($days_n_ch);
        
        //верстка телепрограммы
        $content=View::makeup($days_borders, $ch_list['channel'], $dates, $days_n_ch);
        
        //обработка в html и вывод
        $content=View::to_html($content);
        
        return $content;
    }
    
    //вывод и сохранение сверстанной телепрограммы в файл
    
    static function _out_prog($teleprog, $file_tele)
    {
        //Запись телепрограммы в файл
        $to_write=fopen($file_tele, 'w');
        fwrite($to_write, $teleprog);
        fclose($to_write);
        
        echo($teleprog);
    }
}

/*---------------ПРЕДСТАВЛЕНИЕ-----------------*/

class View
{
    //Специфические отображения названий каналов
    
    static function _channels_view($name)
    {
        //Перенос строки в Первый канал - Украина
        return preg_replace('/ПЕРВЫЙ КАНАЛ\.УКРАИНА/', "ПЕРВЫЙ КАНАЛ.\r\nУКРАИНА", $name);
    }
    
    //Общая верстка телепрограммы
    
    static function makeup($days_names, $ch_names, $dates, $all_ch)
    {
        $content='';
        
        for($day=1; $day<=7; $day++)
        {
            //Название дня
            $content.=($content)?('<br><br>'):('');
            $content.="<b>".$days_names[$day].' '.strtolower($dates[$day])."</b>";
            
            $nextday=intval($day)+1;
            for($chan=0; $chan<count($ch_names); $chan++)
            {
                //название канала и программа на день
                $content.='<br><br><b>'.$ch_names[$chan].'</b>'.$all_ch[$day][$chan];
            }
        }
        
         return $content;
    }
    
    //Стили и перевод в html
    
    static function to_html($text)
    {
        //переносы строк
        $text = preg_replace('/\n/', "\n<br>", $text);

        //шрифт
        $text = '<div style="font-family: Arial;">'.$text.'</div>';
        
        return $text;
    }
    
    static function js()
    {
        //удаление канала из списка
        
        //добавить новый канал
        
        //перетаскивание канала (изменение порядка вывода)
        
        $script2='<script>'.$script2.'</script>';
        
        return $script2;
    }
}

$controller = new Controller();

$teleprog=$controller->_process($set_file, $prog_dir, $days_borders, $month_names, $tv_server);

$controller->_out_prog($teleprog, $file_tele);
?>