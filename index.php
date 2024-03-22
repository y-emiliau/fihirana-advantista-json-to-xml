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
}

function createTables($connection) {
    $sql = "CREATE TABLE author(id INT NOT NULL, name VARCHAR(255), PRIMARY KEY(id));";
    $connection->query($sql);

    $sql = "CREATE TABLE topic(id INT NOT NULL, name VARCHAR(255), PRIMARY KEY(id));";
    $connection->query($sql);

    $sql = "CREATE TABLE song(id INT NOT NULL, number INT, title VARCHAR(255), author_id INT, tonality VARCHAR(255), topic_id INT, details LONGTEXT, favorite TINYINT NOT NULL DEFAULT 0, PRIMARY KEY(id), FOREIGN KEY (author_id) REFERENCES author(id), FOREIGN KEY (topic_id) REFERENCES topic(id));";
    $connection->query($sql);
}

function createParts($connection) {
    $partSeparators = ["/\d+\./", "Réf", "ISAN'ANDININY", "FEON'OLON-TOKANA"];
}

$connection->close();
?>