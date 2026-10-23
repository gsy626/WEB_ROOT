<?php
require_once __DIR__ . '/compat.php';
require_once __DIR__ . '/db.php';
if(session_status()==PHP_SESSION_NONE)
{
    session_start();
}
if(empty($_SESSION['token']))
{
    $_SESSION['token'] = bin2hex(random_bytes(32));
}
function verify_token()
{   //handle token;
    if( $_SESSION['token'] === '' || !hash_equals($_SESSION['token'] , $_POST['token'] ?? '') )
    {
        die("invalid visitor");
    }
}
function current_user()
{
    global $db;
    if (empty($_SESSION['id'])) return NULL;
    $pre = $db -> prepare("SELECT * FROM users WHERE id= :id");
    $pre -> bindValue(':id',(int)$_SESSION['id'],SQLITE3_INTEGER);
    $src = $pre -> execute();
    return $src ? $src -> fetchArray(SQLITE3_ASSOC) : NULL;
}
function require_login()
{
    $user=current_user();
    if(!$user)
    {
        header("Location:login.php");
        exit;
    }
    return $user;
}
function require_level($min_level)
{
    $user=require_login();
    if((int)$user['level'] < $min_level) die("无权限");
    return $user;
}
function db_execute($db, $sql, $params = [])
{
    $pre = $db->prepare($sql);
    if (!$pre) return false;
    foreach ($params as $key => $value)
    {
        $pre->bindValue($key, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }
    return (bool)$pre->execute();
}
function db_query($db,$sql,$params=[])
{
    $pre = $db->prepare($sql);
    if (!$pre) return [];
    foreach ($params as $key => $value)
    {
        $pre->bindValue($key, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }
    $src = $pre->execute();
    if (!$src) return [];
    $results = [];
    while ($row = $src->fetchArray(SQLITE3_ASSOC))
    {
        $results[] = $row;
    }
    return $results;
}
$self = $_SERVER['SCRIPT_NAME'];

