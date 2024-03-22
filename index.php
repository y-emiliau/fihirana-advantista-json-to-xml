<?php

$hostname = 'localhost';
$username = 'root';
$password = '';
$database = 'malagasy_adventist_hymnal';

$connection = new mysqli($hostname, $username, $password, $database);

if($connection->connect_error) {
    echo 'There was an error';
}

$authorsFileName = 'fihirana-json/auteur.json';
$authorsFile = fopen($authorsFileName, 'r');
$authorsJson = fread($authorsFile, filesize($authorsFileName));
$authorArray = json_decode($authorsJson)->auteurs;

$topicsFileName = 'fihirana-json/theme.json';
$topicsFile = fopen($topicsFileName, 'r');
$topicsJson = fread($topicsFile, filesize($topicsFileName));
$topicArray = json_decode($topicsJson)->themes;

$songsFileName = 'fihirana-json/hira.json';
$songsFile = fopen($songsFileName, 'r');
$songsJson = fread($songsFile, filesize($songsFileName));
$songArray = json_decode($songsJson)->hiras;

main();

function main() {

    global $connection, $authorArray, $topicArray, $songArray;
    // remove before executing
    dropTables($connection);
    createTables($connection);

    foreach($authorArray as $author) {
        insertAuthor($author, $connection);
    }
    
    foreach($topicArray as $topic) {
        insertTopic($topic, $connection);
    }
    
    foreach($songArray as $song) {
        insertSong($song, $connection);
    }

    createXML($connection);
}

function insertAuthor($author, $connection) {
    $id = $author->id_auteur;
    $name = $author->nom_auteur;
    $stmt = $connection->prepare("INSERT INTO author(id, name) VALUES(?, ?)");
    $stmt->bind_param("is", $id, $name);
    $stmt->execute();
}

function insertTopic($topic, $connection) {
    $id = $topic->id_theme;
    $name = $topic->nom_theme;
    $stmt = $connection->prepare("INSERT INTO topic(id, name) VALUES(?, ?)");
    $stmt->bind_param("is", $id, $name);
    $stmt->execute();
}

function insertSong($song, $connection) {
    $id = $song->id;
    $number = $song->numero;
    $title = $song->titre;
    $author_id = $song->auteur_id;
    $tonality = $song->tonalite;
    $topic_id = $song->theme_id;
    $details = $song->detaille;
    $favorite = $song->favori;

    $details = str_replace("\t", "", $details);
    $details = str_replace("\r", "", $details);
    $details = str_replace("\n\n", "\n", $details);
    $details = str_replace("\n\n", "\n", $details);
    $details = str_replace("\n\n", "\n", $details);
    $details = str_replace("\n\n", "\n", $details);
    $details = preg_replace('/(\d+\.)\s*/', "$1\n", $details);

    $details = trim($details);

    $stmt = $connection->prepare("INSERT INTO song(id, number, title, author_id, tonality, topic_id, details, favorite) VALUES(?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisisisi", $id, $number, $title, $author_id, $tonality, $topic_id, $details, $favorite);
    $stmt->execute();

    createParts($id, $details, $connection);
}

function createTables($connection) {
    $sql = "CREATE TABLE author(id INT NOT NULL, name VARCHAR(255), PRIMARY KEY(id));";
    $connection->query($sql);

    $sql = "CREATE TABLE topic(id INT NOT NULL, name VARCHAR(255), PRIMARY KEY(id));";
    $connection->query($sql);

    $sql = "CREATE TABLE song(id INT NOT NULL, number INT, title VARCHAR(255), author_id INT, tonality VARCHAR(255), topic_id INT, details LONGTEXT, favorite TINYINT NOT NULL DEFAULT 0, PRIMARY KEY(id), FOREIGN KEY (author_id) REFERENCES author(id), FOREIGN KEY (topic_id) REFERENCES topic(id));";
    $connection->query($sql);

    $sql = "CREATE TABLE verse(id INT NOT NULL AUTO_INCREMENT, verse_key VARCHAR(255), verse_text LONGTEXT, song_id INT, PRIMARY KEY(id), FOREIGN KEY (song_id) REFERENCES song(id));";
    $connection->query($sql);
}

function dropTables($connection) {
    $sql = "DROP TABLE verse;";
    $connection->query($sql);
    $sql = "DROP TABLE song;";
    $connection->query($sql);
    $sql = "DROP TABLE topic;";
    $connection->query($sql);
    $sql = "DROP TABLE author;";
    $connection->query($sql);
}

function createParts($song_id, $details, $connection) {
    $chorusHead = ["Réf", "ISAN'ANDININY", "ISAN'ANDINY"];

    $pattern = '/\d+\.|Réf|ISAN\'ANDININY|FEON\'OLON-TOKANA|ISAN\'ANDINY/';
    $patternMatches = [];
    $patternMatchesNumber = preg_match_all($pattern, $details, $patternMatches);
    $matches = preg_split($pattern, $details, -1, PREG_SPLIT_NO_EMPTY);

    for ($i = 0; $i < count($patternMatches[0]); $i++) {
        $verseKey = $patternMatches[0][$i];
        if(in_array($verseKey, $chorusHead)) {
            $verseKey = "c1";
        } else if ($verseKey == "FEON'OLON-TOKANA") {
            $verseKey = "o1";
        } else {
            $verseKey = str_replace('.', '', $verseKey);
            $verseKey = "v" . $verseKey;
        }
        $stmt = $connection->prepare("INSERT INTO verse(verse_key, verse_text, song_id) VALUES(?, ?, ?)");
        $stmt->bind_param('ssi', $verseKey, $matches[$i], $song_id);
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

        $authorSql = "SELECT * FROM author WHERE id = " . $author_id;
        $authorResults = $connection->query($authorSql);
        $author = $authorResults->fetch_assoc()['name'];
        
        $topicSql = "SELECT * FROM topic WHERE id = " . $topic_id;
        $topicResults = $connection->query($topicSql);
        $topic = $topicResults->fetch_assoc()['name'];

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
        
        $propertiesD->appendChild($docD->createElement('copyright', htmlspecialchars('© 2024')));
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
    $directory = 'fihirana-xml';
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