<?php
require_once __DIR__ ."/auth.php";
function has_column($db,$table,$column)
{
    $info = $db -> query("PRAGMA table_info($table)");
    $has = false;
    while($row = $info->fetchArray(SQLITE3_ASSOC))
    {
        if($row['name'] == $column)
        {
            $has = true;
            break;
        }
    }
    return $has;
}
function add_column($db,$table,$newcolumn,$data_type,$default)
{
    if(!has_column($db,$table,$newcolumn))
    {
        $db -> exec("ALTER TABLE " .$table  . " ADD COLUMN " .$newcolumn . " " . $data_type . " DEFAULT ". $default);
    }
}
function del_column($db,$table,$oldcolumn)
{
    if(has_column($db,$table,$oldcolumn))
    {
        $db -> exec("ALTER TABLE " . $table ." DROP COLUMN " . $oldcolumn);
    }
}
$db = new SQLite3(__DIR__ . "/../data/user.db");
$db -> busyTimeout(5000);
$db -> exec("PRAGMA foreign_keys = ON");
$db -> exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,
    level INTEGER NOT NULL DEFAULT 1
)");
$db -> exec("CREATE TABLE IF NOT EXISTS articles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    content TEXT NOT NULL,
    create_t TEXT DEFAULT (datetime('now','localtime')),
    update_t TEXT DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
)");

?>

