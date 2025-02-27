<?php
session_start();
require_once 'config.php';
require_once 'csrf.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

// Create a logger instance
$log = new Logger('admin_panel');
// Define the log file path
$logFile = __DIR__ . '/logs/novelist-app.log';
// Add a handler to write logs to the specified file
$log->pushHandler(new StreamHandler($logFile, Level::Debug));

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    $log->critical('Unauthorized access attempt to admin panel.', ['ip' => $_SERVER['REMOTE_ADDR']]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
    if (!isset($_POST['token_csrf']) || !verifyToken($_POST['token_csrf'])) {
        die("Something went wrong"); 
    }
    
    $user_id = intval($_POST['user_id']);
    $new_status = isset($_POST['is_premium']) ? 1 : 0;

    $stmt = $conn->prepare("SELECT id FROM Users WHERE id = ? AND role = 'user'");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 0) {
        $log->warning('Unauthorized action.', ['user_id' => $user_id]);
        die('Unauthorized action.');
        exit();
    }

    $stmt->close();


    $stmt = $conn->prepare('UPDATE Users SET is_premium = ? WHERE id = ?');
    $stmt->bind_param('ii', $new_status, $user_id);

    if ($stmt->execute()) {
        $log->info('User privilege updated successfully.', ['user_id' => $user_id, 'is_premium' => $new_status]);
        $_SESSION['success_message'] = 'User privilege updated successfully!';
    } else {
        $log->error('Failed to update user privilege.', ['user_id' => $user_id]);
        $_SESSION['error_message'] = 'Failed to update user privilege!';
    }

    $stmt->close();
    header('Location: admin.php');
    exit();
}

$stmt = $conn->prepare("SELECT id, username, email, is_premium FROM Users WHERE role = 'user'");
$stmt->execute();
$result = $stmt->get_result();
$users = [];

while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
</head>
<body class="grey lighten-4">

    <nav class="blue">
        <div class="nav-wrapper">
            <a href="#" class="brand-logo center">Admin Panel</a>
            <ul id="nav-mobile" class="right">
                <li><a href="dashboard.php"><i class="material-icons left">arrow_back</i>Back</a></li>
            </ul>
        </div>
    </nav>

    <div class="container">
        <div class="section">
            <h3 class="center">User Management</h3>
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="card-panel green lighten-4"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="card-panel red lighten-4"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
            <?php endif; ?>

            <ul class="collection">
                <?php foreach ($users as $user): ?>
                    <li class="collection-item">
                        <span class="title"><strong><?php echo htmlspecialchars($user['username']); ?></strong></span>
                        <p>Email: <?php echo htmlspecialchars($user['email']); ?><br>
                           Premium Status: <?php echo $user['is_premium'] ? 'Yes' : 'No'; ?>
                        </p>
                        <form action="admin.php" method="POST">
                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                            <input type="hidden" name="token_csrf" value= "<?php echo getToken();?>">
                            <label>
                                <input type="checkbox" name="is_premium" value="1" <?php echo $user['is_premium'] ? 'checked' : ''; ?> onchange="this.form.submit()">
                                <span>Make Premium</span>
                            </label>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <footer class="page-footer blue">
        <div class="container">
            <p class="center white-text">&copy; 2025 Novelists</p>
        </div>
    </footer>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
</body>
</html>
