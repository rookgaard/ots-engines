<?php

/* add line below to cron:

* * * * * root php /path/to/movies/cams.php > /dev/null 2>&1

*/

class MinimalFtp
{
	private $connection;

	public function isDir($directory)
	{
		$pwd = ftp_pwd($this->connection);

		if ($pwd === false) {
			return false;
		}

		$isDir = @ftp_chdir($this->connection, $directory);
		ftp_chdir($this->connection, $pwd);

		return $isDir;
	}

	public function mkdir($directory)
	{
		$pwd = ftp_pwd($this->connection);
		$parts = explode('/', trim($directory, '/'));

		foreach ($parts as $part) {
			if (!@ftp_chdir($this->connection, $part)) {
				if (!ftp_mkdir($this->connection, $part)) {
					ftp_chdir($this->connection, $pwd);

					return false;
				}

				ftp_chdir($this->connection, $part);
			}
		}

		ftp_chdir($this->connection, $pwd);

		return true;
	}

	public function send(string $host, string $username, string $password, string $directory, string $filename, string $hash)
	{
		$this->connection = ftp_connect($host);

		if (!$this->connection) {
			return false;
		}

		$success = ftp_login($this->connection, $username, $password)
			&& ftp_pasv($this->connection, true)
			&& $this->mkdir($directory)
			&& ftp_put(
				$this->connection,
				$directory . '/' . $filename . '_' . substr($hash, 0, 16),
				__DIR__ . '/' . $filename
			);

		if ($this->connection) {
			ftp_close($this->connection);
		}

		return $success;
	}
}

class Cam
{
	const LINE_ENDING = "<hr/>\n";
	const MAX_CAMS_PER_RUN = 50;
	const MIN_FILE_AGE_SECONDS = 20;
	private $mysqlConnection;

	public function execute($config)
	{
		$start = microtime(true);
		$cams = $this->collectCamFiles();

		echo 'cams: ' . count($cams) . self::LINE_ENDING;

		if (!count($cams)) {
			return;
		}

		$this->mysqlConnection = createMysqliConnection($config);
		$this->ensureCamsTableExists();

		foreach ($cams as $cam) {
			@ob_flush();
			echo 'File ' . $cam . ': ';
			$fullPath = __DIR__ . '/' . $cam;
			$movie_size = filesize($fullPath);

			if ($movie_size == 0) {
				unlink($fullPath);
				echo 'empty file' . self::LINE_ENDING;
				continue;
			}

			$data = $this->get_data($fullPath);
			$sum = $this->get_video_length($data);

			if ($sum <= 0) {
				unlink($fullPath);
				echo '0 seconds' . self::LINE_ENDING;
				continue;
			}

			$hex_sum = substr('00000000' . dechex($sum), -8);
			$data .= chr(hexdec('63')) . chr(hexdec($hex_sum[6] . $hex_sum[7])) . chr(hexdec($hex_sum[4] . $hex_sum[5]))
				. chr(hexdec($hex_sum[2] . $hex_sum[3])) . chr(hexdec($hex_sum[0] . $hex_sum[1]));
			list($player_id, $timestamp) = explode('_', $cam);
			$hash = rand(0, 9) . base_convert(md5($player_id . $timestamp), 16, 36);
			$directory = date('Ym', $timestamp / 1000);
			$this->compress($data, $fullPath);

			if (!(new MinimalFtp())->send(
				$config['ftpHost'],
				$config['ftpUser'],
				$config['ftpPassword'],
				$directory . '/' . $player_id,
				$cam,
				$hash
			)) {
				echo 'ftp put failed' . self::LINE_ENDING;
				continue;
			}

			$player_data = $this->select('SELECT id AS player_id, account_id, name FROM players WHERE id = ' . $player_id);

			if (empty($player_data)) {
				unlink($fullPath);
				echo 'unknown player' . self::LINE_ENDING;
				continue;
			}

			$player_data = $player_data[0];
			$params = [
				'account_id' => $player_data['account_id'],
				'player_id' => $player_data['player_id'],
				'player_name' => $player_data['name'],
				'hash' => $hash,
				'directory' => $directory,
				'filename' => $cam,
				'duration' => $sum,
				'started' => date('Y-m-d H:i:s', substr($timestamp, 0, 10)),
				'ended' => date('Y-m-d H:i:s', substr($timestamp + $sum, 0, 10)),
				'parsed' => date('Y-m-d H:i:s'),
			];

			$insertQuery = 'INSERT IGNORE INTO cams (' . implode(', ', array_keys($params)) . ') VALUES ("' . implode('", "', array_values($params)) . '")';
			$this->select($insertQuery);
			$checkQuery = 'SELECT * FROM cams WHERE hash = "' . $hash . '"';

			if (!$this->select($checkQuery)) {
				echo 'failed to add cam data, query: ' . $insertQuery . self::LINE_ENDING;
				continue;
			}

			if (is_file($fullPath)) {
				unlink($fullPath);
			}

			echo 'OK' . self::LINE_ENDING;
		}

		$this->mysqlConnection->close();
		echo 'cams: ' . count($cams) . ', time: ' . round(microtime(true) - $start, 3) . self::LINE_ENDING;
	}

	private function collectCamFiles(): array
	{
		$files = scandir(__DIR__);
		$cams = [];

		foreach ($files as $file) {
			if ($this->shouldSkipFile($file)) {
				continue;
			}

			$cams[] = $file;

			if (count($cams) >= self::MAX_CAMS_PER_RUN) {
				break;
			}
		}

		return $cams;
	}

	private function shouldSkipFile(string $file): bool
	{
		if ($file === '' || $file[0] === '.') {
			return true;
		}

		$fullPath = __DIR__ . '/' . $file;

		if (!is_file($fullPath)) {
			return true;
		}

		if (strpos($file, '_') === false) {
			return true;
		}

		if (time() - filemtime($fullPath) < self::MIN_FILE_AGE_SECONDS) {
			return true;
		}

		return false;
	}

	private function compress($data, $filename)
	{
		$fp = fopen($filename, 'w');
		fwrite($fp, gzencode($data, 7));
		fclose($fp);
	}

	private function get_data($filename)
	{
		$data = file_get_contents($filename);
		$firstHex = substr('0' . dechex(ord($data[0])), -2);

		if ($firstHex == '1f') {
			return gzdecode($data);
		}

		return $data;
	}

	private function get_video_length($data)
	{
		$movie_size = strlen($data);
		$end = false;
		$offset = 0;
		$sum = 0;

		while (!$end) {
			$hex = substr('0' . dechex(ord($data[$offset])), -2);
			if ($hex == '63') {
				$offset += 5;
			} elseif ($hex == '64') {
				$offset += 3;
			} elseif ($hex == '65') {
				$delay = ord($data[$offset + 1]) + ord($data[$offset + 2]) * 256;
				$sum += $delay;
				$offset += 3;
			} elseif ($hex == '66' || $hex == '68') {
				$plen = ord($data[$offset + 1]) + ord($data[$offset + 2]) * 256;
				$offset += 3 + $plen;
			} elseif ($hex == '69') {
				$offset += 5;
			} else {
				$end = true;
			}

			if ($offset >= $movie_size) {
				$end = true;
			}
		}

		return $sum;
	}

	private function select(string $query)
	{
		mysqli_query($this->mysqlConnection, 'SET names utf8');
		$results = mysqli_query($this->mysqlConnection, $query);

		if (!is_object($results)) {
			return $results;
		}

		if (mysqli_errno($this->mysqlConnection)) {
			echo mysqli_error($this->mysqlConnection);
		}

		$return = [];

		while ($row = mysqli_fetch_assoc($results)) {
			$return[] = $row;
		}

		return $return;
	}

	private function ensureCamsTableExists(): void
	{
		$schemaSql = file_get_contents(__DIR__ . '/schema.sql');

		if ($schemaSql === false) {
			throw new RuntimeException('Missing cams schema');
		} elseif (!$this->mysqlConnection->query($schemaSql)) {
			throw new RuntimeException('Failed to create cams table: ' . $this->mysqlConnection->error);
		}
	}
}

function createMysqliConnection(array $config): mysqli
{
	$mysqli = new mysqli(
		$config['sqlHost'],
		$config['sqlUser'],
		$config['sqlPassword'],
		$config['sqlDatabase']
	);

	if ($mysqli->connect_error) {
		throw new RuntimeException('MySQL Error: ' . $mysqli->connect_error);
	}

	$mysqli->set_charset('utf8mb4');

	return $mysqli;
}

if (!is_file(__DIR__ . '/config.php')) {
	copy(__DIR__ . '/config.php.example', __DIR__ . '/config.php');
}

include __DIR__ . '/config.php';

if (!isset($config) || !isset($config['sqlHost']) || !isset($config['ftpHost'])) {
	throw new RuntimeException('incomplete config.php file, compare keys with config.php.example');
}

if (PHP_SAPI === 'cli') {
	(new Cam())->execute($config);
	exit;
}

$mysqli = createMysqliConnection($config);

if (isset($_GET['visible'])) {
	$hash = $mysqli->real_escape_string($_GET['visible']);
	$mysqli->query('UPDATE cams SET visible = NOT visible WHERE hash = "' . $hash . '"');
	exit;
}

$sql = 'SELECT player_id, player_name, duration, hash, directory, filename, started, ended, visible FROM cams ';

if (isset($_GET['param'])) {
	$param = trim($_GET['param']);
	$safeParam = $mysqli->real_escape_string($param);
	$timestamp = strtotime($param);

	if (strlen($param) === 10 && $timestamp !== false) {
		$sql .= 'WHERE DATE(started) = "' . $safeParam . '" OR DATE(ended) = "' . $safeParam . '"';
	} elseif ($timestamp !== false) {
		$from = date('Y-m-d H:i:s', $timestamp + 120);
		$to = date('Y-m-d H:i:s', $timestamp - 120);
		$sql .= 'WHERE started < "' . $from . '" AND ended > "' . $to . '"';
	} elseif ($param === 'access') {
		$sql .= 'LEFT JOIN players ON players.id = cams.player_id WHERE group_id > 1';
	} else {
		$sql .= 'WHERE player_name LIKE "%' . $safeParam . '%"';
	}
} else {
	$sql .= 'WHERE started > "' . date('Y-m-d H:i:s', time() - 3 * 86400) . '"';
}

$sql .= ' ORDER BY started DESC';
$results = $mysqli->query($sql);
$entries = [];

if ($results instanceof mysqli_result) {
	while ($row = $results->fetch_assoc()) {
		$entries[] = $row;
	}
}
?>
<!DOCTYPE html>
<html>

<head>
	<meta charset="utf-8">
	<title>Cams</title>
	<style>
		table {
			border-collapse: collapse;
			width: 100%;
		}

		td,
		th {
			border: 1px solid #ccc;
			padding: 8px;
			text-align: left;
		}

		.toggle-visible {
			cursor: pointer;
			text-align: center;
		}

		.toggle-visible.active {
			background: darkgreen;
			color: #fff;
		}
	</style>
</head>

<body>
	<h2>Cams</h2>
	<fieldset>
		<legend>Legend:</legend>
		<p>Changing the visibility to <span style="background-color: darkgreen; color: #fff; padding: 0 4px;">Yes</span> makes the recording visible in the character list of an admin account.</p>
		<p>By default, the last 5 recordings from player account are added to the character list in client.</p>
		<p>Clicking on a name in the "Name" column shows all recordings of that character.</p>
		<p>Clicking on a date in the "Start" column shows all recordings that started/ended on that day.</p>
		<p>Clicking on a date in the "End" column shows all recordings that started 2 minutes before and ended 2 minutes after the given value.</p>
		<p>You can share and watch the recording using just "111" as login and value from "Password" column as password in client, regardless of the status you set.</p>
	</fieldset>
	<p>Query: <?= $sql; ?></p>
	<table>
		<tr class="head">
			<th>Name</th>
			<th>Password</th>
			<th>Duration</th>
			<th>Started</th>
			<th>Ended</th>
			<th>Visible</th>
		</tr>
		<?php foreach ($entries as $entry): ?>
			<tr>
				<td>
					<a href="?param=<?= htmlspecialchars($entry['player_name'], ENT_QUOTES); ?>">
						<?= htmlspecialchars($entry['player_name'], ENT_QUOTES); ?>
					</a>
				</td>
				<td><?= substr($entry['hash'], 0, 16); ?></td>
				<td>
					<?= gmdate("G\\h i\\m s\\s", floor($entry['duration'] / 1000)); ?>
				</td>
				<td>
					<a href="?param=<?= substr($entry['started'], 0, 10); ?>" data-timestamp="<?= strtotime($entry['started']); ?>">
						<?= htmlspecialchars($entry['started'], ENT_QUOTES); ?>
					</a>
				</td>
				<td>
					<a href="?param=<?= $entry['ended']; ?>" data-timestamp="<?= strtotime($entry['ended']); ?>">
						<?= htmlspecialchars($entry['ended'], ENT_QUOTES); ?>
					</a>
				</td>
				<td data-hash="<?= $entry['hash']; ?>" class="toggle-visible <?= $entry['visible'] ? 'active' : ''; ?>"><?= $entry['visible'] ? 'Yes' : 'No'; ?></td>
			</tr>
		<?php endforeach; ?>
	</table>
	<script>
		document.querySelectorAll('.toggle-visible').forEach(function(cell) {
			cell.addEventListener('click', function(event) {
				var targetCell = event.currentTarget;
				var hash = targetCell.getAttribute('data-hash');

				fetch('?visible=' + encodeURIComponent(hash))
					.then(function() {
						targetCell.classList.toggle('active');
						targetCell.textContent = targetCell.classList.contains('active') ? 'Yes' : 'No';
					});
			});
		});
	</script>
</body>

</html>
