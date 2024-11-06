<?php

$hostname = 'localhost';
$username = 'developa';
$password = '';
$database = 'english_adventist_hymnal';

$connection = new mysqli($hostname, $username, $password, $database);

if($connection->connect_error) {
    echo 'There was an error';
}

main();

function main() {

    global $connection, $authorArray, $topicArray, $songArray;
    // // remove before executing
    dropTables($connection);
    createTables($connection);
    
    for ($i = 1; $i <= 695; $i++) {
        $number = ($i < 10 ? "00" : ($i < 100 ? "0" : "")) . $i;
        $songFileName = 'hymnal/' . $number . '.txt';
        try {
            $songFile = fopen($songFileName, 'r');
            $songDetail = fread($songFile, filesize($songFileName));
            insertSong($i, $songDetail, $connection);
        } catch (\Throwable $th) {
            echo "Error: there has been an error\n";
        }
    }

    createXML($connection);
}

function insertSong($id, $songDetail, $connection) {
    $number = $id;
    $lines = explode(PHP_EOL, $songDetail);
    $title = $lines[0];
    array_shift($lines);

    $details = implode(PHP_EOL, $lines);

    $details = str_replace("\t", "", $details);
    $details = str_replace("\r", "", $details);
    $details = str_replace("\n\n", "\n", $details);
    $details = str_replace("\n\n", "\n", $details);
    $details = str_replace("\n\n", "\n", $details);
    $details = str_replace("\n\n", "\n", $details);
    $details = preg_replace('/(\d+\.)\s*/', "$1\n", $details);

    $details = trim($details);

    $stmt = $connection->prepare("INSERT INTO song(id, number, title, details) VALUES(?, ?, ?, ?)");
    $stmt->bind_param("iiss", $id, $number, $title, $details);
    $stmt->execute();

    createParts($id, $details, $connection);
}

function createTables($connection) {
    $sql = "CREATE TABLE song(id INT NOT NULL, number INT, title VARCHAR(255), details LONGTEXT, PRIMARY KEY(id));";
    $connection->query($sql);

    $sql = "CREATE TABLE verse(id INT NOT NULL AUTO_INCREMENT, verse_key VARCHAR(255), verse_text LONGTEXT, song_id INT, PRIMARY KEY(id), FOREIGN KEY (song_id) REFERENCES song(id));";
    $connection->query($sql);
}

function dropTables($connection) {
    $sql = "DROP TABLE verse;";
    $connection->query($sql);
    $sql = "DROP TABLE song;";
    $connection->query($sql);
}

function createParts($song_id, $details, $connection) {
    $chorusHead = ["Refrain"];

    $pattern = '/\d+|Refrain/';
    $patternMatches = [];
    $patternMatchesNumber = preg_match_all($pattern, $details, $patternMatches);
    $matches = preg_split($pattern, $details, -1, PREG_SPLIT_NO_EMPTY);

    for ($i = 0; $i < count($patternMatches[0]); $i++) {
        $verseKey = $patternMatches[0][$i];
        if(in_array($verseKey, $chorusHead)) {
            $verseKey = "c1";
        } else if ($verseKey == "Bridge") {
            $verseKey = "o1";
        } else {
            $verseKey = str_replace('.', '', $verseKey);
            $verseKey = "v" . $verseKey;
        }

        if($matches) {
            $verseText = $matches[$i];
        } else {
            $verseText = $details;
        }
        $stmt = $connection->prepare("INSERT INTO verse(verse_key, verse_text, song_id) VALUES(?, ?, ?)");
        $stmt->bind_param('ssi', $verseKey, $verseText, $song_id);
        $stmt->execute();
    }

    if(!$patternMatches[0]) {
        $verseKey = "v1";
        $stmt = $connection->prepare("INSERT INTO verse(verse_key, verse_text, song_id) VALUES(?, ?, ?)");
        $stmt->bind_param('ssi', $verseKey, $details, $song_id);
        $stmt->execute();
    }
}

function createXML($connection) {
    $songsSql = "SELECT * FROM song";
    $songResults = $connection->query($songsSql);

    while($row = $songResults->fetch_assoc()) {

        foreach ($row as $key => $value) {
            $$key = $value;
        }

        $author = "UNKNOWN";
        $topic = "UNKNOWN";

        $versesSql = "SELECT * FROM verse WHERE song_id = " . $id;
        $versesResults = $connection->query($versesSql);
        $verses = [];

        while($verse = $versesResults->fetch_assoc()) {
            $verses[] = $verse;
        }

        $verseKeys = array_map(function ($verse) {
            return $verse['verse_key'];
        }, $verses);

        $verseOrder = orderVerses($verseKeys);

        $docD = new DOMDocument('1.0', 'UTF-8');
        $docD->formatOutput = true;

        $songD = $docD->createElementNS('http://openlyrics.info/namespace/2009/song', 'song');
        $songD->setAttribute('version', '0.8');
        $songD->setAttribute('createdIn', 'OpenLP 3.0.2');
        $songD->setAttribute('modifiedIn', 'OpenLP 3.0.2');
        $songD->setAttribute('modifiedDate', date('Y-m-d\TH:i:s'));

        $propertiesD = $docD->createElement('properties');
        $titlesD = $docD->createElement('titles');
        $titleD = $docD->createElement('title', htmlspecialchars($title));
        $titlesD->appendChild($titleD);
        $propertiesD->appendChild($titlesD);
        
        $propertiesD->appendChild($docD->createElement('copyright', htmlspecialchars('2024')));
        $propertiesD->appendChild($docD->createElement('verseOrder', htmlspecialchars($verseOrder)));
        $propertiesD->appendChild($docD->createElement('ccliNo', htmlspecialchars($number)));

        $authorsD = $docD->createElement('authors');
        $authorD = $docD->createElement('author', htmlspecialchars($author));
        $authorsD->appendChild($authorD);
        $propertiesD->appendChild($authorsD);

        $songbooksD = $docD->createElement('songbooks');
        $songbookD = $docD->createElement('songbook');
        $songbookD->setAttribute('name', htmlspecialchars('Fihirana Advantista'));
        $songbooksD->appendChild($songbookD);
        $propertiesD->appendchild($songbooksD);

        $themesD = $docD->createElement('themes');
        $themeD = $docD->createElement('theme', htmlspecialchars($topic));
        $themesD->appendChild($themeD);
        $propertiesD->appendChild($themesD);

        $songD->appendChild($propertiesD);

        $lyricsD = $docD->createElement('lyrics');
        foreach ($verses as $verse) {
            $verseD = $docD->createElement('verse');
            $verseD->setAttribute('name', htmlspecialchars($verse['verse_key']));
            $text = trim($verse['verse_text']);
            $text = str_replace("\n", '<br/>', $text);

            $linesD = $docD->createElement('lines', htmlspecialchars($text));
            $verseD->appendChild($linesD);
            $lyricsD->appendChild($verseD);
        }

        $songD->appendChild($lyricsD);
        $docD->appendChild($songD);

        createXMLSong($number, $docD->saveXML());
    }
}

function orderVerses($verseArray) {
        
    $verseOrder = "";
    $hasChorus = false;

    if (in_array('c1', $verseArray)) {
        $hasChorus = true;
    }

    if ($verseArray[0] == 'c1') {
        $verseOrder = 'c1 ';
    }

    foreach ($verseArray as $verse) {
        if($verse === 'c1') {
            continue;
        }

        $verseOrder .= $verse . " ";

        if(substr($verse, 0, 1) === 'v' && $hasChorus) {
            $verseOrder .= "c1 ";
        }
    }

    $verseOrder = trim($verseOrder);

    return $verseOrder;
}

function createXMLSong($number, $xml) {
    $directory = 'hymnal-xml';
    if(!file_exists($directory)) {
        mkdir($directory, 0777, true);
    }
    $filepath = $directory . '/' . $number . '.xml';
    $file = fopen($filepath, 'w');
    fwrite($file, $xml);
    fclose($file);
}

$connection->close();

echo "\nSuccess\n";

?>
