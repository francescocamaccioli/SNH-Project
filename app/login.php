<?php
require_once 'config.php';
require_once 'csrf.php';

use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

// Create a logger instance
$log = new Logger('user_login');
// Define the log file path
$logFile = __DIR__ . '/logs/novelist-app.log';
// Add a handler to write logs to the specified file
$log->pushHandler(new StreamHandler($logFile, Level::Debug));

ob_start();
session_start();
$inactive = 300; // 5 minutes session timeout

function setErrorMessage($message) {
    $_SESSION['error_message'] = $message;
    $_SESSION['source'] = "LOGIN";
    header('Location: index.php'); // Reindirizza l'utente alla pagina di login
    exit();
}

// Check if session has timed out
if (isset($_SESSION['timeout']) && (time() - $_SESSION['timeout'] > $inactive)) {
    session_unset();
    session_destroy();
  
    setErrorMessage("Session expired. Please log in again.");
    $log->warning('Session expired due to inactivity.', ['session_id' => session_id()]);
    exit();
}

$_SESSION['timeout'] = time(); // Update session timeout

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    $log->warning('Login attempt using unsupported HTTP method.', ['method' => $_SERVER['REQUEST_METHOD'], 'ip' => $_SERVER['REMOTE_ADDR']]);
    exit();
}

if (!isset($_POST['token_csrf']) || !verifyToken($_POST['token_csrf'])) {
    $log->warning('csrf token error', ['ip' => $_SERVER['REMOTE_ADDR']]);
    die("Something went wrong");
}


// Validate required fields
$email = trim($_POST['email'] ?? '');
$password = trim($_POST['password'] ?? '');
$recaptcha_response = $_POST['g-recaptcha-response'] ?? '';

if (empty($email) || empty($password) || empty($recaptcha_response)) {
    setErrorMessage("All fields are required!");
    $log->warning('Login attempt with missing fields.', ['email' => $email, 'ip' => $_SERVER['REMOTE_ADDR']]);
    exit();
}

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    setErrorMessage("Invalid email format!");
    $log->warning('Login attempt with invalid email format.', ['email' => $email, 'ip' => $_SERVER['REMOTE_ADDR']]);
    exit();
}

$email = filter_var($email, FILTER_SANITIZE_EMAIL);

// Verify reCAPTCHA
$recaptcha_secret = $_ENV['RECAPTCHA_V2_SECRETKEY'];
$recaptcha_url = 'https://www.google.com/recaptcha/api/siteverify';

$ch = curl_init($recaptcha_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'secret' => $recaptcha_secret,
    'response' => $recaptcha_response
]);
$recaptcha_verify = curl_exec($ch);
curl_close($ch);

$recaptcha_data = json_decode($recaptcha_verify, true);

if (!$recaptcha_data || !$recaptcha_data['success']) {
    setErrorMessage("reCAPTCHA verification failed! Please try again.");
    $log->warning('reCAPTCHA verification failed.', ['username' => $username, 'ip' => $_SERVER['REMOTE_ADDR']]);
    exit();
}

// Prepare SQL query
$stmt = $conn->prepare('SELECT id, username, password_hash, is_premium, role, is_verified, trials, unlocking_date, password_changed_at FROM Users WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $stmt->bind_result($user_id, $db_username, $password_hash, $is_premium, $role, $is_verified, $trials, $unlocking_date,$password_changed_at);
    $stmt->fetch();

    if (($trials % 3) === 0 && $unlocking_date!== NULL) {
        $current_date = new DateTime();
        $unlock_date = new DateTime($unlocking_date);
        
        if ($current_date < $unlock_date) {
            $_SESSION['unlock_date'] = strtotime($unlock_date->format('Y-m-d H:i:s'));
            setErrorMessage("Too many failed attempts. Try again in ");
            exit();
        }
    }

    if (!is_string($password_hash) || empty($password_hash)) {
        $log->error('Invalid password hash retrieved from database.', ['username' => $db_username]);
        die('Authentication error.');
        exit();
    }

    
 
        // $max_password_age_minutes = 1;

        // $password_last_changed = new DateTime($password_changed_at);
        // $current_date = new DateTime();

        // $interval = $current_date->getTimestamp() - $password_last_changed->getTimestamp();
        // $interval_in_minutes = $interval / 60; // Converte i secondi in minuti

        // if ($interval_in_minutes > $max_password_age_minutes) {
        //     $_SESSION['force_password_reset'] = true; // Indica che il reset è obbligatorio
        //     unset($_SESSION['username']);
        //     unset($_SESSION['is_premium']);
        //     unset($_SESSION['role']);
        //     header('Location: force_password_change.php'); // Nuova pagina per il cambio password
        //     exit();
        // }



    if (password_verify($password, $password_hash)) {
        if (!$is_verified) {
            setErrorMessage("Please verify your email address to activate your account.");
            $log->warning('Login attempt with unverified email.', ['username' => $db_username, 'ip' => $_SERVER['REMOTE_ADDR']]);
            exit();
        }
        session_regenerate_id(true); // Prevent session fixation
        newToken();
        // Store user info in session
        $_SESSION['user_id'] = $user_id;
        $_SESSION['username'] = $db_username;
        $_SESSION['is_premium'] = $is_premium;
        $_SESSION['role'] = $role;
            
        $update_stmt = $conn->prepare('UPDATE Users SET trials = 0, unlocking_date = NULL WHERE id = ?');
        $update_stmt->bind_param('i', $user_id);
        $update_stmt->execute();

        $max_password_age_days = 90;

        if ($password_changed_at === NULL) {
            $password_last_changed = new DateTime(); // Use current time as default
        } else {
            $password_last_changed = new DateTime($password_changed_at);
        }
    
        $current_date = new DateTime();
   
        $interval = $current_date->diff($password_last_changed);
        $interval_in_days = $interval->days; // Restituisce la differenza in giorni
    
        if ($interval_in_days > $max_password_age_days) {
            $_SESSION['force_password_reset'] = true; // Indica che il reset è obbligatorio
            unset($_SESSION['username']);
            unset($_SESSION['is_premium']);
            unset($_SESSION['role']);
            header('Location: force_password_change.php'); // Nuova pagina per il cambio password
            exit();
        }
        $log->info('User logged in successfully.', ['username' => $db_username, 'ip' => $_SERVER['REMOTE_ADDR']]);
        header('Location: home.php');
        exit();
    } else {
        $trials ++;
        if(($trials % 3)==0){
            $log->info('Login Locking due to retry limit reach.', ['username' => $db_username, 'ip' => $_SERVER['REMOTE_ADDR']]);
            $lock_duration = min(5 * pow(2, $trials), 86400);
            $new_unlocking_date = (new DateTime())->modify("+$lock_duration seconds")->format('Y-m-d H:i:s');

            $update_stmt = $conn->prepare('UPDATE Users SET trials = ?, unlocking_date = ? WHERE id = ?');
            $update_stmt->bind_param('isi', $trials, $new_unlocking_date,$user_id);
            $update_stmt->execute();
        }else{
            $log->info('Increasing login retry counter.', ['username' => $db_username, 'ip' => $_SERVER['REMOTE_ADDR']]);
            $update_stmt = $conn->prepare('UPDATE Users SET trials = ?, unlocking_date = NULL WHERE id = ?');
            $update_stmt->bind_param('ii', $trials ,$user_id);
            $update_stmt->execute();
        }
        setErrorMessage("Invalid email or password!");
        $log->warning('Failed login attempt due to incorrect password.', ['username' => $db_username, 'ip' => $_SERVER['REMOTE_ADDR']]);
        exit();
    }
    
} else {
    setErrorMessage("Invalid username or password!");
    $log->warning('Failed login attempt with non-existent username.', ['username' => $db_username, 'ip' => $_SERVER['REMOTE_ADDR']]);
    exit();
}

ob_end_flush();
$stmt->close();
$conn->close();
?>
