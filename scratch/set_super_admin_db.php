<?php
require_once __DIR__ . '/../config/db.php';

$stmt = $pdo->prepare("
    UPDATE users 
    SET role = 'admin', active = TRUE 
    WHERE LOWER(username) IN ('matheus.damiao', 'marcuss', 'marcus_aurelio')
       OR LOWER(email) LIKE '%matheus.damiao%'
       OR LOWER(email) LIKE '%marcus_aurelio%'
");
$stmt->execute();
echo "Updated users table for Super Admin accounts! Rows affected: " . $stmt->rowCount() . "\n";

$stmt2 = $pdo->query("SELECT id, username, name, email, role, active FROM users WHERE role = 'admin' OR LOWER(username) IN ('matheus.damiao', 'marcuss')");
$admins = $stmt2->fetchAll(PDO::FETCH_ASSOC);
print_r($admins);
