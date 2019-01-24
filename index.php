<?php
	setcookie("overlay", "on", time() + (28800), "/"); // 8 godzin
?>
<!DOCTYPE html>
<html lang="pl-PL">

<head>
<title>ConsultRisk Cluster</title>
<meta charset="UTF-8">
<meta name="description" content="Aplikacja internetowa do zarządzania klastrem">
<link rel="icon" type="image/png" sizes="32x32" href="files/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="files/favicon-16x16.png">
<link rel="stylesheet" href="files/styles.css">
</head>

<body onload="change_title();">
<div class="main-container">

<div class="header">
	<div class="logo">
		<a href="/"><img src="files/logo.svg" alt="ConsultRisk Cluster"></a>
	</div>
	<div class="send-file">
	<form action="?" method="post" enctype="multipart/form-data">
		<label for="file"><span>+</span> plik FDS</label>
		<input type="file" name="file" id="file" class="inputfile" required>

		<select name="directory" id="directory" onchange="choose_directory()">
			<option value="" selected disabled>zapisz do&hellip;</option>
			<?php
			$root = "/mnt/fds/";
			// próba utworzenia katalogu z bieżącym rokiem, jeśli nie istnieje
			if (!file_exists($root . date(Y) . DIRECTORY_SEPARATOR)) {
				mkdir($root . date(Y) . DIRECTORY_SEPARATOR, 0777, true);
			}
			// listuj w odwrotnym kierunku poprzez "array_reverse" - odwrócenie tablicy
			foreach (array_reverse(scandir($root)) as $subdirectory01) {
				if (is_dir($root . $subdirectory01) && is_numeric($subdirectory01)) {
					echo "<optgroup label=\"{$subdirectory01}\">";
					echo "<option value=\"\">&laquo;utwórz katalog&raquo;</option>";
					foreach (array_reverse(array_diff(scandir($root . $subdirectory01), array('..', '.'))) as $subdirectory02) {
						if (is_dir($root . $subdirectory01 . DIRECTORY_SEPARATOR . $subdirectory02)) {
							echo "<option value=\"" . $subdirectory01 . DIRECTORY_SEPARATOR . $subdirectory02 . DIRECTORY_SEPARATOR . "\">{$subdirectory02}</option>";
						}
					}
					echo "</optgroup>";
				}
			}
			?>
		</select>
		<input type="hidden" name="custom_directory" id="custom_directory" value="">
		<input type="button" value="&times;" onclick="reset_select();">
		<input type="submit" name="submit" value="wyślij"><br>
	</form>
	<p id="text_filename"></p>
	<p id="text_catalog"></p>
	</div>
</div> <!-- header -->

<div class="content">
<?php
$connection = ssh2_connect('localhost', 22);
ssh2_auth_password($connection, 'user', 'pass');
$stream = ssh2_exec($connection, 'qstat -f');
stream_set_blocking($stream, true);
$stream_out = ssh2_fetch_stream($stream, SSH2_STREAM_STDIO);
// pobrane dane
$tempQSTAT = stream_get_contents($stream_out);

// usunięcie łamania linii wraz z wcięciami
$tempQSTAT = preg_replace("/\x0A\x09/", "", $tempQSTAT);

// stworzenie tablicy jednowymiarowej ("explode") i usunięcie białych znaków ("array_map")
$tempQSTAT = array_map('trim',explode("\n", $tempQSTAT));

// stworzenie tablicy dwuwymiarowej z pożądanymi polami ("preg_grep")
$QSTAT = array();
array_push($QSTAT,
str_replace("Job Id: ", "", preg_grep('/Job Id: /', $tempQSTAT)),
str_replace("Job_Name = ", "", preg_grep('/Job_Name = /', $tempQSTAT)),
str_replace("job_state = ", "", preg_grep('/job_state = /', $tempQSTAT)),
str_replace("resources_used.cput = ", "", preg_grep('/resources_used.cput = /', $tempQSTAT)),
str_replace("resources_used.mem = ", "", preg_grep('/resources_used.mem = /', $tempQSTAT)),
str_replace("resources_used.vmem = ", "", preg_grep('/resources_used.vmem = /', $tempQSTAT)),
str_replace("resources_used.walltime = ", "", preg_grep('/resources_used.walltime = /', $tempQSTAT)),
str_replace("Resource_List.walltime = ", "", preg_grep('/Resource_List.walltime = /', $tempQSTAT)),
str_replace("start_time = ", "", preg_grep('/start_time = /', $tempQSTAT)),
str_replace("submit_args = ", "", preg_grep('/submit_args = /', $tempQSTAT)));
/*
Job_Name - NAZWA
hashname - ID
job_state - STAN
   C completed after having run
   E exiting after having run
   H held
   Q queued, eligible to run or routed
   R running
   T being moved to new location
   W waiting for its execution time (-a option) to be reached
   S suspended
exec_host - WĘZŁY/RDZENIE
resources_used.cput - CZAS UŻYCIA PROCESORÓW
resources_used.mem - PAMIĘĆ FIZYCZNA
resources_used.vmem - PAMIĘĆ WIRTUALNA
resources_used.walltime - CZAS OBLICZEŃ
Resource_List.walltime - CZAS DOSTĘPNY
start_time - START OBLICZEŃ
submit_args - ŚCIEŻKA DO PLIKU .PBS
*/

// formatowanie daty
foreach($QSTAT[8] as $key => $value) {
	$seconds_diff = time() - strtotime($value);
	$hours = floor($seconds_diff / 3600);
	$minutes = floor(($seconds_diff - $hours * 3600)/60);
	if ($minutes < 10) $minutes = "0" . $minutes;
	$QSTAT[8][$key] = date_format(date_create_from_format('D M j H:i:s Y', $value), 'Y-m-d H:i:s') . " ({$hours}:{$minutes} godz. temu)";
}

// znajdź plik .out i odczytaj postęp symulacji
foreach($QSTAT[9] as $key => $value) {
	// znajdź początek i koniec ścieżki do katalogu, w którym znajduje się plik .pbs
	$start = strpos($value, DIRECTORY_SEPARATOR);
	$end = strrpos($value, DIRECTORY_SEPARATOR);
	$path = substr($value, $start, $end - $start + 1);
	// znajdź pliki .out w katalogu z plikiem .pbs
	$file_content = file_get_contents(glob($path . "*.out")[0]); // jest tylko jeden plik .out
	// znajdź w pliku .out ostatni czas
	$start = strrpos($file_content, "Total Time:"); // znajdź pozycję ostatniego wystąpienia "Total Time:"
	$end = strpos($file_content, PHP_EOL, $start); // znajdź pozycję końca linii zawierającą wystąpienie ostatniego "Total Time:"
	$total_time = substr($file_content, $start + 11, $end - $start - 11); // liczba 11, bo chcę pominąć "Total Time:"
	$total_time = trim($total_time); // usuń białe znaki z początku i końca łańcucha znaków
	$QSTAT[9][$key] = $total_time;
}

// numerowanie kluczy od zera (array_map)
$QSTAT = array_map('array_values', $QSTAT);

// tworzy tabelę HTML z QSTAT[kolumna][wiersz]
function qstat_table($data = array())
{
	$rows = array();
	// count($data[0]) - liczba wystąpień id pomaga określić liczbę zadań
	for ($row = 0; $row < count($data[0]); $row++) {
		$cells = array();
		
		$cells[] = "<td>{$data[0][$row]}</td>";
		
		$cells[] = "<td>{$data[1][$row]}</td>";
		
		$cells[] = "<td>{$data[2][$row]}</td>";
		
		$cells[] = "<td>{$data[3][$row]}</td>";
		
		if (!empty($data[4][$row])) {
			$cells[] = "<td>{$data[4][$row]} / {$data[5][$row]}</td>";
		}
		else {
			$cells[] = "<td></td>";
		}
		
		$cells[] = "<td>{$data[8][$row]}</td>";
		
		$cells[] = "<td>{$data[9][$row]}</td>";

		if ($data[2][$row] != "C" && $data[2][$row] != "E") {
			$cells[] = "<td><a href=\"index.php?qdel={$data[0][$row]}\">&#x2715;</a></td>";
		}
		else {
			$cells[] = "<td></td>";
		}
		
		if ($data[2][$row] != "R" && $data[2][$row] != "C" && $data[2][$row] != "E") {
			$cells[] = "<td><a href=\"index.php?qrun={$data[0][$row]}\">&#x25B7;</a></td></tr>";
		}
		else {
			$cells[] = "<td></td>";
		}
	$rows[] = "<tr>" . implode('', $cells);
	}
	return "<div id=\"qstat\" class=\"datagrid\" style=\"display:block;\"><table>" .
	"<thead><tr><th>id</th><th>nazwa</th><th>stan</th><th>użyty czas CPU</th><th>pamięć fizyczna / wirtualna</th><th>start obliczeń</th><th>postęp</th><th>usuń</th><th>uruchom</th></tr></thead>" .
	implode('', $rows) .
	"</table></div>";
}

// pobranie i wstępne sformatowanie PBSNODES
$tempPBSNODES = array_map('trim',explode("\n", shell_exec('pbsnodes -a')));
// usunięcie ostatnich pustych wartości
if (empty(array_pop($tempPBSNODES))) array_pop($tempPBSNODES);

/*
busy - node is full and will not accept additional work
down - node is failing to report, is detecting local failures with node
free - node is ready to accept additional work
job-exclusive - available virtual processors are assigned to jobs
job-sharing - node has been allocated to run multiple shared jobs and will remain in this state until jobs are complete
offline - node has been instructed by an admin to no longer accept work
reserve - node has been reserved by the server
time-shared - node always allows multiple jobs to run concurrently
unknown - node has not been detected
*/

$PBSNODES = array();
$key = 0;
foreach ($tempPBSNODES as $value) {
	if (strpos($value, "=") === false && !empty($value)) $PBSNODES[$key]["name"] = $value;
	if (strpos($value, "state = ") !== false) $PBSNODES[$key]["state"] = str_replace("state = ", "", $value);
	if (strpos($value, "np = ") !== false) $PBSNODES[$key]["np"] = str_replace("np = ", "", $value);
	if (strpos($value, "jobs = ") !== false) $PBSNODES[$key]["jobs"] = str_replace("jobs = ", "", $value);
	// opisy węzłów są rozdzielane pustymi liniami
	if (empty($value)) $key++;
}

// status węzłów wyrażony za pomocą światełek
// PRZETESTOWANE TYLKO DLA TRZECH STANÓW: offline, free, job-exclusive
foreach ($PBSNODES as $key => $array) {
	$value = "";
	$usedNodes = substr_count($array["jobs"], "/"); //zlicza ilość użytych rdzeni w węźle
	for ($i=0; $i<$array["np"]; $i++) {
		if ($usedNodes > 0 && $array["state"] == "job-exclusive") {
			$value .= "<div class=\"square job-exclusive\"></div>";
			$usedNodes--;
		}
		else if ($array["state"] == "free") {
			$value .= "<div class=\"square free\"></div>";
		}
			// wszystko, co nie jest job-exclusive lub free
			else {
				$value .= "<div class=\"square\"></div>";
			}
	}
	$PBSNODES[$key]["light_panel"] = $value;
}

// tworzy tabelę HTML z PBSNODES
function pbsnodes_table($data = array())
{
	$rows = array();
	foreach ($data as $row) {
		$rows[] = "<tr><td>" . $row["name"] . "</td>" . "<td>" . $row["state"] . "</td>" . "<td>" . $row["light_panel"] . "</td>" . "<td>" . $row["jobs"] . "</td></tr>";
	}
	return "<div id=\"pbsnodes\" class=\"datagrid\" style=\"display:block;\"><table>" .
	"<thead><tr><th>nazwa</th><th>status</th><th></th><th>zadania</th></tr></thead>" .
	implode('', $rows) .
	"</table></div>";
}

?>

<p><a href="#/" onclick="toggle_visibility('qstat');">[+/-] bieżące zadania</a></p>
<?php echo qstat_table($QSTAT); ?>

<p><a href="#/" onclick="toggle_visibility('pbsnodes');">[+/-] węzły</a></p>
<?php echo pbsnodes_table($PBSNODES); ?>

<p><a href="#/" onclick="toggle_visibility('simulation');">[+/-] symulacje</a></p>
<div id="simulation" class="files_lev1 datagrid" style="display:none"><?php
// wyświetl listę wszystkich zapisanych symulacji
$root = '/mnt/fds/';
foreach (scandir($root) as $subdirectory01) {
	if (is_dir($root . $subdirectory01) && is_numeric($subdirectory01)) {
		echo $subdirectory01;
		echo "<div class=\"files_lev2\">";
		foreach (array_diff(scandir($root . $subdirectory01), array('..', '.')) as $subdirectory02) {
			if (is_dir($root . $subdirectory01 . DIRECTORY_SEPARATOR . $subdirectory02)) {
				echo $subdirectory02 . "<br>";
			}
		}
		echo "</div>";
	}
}
?></div>

<p><a href="#/" onclick="toggle_visibility('description');">[+/-] opis interfejsu</a></p>
<div id="description" style="display:none">
<ul>
	<li>Aby dodać nowe zadanie, kliknij <strong>+ plik FDS</strong>, po czym zaakceptuj wybór klikając na <strong>wyślij</strong>.</li>
	<li>Opcjonalnie można wskazać lub utworzyć własny katalog, w którym zostanie zapisany plik.</li>
	<li>W dymku na górze strony pojawiają się komunikaty.</li>
</ul>
</div>
</div> <!-- content -->

<div class="footer">
zgłaszanie błędów i sugestii: <strong>ul. Mickiewicza 63/303, 01-625 Warszawa</strong> | materiały filmowe: <a href="https://www.youtube.com/channel/UC3HVjj4zeORtYZE31AkQy1Q">CR</a>, <a href="https://www.youtube.com/channel/UCl49cCv2Machcy-MBC0-DMw">ED</a>, <a href="https://www.youtube.com/channel/UCDXnrv4PB6LCwMu9GBy7egA">FWEB</a>
</div> <!-- footer -->

<div class="mascot-move"><div class="mascot-play"></div></div>

<div class="notification">
<?php
// powitanie
switch($_SERVER['REMOTE_ADDR']) {
	case "192.168.0.23": echo "<p>Do usług, mój panie!</p>"; break;
	case "192.168.0.2": echo "<p>Widzę, że łączysz się z zewnątrz.</p>"; break;
	default: echo "<p>Łączysz się z adresu " . $_SERVER['REMOTE_ADDR'] . ".</p>"; break;
}

// śledź użytkownika: data + adres + useragent
file_put_contents('.logs', date("Y-m-d H:i:s") . PHP_EOL . $_SERVER['REMOTE_ADDR'] . PHP_EOL . $_SERVER['HTTP_USER_AGENT'] . PHP_EOL . PHP_EOL, FILE_APPEND | LOCK_EX);

// pokaż stan dysku
$cmd = "df | grep /mnt/fds | awk '{print $5}'";
$disk_usage = trim(shell_exec($cmd));
if (empty($disk_usage)) {
	echo "<p>Dysk nie jest zamontowany!</p>";
}
else {
	echo "<p>Użycie dysku wynosi {$disk_usage}.</p>";
}

// utwórz połączenie (dla poprawnego wykonania komend "qdel" i "qrun")
$connection = ssh2_connect('localhost', 22);
ssh2_auth_password($connection, 'user', 'pass');

// usuń zadanie
if (isset($_GET['qdel'])) {
	$cmd = "qdel " . $_GET['qdel'];
	ssh2_exec($connection, $cmd);
	echo "<p>Zadanie {$_GET['qdel']} przekazane do usunięcia.</p>";
}

// uruchom zadanie
if (isset($_GET['qrun'])) {
	$cmd = "qrun " . $_GET['qrun'];
	ssh2_exec($connection, $cmd);
	echo "<p>Zadanie {$_GET['qrun']} przekazane do uruchomienia.</p>";
}

// wykonaj akcje po przesłaniu pliku
if (file_exists($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
	$file_content = file_get_contents($_FILES['file']['tmp_name']);
	// zamień wszystkie znaki w pliku wejściowym na małe
	$file_content = strtolower($file_content);
	$meshes = substr_count($file_content, '&mesh ');
	if (substr_count($file_content, '&head ') > 0 && substr_count($file_content, '&time ') > 0 && $meshes > 0 && substr_count($file_content, '&tail') > 0) {
		$cores = 4;
		$nodes = ceil($meshes / $cores);
		
		// odczytanie tytułu symulacji z pliku wsadowego
		// usuń >1 białych znaków (znaki końca linii, tabulatory, itp.) i zastąp je spacjami (to też biały znak)
		$file_content = preg_replace('/\s+/', ' ', trim($file_content));
		// uporządkuj tekst
		$file_content = str_replace(" =", "=", $file_content);
		$file_content = str_replace(" '", "'", $file_content);
		// znacznik "title" powinien wystąpić tylko raz i go szukam wraz z "='", by wyciągnąć jego wartość
		$find_title = "title='";
		// na razie tytuł będzie pusty
		$title = "";
		if (substr_count($file_content, $find_title) > 0) {
			// znajdź pozycję tytułu
			$pos = strpos($file_content, $find_title) + strlen($find_title);
			$file_lenght = strlen($file_content);
			// znajdź długość tytułu
			$title_length = 0;
			while ($file_content[$pos] != "'" && $pos < $file_lenght) {
					$pos++;
					$title_length++;
			}
			// skopiuj znaleziony tytuł
			$title = substr($file_content, ($pos - $title_length), $title_length);
		}
		
		$file = strtolower($_FILES['file']['name']);
		$file_short = preg_replace('/\.fds$/', '', $file); // bez ".fds" na końcu
		// gdy brakuje tytułu lub jest pusty, wykorzystaj nazwę pliku wejściowego
		if (empty($title)) {
			$title = $file_short;
		}
		
		// zamień "-" na "_" dla ustandaryzowania nazw
		$title = preg_replace("/[-]/", "_", $title);
		// usuń wszystkie znaki poza literami alfabetu łacińskiego, cyframi i "_"
		$title = preg_replace("/[^a-zA-Z0-9_]+/", "", $title);
		// skróć ciąg do 24 znaków
		$title = substr($title, 0, 24);
		
		$root = "/mnt/fds/";
		// domyślna ścieżka bezwzględna do katalogu
		$directory = $root . date(Y) . DIRECTORY_SEPARATOR . date("mdHis") . "_" . $title . DIRECTORY_SEPARATOR;
		
		// dodaj zadanie do wybranego przez użytkownika katalogu - przesyłana jest ścieżka względna (wraz z katalogiem nadrzędnym)
		// jeżeli zażyczył sobie własny katalog
		if (isset($_POST['custom_directory']) && !empty($_POST['custom_directory'])) {
			$directory = $root . $_POST['custom_directory'] . date("mdHis") . "_" . $title . DIRECTORY_SEPARATOR;
		}
		// jeżeli wybrał katalog z listy
		if (isset($_POST['directory']) && !empty($_POST['directory'])) {
			$directory = $root . $_POST['directory'] . date("mdHis") . "_" . $title . DIRECTORY_SEPARATOR;
		}
		$PBS = "#!/bin/bash" . PHP_EOL
		. "#PBS -N {$title}" . PHP_EOL
		. "#PBS -e {$directory}{$file_short}.err" . PHP_EOL
		. "#PBS -o {$directory}{$file_short}.log" . PHP_EOL
		. "#PBS -l nodes={$nodes}:ppn={$cores}" . PHP_EOL
		. "#PBS -l walltime=999:0:0" . PHP_EOL
		. "#export OMP_NUM_THREADS=1" . PHP_EOL
		. "cd {$directory}" . PHP_EOL
		. "mpiexec -np {$meshes} PATH_TO_FDS/FDS/FDS6/bin/fds {$directory}{$file}" . PHP_EOL;
		
		// utwórz katalog, zapisz do niego plik FDS oraz PBS i utwórz zadanie
		if (!file_exists($directory) && mkdir($directory, 0777, true)) {
			if (move_uploaded_file($_FILES['file']['tmp_name'], "{$directory}{$file}")) {
				file_put_contents("{$directory}start.pbs", $PBS);
				$cmd = "qsub {$directory}start.pbs";
				ssh2_exec($connection, $cmd);
				echo "<p>Dodano plik wejściowy {$file}.</p>";
			}
			else {
				echo "<p>Błąd podczas zapisu.</p>";
			}
		}
		else {
			echo "<p>Błąd podczas tworzenia katalogu.</p>";
		}
	}
	else {
		echo "<p>Niepoprawny plik wejściowy FDS.</p>";
	}
}
?>
</div> <!-- notification -->

</div> <!-- main-container -->

<?php
	// wyświetla dowcip na całym okienku
	if(!isset($_COOKIE["overlay"]))
	{
		$jokes = explode("@", file_get_contents("files/jokes.txt"));
		$joke = nl2br($jokes[random_int(0, count($jokes)-1)], false);
		echo "<div id=\"overlay\" onclick=\"overlay_off()\">";
		echo "<div id=\"joke\">{$joke}</div>";
		echo "</div> <!-- overlay -->";
	}
?>

<script>
<!--
// resetuj listę w formularzu
function reset_select() {
	document.getElementById("directory").selectedIndex = "0";
	document.getElementById("custom_directory").value = null;
	document.getElementById("text_catalog").textContent = null;
}

// reakcja na zaznaczenie pozycji na liście rozwijanej
function choose_directory() {
	document.getElementById("custom_directory").value = null;
	var form_select = document.getElementById("directory");
	var parent_catalog = form_select.options[form_select.selectedIndex].parentNode.label;
	document.getElementById("text_catalog").textContent = form_select.value;
	// jeśli kliknięto na "utwórz katalog"
	if (!form_select.value) { // any variable can be evaluated as a boolean
		reset_select();
		var custom_directory = prompt("Wpisz nazwę katalogu.", "");
		if (custom_directory) {
			custom_directory = custom_directory.toLowerCase();
			var reg = /[-]/g;
			custom_directory = custom_directory.replace(reg, "_");
			reg = /[^a-z0-9_]+/g;
			custom_directory = custom_directory.replace(reg, "");
			// skróć ciąg do 24 znaków
			custom_directory = custom_directory.substring(0, 24); // wyciągnij string między pozycjami <0, 24)
			custom_directory = parent_catalog + "/" + custom_directory + "/";
			document.getElementById("custom_directory").value = custom_directory;
			document.getElementById("text_catalog").textContent = custom_directory;
		}
	}
	return false;
}

// zwijanie/rozwijanie
function toggle_visibility(id) {
	var e = document.getElementById(id);
	if (e.style.display === 'block')
		e.style.display = 'none';
	else
		e.style.display = 'block';
	return false;
}

// wyświetlenie wybranego pliku
document.getElementById("file").addEventListener("change", show_filename);

function show_filename(event) {
	document.getElementById("text_filename").textContent = event.srcElement.files[0].name;
	return false;
}

// hakerskie literki w tytule strony
function change_title() {
	var text = "";
	var possible = "abcdef0123456789";
	for (var i = 0; i < 127; i++) {
		text += possible.charAt(Math.floor(Math.random() * possible.length));
	}
	document.title = text;
	setTimeout(change_title, 1000);
}
//-->

function overlay_off() {
  document.getElementById("overlay").style.display = "none";
}
</script>

</body>
</html>
