<?php
session_start();
require_once 'db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $message = "Все поля обязательны.";
    } elseif (strlen($username) < 3 || strlen($username) > 50) {
        $message = "Имя пользователя должно быть от 3 до 50 символов.";
    } elseif (strlen($password) < 6) {
        $message = "Пароль должен быть не менее 6 символов.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $message = "Пользователь с таким именем уже существует.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO users (username, password_hash, rating) VALUES (?, ?, 0)")
                ->execute([$username, $hash]);
            header("Location: login.php");
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Регистрация</title>
    <style>
        body::before {
            content: "";
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background-color: #e0e7ff;
            background-image:
                linear-gradient(45deg, #d0d8ff 25%, transparent 25%),
                linear-gradient(-45deg, #d0d8ff 25%, transparent 25%),
                linear-gradient(45deg, transparent 75%, #d0d8ff 75%),
                linear-gradient(-45deg, transparent 75%, #d0d8ff 75%);
            background-size: 100px 100px;
            background-position: 0 0, 0 50px, 50px -50px, -50px 0;
            z-index: -1;
            opacity: 0.6;
        }
        body { font-family: Arial, sans-serif; background: #f0f2ff; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .form { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 300px; }
        input { width: 100%; padding: 10px; margin: 8px 0; border: 1px solid #ccc; border-radius: 6px; }
        button{width: 100%; padding: 10px; background: #816bff; color: white; border: none; border-radius: 6px; cursor: pointer; }
        button:hover { background: #6a55e0; }
        .error { color: red; font-size: 0.9em; margin-top: 5px; }
        a { display: block; text-align: center; margin-top: 15px; color: #816bff; text-decoration: none; }
    </style>
</head>
<body>
    <div class="form">
        <h2>Регистрация</h2>
        <?php if ($message): ?>
            <div class="error"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="text" name="username" placeholder="Имя пользователя" required>
            <input type="password" name="password" placeholder="Пароль" required>
            <button type="submit">Зарегистрироваться</button>
        </form>
        <a href="login.php">Уже есть аккаунт? Войти</a>
    </div>
</body>
</html>